<?php

declare(strict_types=1);

namespace Rig\Db;

/**
 * Конструктор запросов. Значения всегда уходят параметрами, поэтому SQL-инъекция через них невозможна.
 *
 *   DB::table('todos')
 *       ->where('done', false)
 *       ->where('created_at', '>=', '2026-01-01')
 *       ->whereIn('list_id', [1, 2])
 *       ->orderBy('id', 'desc')
 *       ->limit(20)
 *       ->get();
 *
 * Для модели (Todo::where(…)) результаты приходят объектами модели, иначе массивами.
 */
class Query
{
    private const OPS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'like', 'not like', 'ilike'];

    /** @var list<string|Raw> */
    private array $columns = [];
    /** @var list<array{0:string,1:string,2:array}> [AND|OR, sql, bindings] */
    private array $wheres = [];
    /** @var list<string> */
    private array $orders = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private bool $distinct = false;
    /** Связи для жадной загрузки: имя => условие или null. */
    private array $with = [];

    /** @param class-string<Model>|null $model */
    public function __construct(
        public readonly Connection $db,
        public readonly string $table,
        public readonly ?string $model = null,
    ) {
    }

    public function select(string|Raw ...$columns): static
    {
        $this->columns = $columns;
        return $this;
    }

    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * Загрузить связи заранее, одним запросом на связь, чтобы не было N+1:
     *
     *   Post::with('author', 'comments.author')->get();
     *   Post::with(['comments' => fn (Query $q) => $q->where('hidden', false)->latest()])->get();
     *
     * Связь читается свойством ($post->author) и попадает в toArray() и состояние компонента.
     * Условие действует на весь запрос связи: limit(5) даст 5 комментариев на все посты, а не на каждый.
     */
    public function with(string|array ...$relations): static
    {
        if ($this->model === null) {
            throw new \LogicException('with() работает только с моделями: Post::with(…), а не DB::table(…)');
        }
        foreach ($relations as $r) {
            foreach ((array)$r as $k => $v) {
                if (is_string($k)) {
                    $this->with[$k] = $v;        // 'comments' => fn (Query $q) => …
                } else {
                    $this->with[(string)$v] = null;
                }
            }
        }
        return $this;
    }

    // ---------------------------------------------------------------- условия

    /**
     * where('id', 5) · where('age', '>', 18) · where(['done' => false, 'list_id' => 2])
     * where(fn (Query $q) => $q->where('a', 1)->orWhere('b', 2)) даёт условие в скобках.
     */
    public function where(string|array|\Closure $column, mixed $op = null, mixed $value = null): static
    {
        return $this->addWhere('AND', $column, $op, $value, func_num_args());
    }

    public function orWhere(string|array|\Closure $column, mixed $op = null, mixed $value = null): static
    {
        return $this->addWhere('OR', $column, $op, $value, func_num_args());
    }

    public function whereIn(string $column, array $values, bool $not = false): static
    {
        if ($values === []) {
            $this->wheres[] = ['AND', $not ? '1 = 1' : '1 = 0', []];
            return $this;
        }
        $marks = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = ['AND', $this->db->wrap($column) . ($not ? ' NOT IN ' : ' IN ') . "({$marks})", array_values($values)];
        return $this;
    }

    public function whereNotIn(string $column, array $values): static
    {
        return $this->whereIn($column, $values, true);
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = ['AND', $this->db->wrap($column) . ' IS NULL', []];
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['AND', $this->db->wrap($column) . ' IS NOT NULL', []];
        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to): static
    {
        $this->wheres[] = ['AND', $this->db->wrap($column) . ' BETWEEN ? AND ?', [$from, $to]];
        return $this;
    }

    /** Своё условие с параметрами: whereRaw('lower(email) = ?', [$email]). */
    public function whereRaw(string $sql, array $bindings = [], string $bool = 'AND'): static
    {
        $this->wheres[] = [$bool, $sql, array_values($bindings)];
        return $this;
    }

    // ---------------------------------------------------------------- порядок и границы

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $dir = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        $this->orders[] = $this->db->wrap($column) . ' ' . $dir;
        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function latest(string $column = 'id'): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function oldest(string $column = 'id'): static
    {
        return $this->orderBy($column, 'asc');
    }

    public function limit(int $n): static
    {
        $this->limit = max(0, $n);
        return $this;
    }

    public function take(int $n): static
    {
        return $this->limit($n);
    }

    public function offset(int $n): static
    {
        $this->offset = max(0, $n);
        return $this;
    }

    public function skip(int $n): static
    {
        return $this->offset($n);
    }

    // ---------------------------------------------------------------- чтение

    /** Модели, если запрос построен от модели, иначе массивы. @return list<array|Model> */
    public function get(): array
    {
        [$sql, $b] = $this->toSql();
        $rows = $this->hydrate($this->db->select($sql, $b));
        if ($this->with && $rows) {
            Model::eagerLoad($rows, $this->with);
        }
        return $rows;
    }

    public function first(): array|Model|null
    {
        $rows = (clone $this)->limit(1)->get();
        return $rows[0] ?? null;
    }

    /** Первая строка или RecordNotFound (в ответе на запрос это 404). */
    public function firstOrFail(): array|Model
    {
        return $this->first() ?? throw new RecordNotFound($this->table);
    }

    public function find(int|string $id, string $key = 'id'): array|Model|null
    {
        return (clone $this)->where($key, $id)->first();
    }

    /** Значение одной колонки первой строки. */
    public function value(string $column): mixed
    {
        $q = (clone $this)->select($column)->limit(1);
        [$sql, $b] = $q->toSql();
        $row = $this->db->selectOne($sql, $b);
        return $row === null ? null : reset($row);
    }

    /** Колонка списком; с $key получается массив ключ => значение. */
    public function pluck(string $column, ?string $key = null): array
    {
        $q = (clone $this)->select(...($key === null ? [$column] : [$column, $key]));
        [$sql, $b] = $q->toSql();
        $out = [];
        $col = self::bare($column);
        foreach ($this->db->select($sql, $b) as $row) {
            if ($key === null) {
                $out[] = $row[$col];
            } else {
                $out[$row[self::bare($key)]] = $row[$col];
            }
        }
        return $out;
    }

    public function count(string $column = '*'): int
    {
        return (int)$this->aggregate('COUNT', $column);
    }

    public function exists(): bool
    {
        $q = (clone $this)->select(new Raw('1 AS e'))->limit(1);
        $q->orders = [];
        [$sql, $b] = $q->toSql();
        return $this->db->selectOne($sql, $b) !== null;
    }

    public function sum(string $column): float|int
    {
        $v = $this->aggregate('SUM', $column);
        return is_numeric($v) ? $v + 0 : 0;
    }

    public function avg(string $column): ?float
    {
        $v = $this->aggregate('AVG', $column);
        return $v === null ? null : (float)$v;
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Страница результатов для списка с переключателем страниц:
     * ['items' => […], 'total' => 135, 'page' => 2, 'pages' => 7, 'perPage' => 20].
     * В items то же, что вернул бы get(): модели или массивы. В состояние компонента модели можно класть как есть.
     */
    public function paginate(int $perPage = 20, int $page = 1): array
    {
        $perPage = max(1, $perPage);
        $total = (clone $this)->count();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $items = (clone $this)->limit($perPage)->offset(($page - 1) * $perPage)->get();
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
        ];
    }

    // ---------------------------------------------------------------- запись

    /** Вставить строку (или список строк). Возвращает id последней вставленной. */
    public function insert(array $row): int|string
    {
        $rows = array_is_list($row) && $row !== [] && is_array($row[0]) ? $row : [$row];
        $cols = array_keys($rows[0]);
        if ($cols === []) {
            throw new \InvalidArgumentException('insert(): пустая строка');
        }
        $marks = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $values = [];
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                $values[] = self::db($r[$c] ?? null);
            }
        }
        $sql = 'INSERT INTO ' . $this->db->wrap($this->table) . ' (' . implode(', ', array_map($this->db->wrap(...), $cols)) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), $marks));
        if ($this->db->driver === 'pgsql') {
            $row = $this->db->selectOne($sql . ' RETURNING *', $values);
            $id = $row['id'] ?? null;
            return is_numeric($id) ? (int)$id : (string)$id;
        }
        $this->db->statement($sql, $values);
        return $this->db->lastInsertId();
    }

    /** Обновить подходящие строки. Возвращает их число. */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }
        $set = [];
        $b = [];
        foreach ($values as $c => $v) {
            if ($v instanceof Raw) {
                $set[] = $this->db->wrap((string)$c) . ' = ' . $v->sql;
            } else {
                $set[] = $this->db->wrap((string)$c) . ' = ?';
                $b[] = self::db($v);
            }
        }
        [$where, $wb] = $this->compileWheres();
        return $this->db->statement('UPDATE ' . $this->db->wrap($this->table) . ' SET ' . implode(', ', $set) . $where, [...$b, ...$wb]);
    }

    public function increment(string $column, int|float $by = 1, array $also = []): int
    {
        $c = $this->db->wrap($column);
        return $this->update([$column => new Raw("{$c} + " . (0 + $by))] + $also);
    }

    public function decrement(string $column, int|float $by = 1, array $also = []): int
    {
        return $this->increment($column, -$by, $also);
    }

    /** Удалить подходящие строки. Возвращает их число. */
    public function delete(): int
    {
        [$where, $b] = $this->compileWheres();
        return $this->db->statement('DELETE FROM ' . $this->db->wrap($this->table) . $where, $b);
    }

    // ---------------------------------------------------------------- SQL

    /** @return array{0:string,1:array} */
    public function toSql(): array
    {
        $cols = $this->columns ?: ['*'];
        $sel = implode(', ', array_map(fn ($c) => $c instanceof Raw ? $c->sql : $this->db->wrap($c), $cols));
        [$where, $b] = $this->compileWheres();
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . $sel . ' FROM ' . $this->db->wrap($this->table) . $where;
        if ($this->orders) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ($this->limit === null && $this->db->driver !== 'pgsql' ? ' LIMIT ' . ($this->db->driver === 'mysql' ? '18446744073709551615' : '-1') : '') . ' OFFSET ' . $this->offset;
        }
        return [$sql, $b];
    }

    private function addWhere(string $bool, string|array|\Closure $column, mixed $op, mixed $value, int $argc): static
    {
        if ($column instanceof \Closure) {
            $inner = new self($this->db, $this->table);
            $column($inner);
            [$sql, $b] = $inner->compileWheres(false);
            if ($sql !== '') {
                $this->wheres[] = [$bool, "({$sql})", $b];
            }
            return $this;
        }
        if (is_array($column)) {
            foreach ($column as $c => $v) {
                $this->addWhere($bool, (string)$c, '=', $v, 3);
            }
            return $this;
        }
        if ($argc === 2) {
            [$op, $value] = ['=', $op];
        }
        $op = strtolower(trim((string)$op));
        if (!in_array($op, self::OPS, true)) {
            throw new \InvalidArgumentException("Недопустимый оператор сравнения «{$op}»");
        }
        $col = $this->db->wrap($column);
        if ($value === null) {
            $this->wheres[] = [$bool, $col . (in_array($op, ['!=', '<>'], true) ? ' IS NOT NULL' : ' IS NULL'), []];
            return $this;
        }
        $this->wheres[] = [$bool, "{$col} " . strtoupper($op) . ' ?', [self::db($value)]];
        return $this;
    }

    /** @return array{0:string,1:array} */
    private function compileWheres(bool $keyword = true): array
    {
        if (!$this->wheres) {
            return ['', []];
        }
        $sql = '';
        $b = [];
        foreach ($this->wheres as $i => [$bool, $part, $pb]) {
            $sql .= ($i === 0 ? '' : " {$bool} ") . $part;
            array_push($b, ...$pb);
        }
        return [($keyword ? ' WHERE ' : '') . $sql, $b];
    }

    private function aggregate(string $fn, string $column): mixed
    {
        $q = clone $this;
        $q->orders = [];
        $q->limit = $q->offset = null;
        $q->columns = [new Raw("{$fn}(" . ($column === '*' ? '*' : $this->db->wrap($column)) . ') AS agg')];
        [$sql, $b] = $q->toSql();
        return $this->db->selectOne($sql, $b)['agg'] ?? null;
    }

    /** @param list<array> $rows */
    private function hydrate(array $rows): array
    {
        if ($this->model === null) {
            return $rows;
        }
        $class = $this->model;
        return array_map(static fn (array $r) => $class::fromRow($r), $rows);
    }

    /** Значение для базы: bool как 0/1, массивы как JSON. */
    private static function db(mixed $v): mixed
    {
        return match (true) {
            is_bool($v) => (int)$v,
            is_array($v), $v instanceof \JsonSerializable => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $v instanceof \DateTimeInterface => $v->format('Y-m-d H:i:s'),
            $v instanceof \BackedEnum => $v->value,
            default => $v,
        };
    }

    private static function bare(string $column): string
    {
        if (preg_match('/\s+as\s+(\w+)$/i', $column, $m)) {
            return $m[1];
        }
        $p = strrpos($column, '.');
        return $p === false ? $column : substr($column, $p + 1);
    }
}
