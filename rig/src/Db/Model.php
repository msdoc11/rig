<?php

declare(strict_types=1);

namespace Rig\Db;

use Rig\DB;

/**
 * Модель: строка таблицы в виде объекта.
 *
 *   final class Todo extends Model
 *   {
 *       protected static array $casts = ['done' => 'bool'];
 *   }
 *
 *   $todo = Todo::create(['title' => 'Купить хлеб']);
 *   $todo->done = true;
 *   $todo->save();
 *   Todo::where('done', false)->orderBy('id')->get();
 *
 * Таблица по умолчанию: имя класса во множественном числе (Todo: todos, TodoList: todo_lists).
 * В состояние компонента модель кладётся массивом: $this->todos = Todo::all(), toArray() rig вызовет сам.
 *
 * @method static Query with(string|array ...$relations)
 * @method static Query where(string|array|\Closure $column, mixed $op = null, mixed $value = null)
 * @method static Query whereIn(string $column, array $values)
 * @method static Query whereNull(string $column)
 * @method static Query whereNotNull(string $column)
 * @method static Query orderBy(string $column, string $direction = 'asc')
 * @method static Query latest(string $column = 'id')
 * @method static Query limit(int $n)
 * @method static Query select(string ...$columns)
 * @method static int count()
 * @method static array paginate(int $perPage = 20, int $page = 1)
 */
abstract class Model implements \JsonSerializable, \ArrayAccess
{
    /** Имя таблицы; при null берётся из имени класса. */
    protected static ?string $table = null;
    protected static string $primaryKey = 'id';
    /** Подключение из config.php; при null используется подключение по умолчанию. */
    protected static ?string $connection = null;
    /** Поля, которые можно заполнить из массива (create, fill). Если пусто, можно все, кроме $guarded. */
    protected static array $fillable = [];
    protected static array $guarded = ['id'];
    /** Поля, которые не попадают в toArray() и состояние компонента: пароли, токены. */
    protected static array $hidden = [];
    /** Типы полей: int, float, bool, string, array (JSON в базе). */
    protected static array $casts = [];
    /** created_at и updated_at заполняются сами. */
    protected static bool $timestamps = true;

    private array $attributes = [];
    private array $original = [];
    public bool $exists = false;

    final public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    // ---------------------------------------------------------------- запросы

    public static function query(): Query
    {
        return new Query(DB::connection(static::$connection), static::table(), static::class);
    }

    public static function __callStatic(string $method, array $args): mixed
    {
        return static::query()->$method(...$args);
    }

    /** @return list<static> */
    public static function all(): array
    {
        return static::query()->get();
    }

    public static function find(int|string $id): ?static
    {
        return static::query()->find($id, static::$primaryKey);
    }

    /** Запись или RecordNotFound (в ответе на запрос это 404). */
    public static function findOrFail(int|string $id): static
    {
        return static::find($id) ?? throw new RecordNotFound(static::table());
    }

    public static function create(array $attributes): static
    {
        $m = new static($attributes);
        $m->save();
        return $m;
    }

    public static function table(): string
    {
        return static::$table ?? self::tableFor(static::class);
    }

    /** Таблица по умолчанию для класса модели: Order даёт orders, Category даёт categories, TodoList даёт todo_lists. */
    public static function tableFor(string $class): string
    {
        $snake = self::snake($class);
        return match (true) {
            (bool)preg_match('/[^aeiou]y$/', $snake) => substr($snake, 0, -1) . 'ies',
            (bool)preg_match('/(s|x|z|ch|sh)$/', $snake) => $snake . 'es',
            default => $snake . 's',
        };
    }

    /** @internal модель из строки базы */
    public static function fromRow(array $row): static
    {
        $m = new static();
        foreach ($row as $k => $v) {
            $m->attributes[$k] = static::castGet($k, $v);
        }
        $m->original = $m->attributes;
        $m->exists = true;
        return $m;
    }

    // ---------------------------------------------------------------- запись

    public function fill(array $attributes): static
    {
        foreach ($attributes as $k => $v) {
            $k = (string)$k;
            $allowed = static::$fillable ? in_array($k, static::$fillable, true) : !in_array($k, static::$guarded, true);
            if ($allowed) {
                $this->__set($k, $v);
            }
        }
        return $this;
    }

