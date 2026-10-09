<?php

declare(strict_types=1);

namespace Rig\Db;

use Rig\Config;

/**
 * Миграции: файлы database/migrations/ГГГГ_ММ_ДД_ЧЧММСС_имя.php выполняются по порядку имён.
 * Выполненные записываются в таблицу rig_migrations; запуски группируются в «пачки» (batch),
 * rig migrate:rollback откатывает последнюю пачку.
 *
 *   <?php
 *   use Rig\Db\{Schema, Table};
 *
 *   return new class {
 *       public function up(): void { Schema::create('todos', function (Table $t) { … }); }
 *       public function down(): void { Schema::dropIfExists('todos'); }
 *   };
 */
final class Migrator
{
    private const TABLE = 'rig_migrations';

    public function __construct(private readonly ?\Closure $log = null)
    {
    }

    public static function dir(): string
    {
        return Config::path('database/migrations');
    }

    /** @return list<string> выполненные миграции */
    public function migrate(): array
    {
        $this->ensureTable();
        $done = array_flip($this->ran());
        $pending = array_values(array_filter($this->files(), static fn (string $n) => !isset($done[$n])));
        if (!$pending) {
            return [];
        }
        $db = Schema::db();
        $batch = (int)$db->table(self::TABLE)->max('batch') + 1;
        foreach ($pending as $name) {
            $started = microtime(true);
            $this->transactional(function () use ($name, $db, $batch): void {
                $this->load($name)->up();
                $db->table(self::TABLE)->insert(['name' => $name, 'batch' => $batch, 'ran_at' => date('Y-m-d H:i:s')]);
            });
            $this->say($name, microtime(true) - $started);
        }
        return $pending;
    }

    /** Откатить последние $steps пачек. @return list<string> */
    public function rollback(int $steps = 1): array
    {
        $this->ensureTable();
        $db = Schema::db();
        $batches = $db->table(self::TABLE)->select('batch')->distinct()->orderBy('batch', 'desc')->limit(max(1, $steps))->pluck('batch');
        if (!$batches) {
            return [];
        }
        $names = $db->table(self::TABLE)->whereIn('batch', $batches)->orderBy('id', 'desc')->pluck('name');
        foreach ($names as $name) {
            $started = microtime(true);
            $this->transactional(function () use ($name, $db): void {
                $m = $this->load($name);
                if (method_exists($m, 'down')) {
                    $m->down();
                }
                $db->table(self::TABLE)->where('name', $name)->delete();
            });
            $this->say($name, microtime(true) - $started);
        }
        return $names;
    }

    /** Удалить все таблицы и выполнить все миграции заново. */
    public function fresh(): array
    {
        Schema::dropAll();
        return $this->migrate();
    }

    /** @return list<array{name:string,ran:bool,batch:?int}> */
    public function status(): array
    {
        $this->ensureTable();
        $rows = [];
        foreach (Schema::db()->table(self::TABLE)->get() as $r) {
            $rows[$r['name']] = (int)$r['batch'];
        }
        $out = [];
        foreach ($this->files() as $name) {
            $out[] = ['name' => $name, 'ran' => isset($rows[$name]), 'batch' => $rows[$name] ?? null];
        }
        return $out;
    }

    /** Создать файл миграции. Для create_todos_table в файл сразу пишется Schema::create('todos'). */
    public static function make(string $name): string
    {
        $name = strtolower((string)preg_replace('/[^A-Za-z0-9_]+/', '_', $name));
        if (!is_dir(self::dir())) {
            mkdir(self::dir(), 0777, true);
        }
        $file = self::dir() . '/' . date('Y_m_d_His') . "_{$name}.php";
        if (preg_match('/^create_(\w+?)_table$/', $name, $m)) {
            $up = "        Schema::create('{$m[1]}', function (Table \$t) {\n            \$t->id();\n            \$t->timestamps();\n        });";
            $down = "        Schema::dropIfExists('{$m[1]}');";
        } elseif (preg_match('/_(?:to|in|from)_(\w+?)(?:_table)?$/', $name, $m)) {
            $up = "        Schema::table('{$m[1]}', function (Table \$t) {\n            //\n        });";
            $down = "        Schema::table('{$m[1]}', function (Table \$t) {\n            //\n        });";
        } else {
            $up = $down = '        //';
        }
        file_put_contents($file, "<?php\n\nuse Rig\\Db\\Schema;\nuse Rig\\Db\\Table;\n\nreturn new class {\n    public function up(): void\n    {\n{$up}\n    }\n\n    public function down(): void\n    {\n{$down}\n    }\n};\n");
        return $file;
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = array_map(static fn (string $f) => basename($f, '.php'), glob(self::dir() . '/*.php') ?: []);
        sort($files, SORT_STRING);
        return $files;
    }

    /** @return list<string> */
    private function ran(): array
    {
        return Schema::db()->table(self::TABLE)->orderBy('id')->pluck('name');
    }

    private function load(string $name): object
    {
        $m = require self::dir() . "/{$name}.php";
        if (!is_object($m) || !method_exists($m, 'up')) {
            throw new \RuntimeException("Миграция {$name}: файл должен вернуть объект с методом up() — return new class { public function up(): void { … } };");
        }
        return $m;
    }

    private function ensureTable(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Table $t): void {
                $t->id();
                $t->string('name')->unique();
                $t->integer('batch');
                $t->dateTime('ran_at');
            });
        }
    }

    /** MySQL выполняет DDL вне транзакций (неявный COMMIT), поэтому там миграция идёт без обёртки. */
    private function transactional(\Closure $fn): void
    {
        $db = Schema::db();
        if ($db->driver === 'mysql') {
            $fn();
            return;
        }
        $db->transaction($fn);
    }

    private function say(string $name, float $sec): void
    {
        if ($this->log !== null) {
            ($this->log)($name, $sec);
        }
    }
}
