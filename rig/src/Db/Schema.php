<?php

declare(strict_types=1);

namespace Rig\Db;

use Rig\DB;

/**
 * Таблицы базы: в миграциях database/migrations/*.php.
 *
 *   Schema::create('todos', fn (Table $t) => …);
 *   Schema::table('todos', fn (Table $t) => $t->string('note')->nullable());
 *   Schema::dropIfExists('todos');
 *
 * SQL пишется под драйвер подключения: SQLite, MySQL или PostgreSQL.
 */
final class Schema
{
    private static ?string $connection = null;

    /** Подключение для следующих вызовов (по умолчанию основное). */
    public static function connection(?string $name): void
    {
        self::$connection = $name;
    }

    public static function create(string $name, \Closure $fn): void
    {
        $db = self::db();
        $t = new Table($name);
        $fn($t);
        $defs = [];
        $fks = [];
        foreach ($t->columns as $c) {
            $defs[] = self::column($db, $c);
            if ($c->references !== null) {
                $fks[] = self::foreign($db, $c);
            }
        }
        $sql = 'CREATE TABLE ' . $db->wrap($name) . " (\n  " . implode(",\n  ", [...$defs, ...$fks]) . "\n)";
        if ($db->driver === 'mysql') {
            $sql .= ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        $db->statement($sql);
        self::indexes($db, $t);
    }

    /** Изменить таблицу: новые колонки, удаление и переименование колонок, индексы. */
    public static function table(string $name, \Closure $fn): void
    {
        $db = self::db();
        $t = new Table($name);
        $fn($t);
        foreach ($t->columns as $c) {
            $def = self::column($db, $c);
            if ($c->references !== null && $db->driver !== 'sqlite') {
                $def .= ', ADD ' . self::foreign($db, $c);
            } elseif ($c->references !== null) {
                // SQLite не добавляет ограничение к существующей таблице, поэтому добавляется только колонка со ссылкой
                $r = $c->references;
                $def .= ' REFERENCES ' . $db->wrap($r['table']) . ' (' . $db->wrap($r['column']) . ')' . ($r['onDelete'] ? ' ON DELETE ' . $r['onDelete'] : '');
            }
            $db->statement('ALTER TABLE ' . $db->wrap($name) . ' ADD ' . ($db->driver === 'sqlite' ? 'COLUMN ' : '') . $def);
        }
        foreach ($t->renames as [$from, $to]) {
            $db->statement('ALTER TABLE ' . $db->wrap($name) . ' RENAME COLUMN ' . $db->wrap($from) . ' TO ' . $db->wrap($to));
        }
        foreach ($t->drops as $col) {
            $db->statement('ALTER TABLE ' . $db->wrap($name) . ' DROP COLUMN ' . $db->wrap($col));
        }
        self::indexes($db, $t);
    }

    public static function drop(string $name): void
    {
        self::db()->statement('DROP TABLE ' . self::db()->wrap($name));
    }

    public static function dropIfExists(string $name): void
    {
        self::db()->statement('DROP TABLE IF EXISTS ' . self::db()->wrap($name));
    }

    public static function rename(string $from, string $to): void
    {
        $db = self::db();
        $db->statement($db->driver === 'mysql'
            ? 'RENAME TABLE ' . $db->wrap($from) . ' TO ' . $db->wrap($to)
            : 'ALTER TABLE ' . $db->wrap($from) . ' RENAME TO ' . $db->wrap($to));
    }

    public static function hasTable(string $name): bool
    {
        return in_array($name, self::tables(), true);
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $db = self::db();
        $cols = match ($db->driver) {
            'sqlite' => array_column($db->select('PRAGMA table_info(' . $db->wrap($table) . ')'), 'name'),
            'mysql' => array_column($db->select('SHOW COLUMNS FROM ' . $db->wrap($table)), 'Field'),
            default => array_column($db->select('SELECT column_name FROM information_schema.columns WHERE table_name = ?', [$table]), 'column_name'),
        };
        return in_array($column, $cols, true);
    }

    /** @return list<string> */
    public static function tables(): array
    {
        $db = self::db();
        return match ($db->driver) {
            'sqlite' => array_column($db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'name'),
            'mysql' => array_map(static fn (array $r) => (string)reset($r), $db->select('SHOW TABLES')),
            default => array_column($db->select("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename"), 'tablename'),
        };
    }

    /** Удалить все таблицы (rig migrate:fresh). */
    public static function dropAll(): void
    {
        $db = self::db();
        $tables = self::tables();
        if ($db->driver === 'sqlite') {
            $db->statement('PRAGMA foreign_keys = OFF');
        } elseif ($db->driver === 'mysql') {
            $db->statement('SET FOREIGN_KEY_CHECKS = 0');
        }
        foreach ($tables as $t) {
            $db->statement('DROP TABLE IF EXISTS ' . $db->wrap($t) . ($db->driver === 'pgsql' ? ' CASCADE' : ''));
        }
        if ($db->driver === 'sqlite') {
            $db->statement('PRAGMA foreign_keys = ON');
        } elseif ($db->driver === 'mysql') {
            $db->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public static function db(): Connection
    {
        return DB::connection(self::$connection);
    }

    private static function column(Connection $db, Column $c): string
    {
        $d = $db->driver;
        if ($c->type === 'id') {
            return $db->wrap($c->name) . ' ' . match ($d) {
                'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'mysql' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                default => 'BIGSERIAL PRIMARY KEY',
            };
        }
        $type = match ($c->type) {
            'string' => 'VARCHAR(' . (int)($c->args[0] ?? 255) . ')',
            'text' => 'TEXT',
            'integer' => $d === 'mysql' ? 'INT' : 'INTEGER',
            'bigInteger' => $d === 'sqlite' ? 'INTEGER' : 'BIGINT',
            'float' => match ($d) { 'sqlite' => 'REAL', 'mysql' => 'DOUBLE', default => 'DOUBLE PRECISION' },
            'decimal' => $d === 'sqlite' ? 'NUMERIC' : 'DECIMAL(' . (int)$c->args[0] . ', ' . (int)$c->args[1] . ')',
            'boolean' => match ($d) { 'sqlite' => 'INTEGER', 'mysql' => 'TINYINT(1)', default => 'BOOLEAN' },
            'json' => match ($d) { 'sqlite' => 'TEXT', 'mysql' => 'JSON', default => 'JSONB' },
            'date' => $d === 'sqlite' ? 'TEXT' : 'DATE',
            'datetime' => match ($d) { 'sqlite' => 'TEXT', 'mysql' => 'DATETIME', default => 'TIMESTAMP(0)' },
            default => throw new \InvalidArgumentException("Неизвестный тип колонки {$c->type}"),
        };
        if ($c->unsigned && $d === 'mysql' && in_array($c->type, ['integer', 'bigInteger'], true)) {
            $type .= ' UNSIGNED';
        }
        $sql = $db->wrap($c->name) . ' ' . $type . ($c->nullable ? ' NULL' : ' NOT NULL');
        if ($c->hasDefault) {
            $sql .= ' DEFAULT ' . self::literal($db, $c, $c->default);
        }
        return $sql;
    }

    private static function literal(Connection $db, Column $c, mixed $v): string
    {
        return match (true) {
            $v instanceof Raw => $v->sql,
            $v === null => 'NULL',
            is_bool($v) => $db->driver === 'pgsql' && $c->type === 'boolean' ? ($v ? 'TRUE' : 'FALSE') : ($v ? '1' : '0'),
            is_int($v), is_float($v) => (string)$v,
            is_array($v) => $db->pdo->quote(json_encode($v, JSON_UNESCAPED_UNICODE)),
            default => $db->pdo->quote((string)$v),
        };
    }

    private static function foreign(Connection $db, Column $c): string
    {
        $r = $c->references;
        return 'FOREIGN KEY (' . $db->wrap($c->name) . ') REFERENCES ' . $db->wrap($r['table']) . ' (' . $db->wrap($r['column']) . ')'
            . ($r['onDelete'] ? ' ON DELETE ' . $r['onDelete'] : '');
    }

    private static function indexes(Connection $db, Table $t): void
    {
        $list = $t->indexes;
        foreach ($t->columns as $c) {
            if ($c->unique) {
                $list[] = [$t->name . '_' . $c->name . '_unique', [$c->name], true];
            } elseif ($c->index) {
                $list[] = [$t->name . '_' . $c->name . '_index', [$c->name], false];
            }
        }
        foreach ($list as [$name, $cols, $unique]) {
            $db->statement('CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $db->wrap($name) . ' ON ' . $db->wrap($t->name)
                . ' (' . implode(', ', array_map($db->wrap(...), $cols)) . ')');
        }
    }
}