    /** Записать в базу: новую строку через INSERT, изменённые поля существующей через UPDATE. */
    public function save(): bool
    {
        $now = date('Y-m-d H:i:s');
        $q = static::query();
        $pk = static::$primaryKey;
        if (!$this->exists) {
            if (static::$timestamps) {
                $this->attributes['created_at'] ??= $now;
                $this->attributes['updated_at'] ??= $now;
            }
            $id = (new Query($q->db, $q->table))->insert($this->dbValues($this->attributes));
            if (!isset($this->attributes[$pk]) && $id !== '' && $id !== 0) {
                $this->attributes[$pk] = $id;
            }
            $this->exists = true;
            $this->original = $this->attributes;
            return true;
        }
        $dirty = $this->dirty();
        if (!$dirty) {
            return false;
        }
        if (static::$timestamps && !isset($dirty['updated_at'])) {
            $this->attributes['updated_at'] = $dirty['updated_at'] = $now;
        }
        (new Query($q->db, $q->table))->where($pk, $this->getKey())->update($this->dbValues($dirty));
        $this->original = $this->attributes;
        return true;
    }

    public function update(array $attributes): bool
    {
        return $this->fill($attributes)->save();
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        $q = static::query();
        (new Query($q->db, $q->table))->where(static::$primaryKey, $this->getKey())->delete();
        $this->exists = false;
        return true;
    }

    /** Перечитать из базы. */
    public function refresh(): static
    {
        $fresh = static::find($this->getKey());
        if ($fresh !== null) {
            $this->attributes = $fresh->attributes;
            $this->original = $fresh->original;
        }
        return $this;
    }

    public function getKey(): int|string|null
    {
        return $this->attributes[static::$primaryKey] ?? null;
    }

    /** Изменённые после чтения поля. */
    public function dirty(): array
    {
        $out = [];
        foreach ($this->attributes as $k => $v) {
            if (!array_key_exists($k, $this->original) || $this->original[$k] !== $v) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public function isDirty(?string $field = null): bool
    {
        $d = $this->dirty();
        return $field === null ? $d !== [] : array_key_exists($field, $d);
    }

    // ---------------------------------------------------------------- связи

    /** Связи, загруженные через with(): имя => модель, список моделей или null. */
    private array $relations = [];

    /**
     * Пока with() узнаёт, что за связь объявлена в методе, хелперы ниже не делают запрос,
     * а записывают сюда тип и ключи: [тип, класс, внешний ключ, ключ].
     */
    private static bool $describing = false;
    private static ?array $spec = null;

    /** Дочерние записи: $post->hasMany(Comment::class), связь comments.post_id = posts.id. */
    protected function hasMany(string $class, ?string $foreignKey = null, ?string $localKey = null): Query
    {
        $localKey ??= static::$primaryKey;
        $foreignKey ??= $this->foreignKeyName();
        if (self::$describing) {
            self::$spec = ['many', $class, $foreignKey, $localKey];
            return $class::query();
        }
        return $class::query()->where($foreignKey, $this->attributes[$localKey] ?? null);
    }

    protected function hasOne(string $class, ?string $foreignKey = null, ?string $localKey = null): ?Model
    {
        $q = $this->hasMany($class, $foreignKey, $localKey);
        if (self::$describing) {
            self::$spec[0] = 'one';
            return null;
        }
        return $q->first();
    }

    /** Родительская запись: $comment->belongsTo(Post::class), comments.post_id ссылается на posts.id. */
    protected function belongsTo(string $class, ?string $foreignKey = null, string $ownerKey = 'id'): ?Model
    {
        $foreignKey ??= self::snake($class) . '_id';
        if (self::$describing) {
            self::$spec = ['belongs', $class, $foreignKey, $ownerKey];
            return null;
        }
        $id = $this->attributes[$foreignKey] ?? null;
        return $id === null ? null : $class::query()->where($ownerKey, $id)->first();
    }

    /** Загружена ли связь через with(). */
    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    /**
     * @internal Связи для списка моделей одного класса: один запрос whereIn на каждую связь.
     * $with: ['author' => null, 'comments.author' => null, 'comments' => fn (Query $q) => …].
     * @param list<Model> $models
     */
    public static function eagerLoad(array $models, array $with): void
    {
        if (!$models) {
            return;
        }
        // 'comments.author' раскладываем в дерево: comments => вложенные [author]
        $tree = [];
        foreach ($with as $path => $constraint) {
            $parts = explode('.', (string)$path);
            $head = array_shift($parts);
            $tree[$head] ??= ['constraint' => null, 'nested' => []];
            if ($parts) {
                $tree[$head]['nested'][implode('.', $parts)] = $constraint;
            } else {
                $tree[$head]['constraint'] = $constraint;
            }
        }
        $class = get_class($models[0]);
        foreach ($tree as $name => $opt) {
            [$type, $related, $fk, $key, $base] = self::relationSpec($class, $name);
            $q = $base ? clone $base : $related::query();
            if ($type === 'belongs') {
                // родитель: собираем внешние ключи детей, ищем родителей по ключу владельца
                $ids = self::keys($models, $fk);
                if ($opt['constraint']) {
                    ($opt['constraint'])($q);
                }
                $byKey = [];
                foreach ($ids ? $q->whereIn($key, $ids)->get() : [] as $r) {
                    $byKey[(string)$r->attributes[$key]] = $r;
                }
                foreach ($models as $m) {
                    $id = $m->attributes[$fk] ?? null;
                    $m->relations[$name] = $id === null ? null : ($byKey[(string)$id] ?? null);
                }
                $loaded = array_values($byKey);
            } else {
                // дети: ищем по внешнему ключу среди ключей родителей и раскладываем по родителям
                $ids = self::keys($models, $key);
                if ($opt['constraint']) {
                    ($opt['constraint'])($q);
                }
                $loaded = $ids ? $q->whereIn($fk, $ids)->get() : [];
                $groups = [];
                foreach ($loaded as $r) {
                    $groups[(string)$r->attributes[$fk]][] = $r;
                }
                foreach ($models as $m) {
                    $group = $groups[(string)($m->attributes[$key] ?? '')] ?? [];
                    $m->relations[$name] = $type === 'one' ? ($group[0] ?? null) : $group;
                }
            }
            if ($opt['nested'] && $loaded) {
                self::eagerLoad($loaded, $opt['nested']);
            }
        }
    }

    /** Тип и ключи связи из метода модели, без запроса к базе. */
    private static function relationSpec(string $class, string $name): array
    {
        if (!method_exists($class, $name)) {
            throw new \InvalidArgumentException("with('{$name}'): у модели {$class} нет метода {$name}()");
        }
        self::$describing = true;
        self::$spec = null;
        try {
            $returned = (new $class())->$name();
        } finally {
            self::$describing = false;
        }
        $spec = self::$spec ?? throw new \InvalidArgumentException("with('{$name}'): {$class}::{$name}() должен вернуть hasMany(), hasOne() или belongsTo()");
        // hasMany(…)->where('done', false): условия из метода связи сохраняются и при жадной загрузке
        $spec[4] = $returned instanceof Query ? $returned : null;
        return $spec;
    }

    /** Непустые значения поля у списка моделей, без повторов. */
    private static function keys(array $models, string $field): array
    {
        $ids = [];
        foreach ($models as $m) {
            $v = $m->attributes[$field] ?? null;
            if ($v !== null) {
                $ids[(string)$v] = $v;
            }
        }
        return array_values($ids);
    }

    private function foreignKeyName(): string
    {
        return self::snake(static::class) . '_id';
    }

    /** App\Models\TodoList даёт todo_list */
    private static function snake(string $class): string
    {
        $short = basename(str_replace('\\', '/', $class));
        return strtolower((string)preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $short));
    }

    // ---------------------------------------------------------------- поля

    /** Поле записи или связь, загруженная через with(). */
    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = static::castGet($name, $value);
    }

