<?php

declare(strict_types=1);

namespace Rig\Db;

use Rig\Config;

/**
 * Подключение к базе поверх PDO: SQLite, MySQL/MariaDB, PostgreSQL.
 * Все запросы идут через подготовленные выражения; строки приходят массивами.
 */
final class Connection
{
    public readonly \PDO $pdo;
    public readonly string $driver;
    /** Сколько запросов прошло через подключение: удобно ловить N+1, DB::connection()->queries. */
    public int $queries = 0;
    private int $depth = 0;

    public function __construct(public readonly string $name, array $cfg)
    {
        $this->driver = (string)($cfg['driver'] ?? 'sqlite');
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        switch ($this->driver) {
            case 'sqlite':
                $db = (string)($cfg['database'] ?? 'storage/database.sqlite');
                if ($db !== ':memory:') {
                    $db = preg_match('~^([a-z]:)?[\\\\/]~i', $db) ? $db : Config::path($db);
                    if (!is_dir(dirname($db))) {
                        mkdir(dirname($db), 0777, true);
                    }
                }
                $this->pdo = new \PDO("sqlite:{$db}", null, null, $options);
                $this->pdo->exec('PRAGMA foreign_keys = ON');
                $this->pdo->exec('PRAGMA busy_timeout = 5000'); // несколько воркеров пишут в один файл
                break;
            case 'mysql':
                $dsn = 'mysql:host=' . ($cfg['host'] ?? '127.0.0.1') . ';port=' . ($cfg['port'] ?? 3306)
                    . ';dbname=' . ($cfg['database'] ?? '') . ';charset=' . ($cfg['charset'] ?? 'utf8mb4');
                $this->pdo = new \PDO($dsn, (string)($cfg['username'] ?? 'root'), (string)($cfg['password'] ?? ''), $options);
                break;
            case 'pgsql':
                $dsn = 'pgsql:host=' . ($cfg['host'] ?? '127.0.0.1') . ';port=' . ($cfg['port'] ?? 5432) . ';dbname=' . ($cfg['database'] ?? '');
                $this->pdo = new \PDO($dsn, (string)($cfg['username'] ?? 'postgres'), (string)($cfg['password'] ?? ''), $options);
                $this->pdo->exec("SET NAMES 'UTF8'");
                break;
            default:
                throw new \InvalidArgumentException("Неизвестный драйвер базы «{$this->driver}»: нужен sqlite, mysql или pgsql");
        }
    }

    public function table(string $table): Query
    {
        return new Query($this, $table);
    }

    /** @return list<array> */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->fetchAll();
    }

    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();
        return is_array($row) ? $row : null;
    }

    /** INSERT/UPDATE/DELETE/DDL: число затронутых строк. */
    public function statement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    public function lastInsertId(?string $sequence = null): int|string
    {
        $id = $this->pdo->lastInsertId($sequence);
        return is_string($id) && ctype_digit($id) ? (int)$id : (string)$id;
    }

    /**
     * Всё внутри выполняется одной транзакцией, исключение откатывает изменения.
     * Вложенные вызовы работают в одной внешней транзакции.
     */
    public function transaction(\Closure $fn): mixed
    {
        if ($this->depth++ === 0) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $fn($this);
            if (--$this->depth === 0 && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($this->depth > 0) {
                $this->depth = 0;
            }
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Имя таблицы или колонки в кавычках драйвера. table.column и «*» допустимы. */
    public function wrap(string $id): string
    {
        $id = trim($id);
        if ($id === '*') {
            return '*';
        }
        if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $id, $m)) {
            return $this->wrap($m[1]) . ' AS ' . $this->wrap($m[2]);
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.([A-Za-z_][A-Za-z0-9_]*|\*))?$/', $id)) {
            throw new \InvalidArgumentException("Недопустимое имя колонки или таблицы «{$id}». Для выражений используйте DB::raw()");
        }
        $q = $this->driver === 'mysql' ? '`' : '"';
        return implode('.', array_map(static fn (string $p): string => $p === '*' ? '*' : $q . $p . $q, explode('.', $id)));
    }

    private function run(string $sql, array $bindings): \PDOStatement
    {
        $this->queries++;
        $st = $this->pdo->prepare($sql);
        $i = 0;
        foreach ($bindings as $v) {
            $i++;
            $st->bindValue($i, is_bool($v) ? (int)$v : $v, match (true) {
                is_int($v), is_bool($v) => \PDO::PARAM_INT,
                $v === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            });
        }
        try {
            $st->execute();
        } catch (\PDOException $e) {
            throw new QueryError($e->getMessage() . "\nSQL: {$sql}", (int)$e->getCode(), $e);
        }
        return $st;
    }
}
