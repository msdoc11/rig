<?php

declare(strict_types=1);

namespace Rig\Db;

use Rig\DB;
use Rig\HttpError;

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

    public static function findOrFail(int|string $id): static
    {
        return static::find($id) ?? throw new HttpError(404, 'Запись не найдена');
    }

    public static function create(array $attributes): static
    {
        $m = new static($attributes);
        $m->save();
        return $m;
    }

    public static function table(): string
    {
        if (static::$table !== null) {
            return static::$table;
        }
        $snake = self::snake(static::class);
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

    /** Дочерние записи: $post->hasMany(Comment::class), связь comments.post_id = posts.id. */
    protected function hasMany(string $class, ?string $foreignKey = null, ?string $localKey = null): Query
    {
        $localKey ??= static::$primaryKey;
        return $class::query()->where($foreignKey ?? $this->foreignKeyName(), $this->attributes[$localKey] ?? null);
    }

    protected function hasOne(string $class, ?string $foreignKey = null, ?string $localKey = null): ?Model
    {
        return $this->hasMany($class, $foreignKey, $localKey)->first();
    }

    /** Родительская запись: $comment->belongsTo(Post::class), comments.post_id ссылается на posts.id. */
    protected function belongsTo(string $class, ?string $foreignKey = null, string $ownerKey = 'id'): ?Model
    {
        $foreignKey ??= self::snake($class) . '_id';
        $id = $this->attributes[$foreignKey] ?? null;
        return $id === null ? null : $class::query()->where($ownerKey, $id)->first();
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

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = static::castGet($name, $value);
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->__set((string)$offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }

    /** Поля массивом, без $hidden. */
    public function toArray(): array
    {
        return array_diff_key($this->attributes, array_flip(static::$hidden));
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