    public function __isset(string $name): bool
    {
        return isset($this->relations[$name]) || isset($this->attributes[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset((string)$offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get((string)$offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->__set((string)$offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }

    /** Поля массивом, без $hidden, вместе со связями из with(). */
    public function toArray(): array
    {
        $out = array_diff_key($this->attributes, array_flip(static::$hidden));
        foreach ($this->relations as $name => $rel) {
            $out[$name] = match (true) {
                $rel instanceof self => $rel->toArray(),
                is_array($rel) => array_map(static fn (self $m) => $m->toArray(), $rel),
                default => null,
            };
        }
        return $out;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function castGet(string $key, mixed $v): mixed
    {
        $type = static::$casts[$key] ?? null;
        if ($type === null) {
            // id и *_id из SQLite/PDO приходят числами, остальное остаётся как есть
            return $v;
        }
        if ($v === null) {
            return null;
        }
        return match ($type) {
            'int', 'integer' => (int)$v,
            'float', 'double', 'decimal' => (float)$v,
            'bool', 'boolean' => is_string($v) ? !in_array(strtolower($v), ['', '0', 'false'], true) : (bool)$v,
            'string' => (string)$v,
            'array', 'json' => is_string($v) ? (json_decode($v, true) ?? []) : (array)$v,
            default => $v,
        };
    }

    private function dbValues(array $values): array
    {
        foreach ($values as $k => $v) {
            if (is_array($v)) {
                $values[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($v)) {
                $values[$k] = (int)$v;
            }
        }
        return $values;
    }
}
