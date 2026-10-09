<?php

declare(strict_types=1);

namespace Rig;

use Rig\Db\Connection;
use Rig\Db\Query;
use Rig\Db\Raw;

/**
 * База данных.
 *
 *   DB::table('todos')->where('done', false)->orderBy('id')->get();
 *   DB::table('todos')->insert(['title' => 'Купить хлеб']);
 *   DB::transaction(function () { … });
 *
 * Подключения описаны в ключе 'db' в config.php (DB_CONNECTION, DB_DATABASE… в .env). По умолчанию SQLite в storage/database.sqlite.
 */
final class DB
{
    /** @var array<string,Connection> */
    private static array $connections = [];

    public static function connection(?string $name = null): Connection
    {
        $name ??= (string)Config::get('db.default', 'sqlite');
        if (!isset(self::$connections[$name])) {
            $cfg = Config::get("db.connections.{$name}");
            if (!is_array($cfg)) {
                if ($name !== 'sqlite') {
                    throw new \InvalidArgumentException("Нет подключения к базе «{$name}» в config.php ('db' => ['connections' => …])");
                }
                $cfg = ['driver' => 'sqlite', 'database' => 'storage/database.sqlite'];
            }
            self::$connections[$name] = new Connection($name, $cfg);
        }
        return self::$connections[$name];
    }

    public static function table(string $table): Query
    {
        return self::connection()->table($table);
    }

    /** @return list<array> */
    public static function select(string $sql, array $bindings = []): array
    {
        return self::connection()->select($sql, $bindings);
    }

    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        return self::connection()->selectOne($sql, $bindings);
    }

    public static function statement(string $sql, array $bindings = []): int
    {
        return self::connection()->statement($sql, $bindings);
    }

    public static function transaction(\Closure $fn): mixed
    {
        return self::connection()->transaction($fn);
    }

    public static function raw(string $sql): Raw
    {
        return new Raw($sql);
    }

    /** Закрыть подключения (тесты, долгие процессы вроде rig queue:work). */
    public static function disconnect(): void
    {
        self::$connections = [];
    }
}
