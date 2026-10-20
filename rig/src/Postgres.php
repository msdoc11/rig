<?php

declare(strict_types=1);

namespace Rig;

/**
 * PostgreSQL проекта для разработки: rig dev сам создаёт и запускает свой кластер.
 * Данные в storage/postgres, отдельный порт, без общих баз и ручной установки.
 *
 * Включается в .env:  DB_CONNECTION=pgsql  и  DB_MANAGED=true.
 * Программы PostgreSQL берутся из PG_BIN (папка с pg_ctl), иначе ищутся в PATH и в обычных местах установки:
 * OSPanel и Program Files на Windows, /usr/lib/postgresql и /usr/pgsql-* на Linux, Homebrew и Postgres.app на macOS.
 * На продакшене DB_MANAGED=false, база живёт на вашем сервере PostgreSQL.
 */
final class Postgres
{
    public function __construct(private readonly array $cfg)
    {
    }

    /** Настройки подключения 'pgsql' из config.php. */
    public static function fromConfig(): self
    {
        $name = (string)Config::get('db.default', 'pgsql');
        $cfg = (array)Config::get("db.connections.{$name}", []);
        return new self($cfg);
    }

    public static function managed(): bool
    {
        $name = (string)Config::get('db.default', '');
        return (bool)Config::get('db.managed', false) && Config::get("db.connections.{$name}.driver") === 'pgsql';
    }

    public function port(): int
    {
        return (int)($this->cfg['port'] ?? 5432);
    }

    public function dataDir(): string
    {
        return Config::path('storage/postgres/data');
    }

    public function logFile(): string
    {
        return Config::path('storage/postgres/server.log');
    }

    private ?string $bin = null;

    /** Папка с pg_ctl, initdb, psql. */
    public function bin(): string
    {
        return $this->bin ??= $this->findBin();
    }

    private function findBin(): string
    {
        $exe = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        $candidates = [];
        $env = Env::get('PG_BIN');
        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $p) {
            $candidates[] = $p;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            // OSPanel: соседние модули PostgreSQL-NN; установщик PostgreSQL: Program Files\PostgreSQL\NN
            $ospanel = dirname(PHP_BINARY, 3);
            foreach ([...(glob("{$ospanel}/modules/PostgreSQL-*/bin") ?: []), ...(glob('C:/Program Files/PostgreSQL/*/bin') ?: [])] as $dir) {
                $candidates[] = $dir;
            }
        } else {
            // Debian и Ubuntu, RHEL и Fedora, Homebrew (в том числе postgresql@16 вне PATH), Postgres.app
            foreach ([
                '/usr/lib/postgresql/*/bin', '/usr/pgsql-*/bin',
                '/opt/homebrew/opt/postgresql*/bin', '/usr/local/opt/postgresql*/bin',
                '/Applications/Postgres.app/Contents/Versions/*/bin',
            ] as $pattern) {
                array_push($candidates, ...(glob($pattern) ?: []));
            }
            array_push($candidates, '/usr/local/bin', '/opt/homebrew/bin');
        }
        $found = [];
        foreach ($candidates as $dir) {
            $dir = rtrim(str_replace('\\', '/', trim($dir, " \"")), '/');
            if ($dir !== '' && is_file("{$dir}/pg_ctl{$exe}") && is_file("{$dir}/initdb{$exe}")) {
                $found[] = $dir;
            }
        }
        if (!$found) {
            $example = PHP_OS_FAMILY === 'Windows' ? 'D:/OSPanel/modules/PostgreSQL-18/bin' : '/usr/lib/postgresql/16/bin';
            throw new \RuntimeException("Не найден PostgreSQL (pg_ctl и initdb). Установите его или укажите папку в .env: PG_BIN={$example}");
        }
        if ($env === null || $env === '') {
            // из найденных берём самую новую версию
            usort($found, static fn ($a, $b) => version_compare(self::versionOf($b), self::versionOf($a)));
        }
        return $found[0];
    }

    public function version(): string
    {
        return self::versionOf($this->bin());
    }

    public function running(): bool
    {
        $s = @fsockopen('127.0.0.1', $this->port(), $errno, $errstr, 0.3);
        if ($s === false) {
            return false;
        }
        fclose($s);
        return is_file($this->dataDir() . '/postmaster.pid');
    }

    /**
     * Создать кластер (при первом запуске), запустить сервер и базу проекта.
     * Возвращает, что было сделано: 'запущен', 'уже работал', 'создан и запущен'.
     */
    public function start(): string
    {
        $data = $this->dataDir();
        $did = 'запущен';
        if ($this->running()) {
            $did = 'уже работал';
        } else {
            $s = @fsockopen('127.0.0.1', $this->port(), $errno, $errstr, 0.3);
            if ($s !== false) {
                fclose($s);
                throw new \RuntimeException("Порт {$this->port()} занят другой программой. Укажите другой в .env: DB_PORT=54330");
            }
            if (!is_file("{$data}/PG_VERSION")) {
                $this->init();
                $did = 'создан и запущен';
            }
            @unlink("{$data}/postmaster.pid"); // остался от аварийной остановки
            $opts = '-p ' . $this->port() . ' -h 127.0.0.1';
            $this->run([$this->exe('pg_ctl'), 'start', '-D', $data, '-l', $this->logFile(), '-o', $opts, '-w', '-t', '30'], 'Не удалось запустить PostgreSQL');
        }
        $this->ensureDatabase();
        return $did;
    }

    public function stop(): void
    {
        if (!is_file($this->dataDir() . '/postmaster.pid')) {
            return;
        }
        $this->run([$this->exe('pg_ctl'), 'stop', '-D', $this->dataDir(), '-m', 'fast', '-w', '-t', '20'], 'Не удалось остановить PostgreSQL', false);
    }

    private function init(): void
    {
        $dir = dirname($this->dataDir());
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $pw = "{$dir}/.pwfile";
        file_put_contents($pw, (string)($this->cfg['password'] ?? ''));
        try {
            $args = [$this->exe('initdb'), '-D', $this->dataDir(), '-U', (string)($this->cfg['username'] ?? 'rig'),
                '--pwfile=' . $pw, '--auth=scram-sha-256', '--encoding=UTF8'];
            // встроенная локаль (PostgreSQL 17+) одинакова на всех ОС; в старых версиях C
            $args = [...$args, ...(version_compare($this->version(), '17', '>=') ? ['--locale-provider=builtin', '--builtin-locale=C.UTF-8'] : ['--locale=C'])];
            $this->run($args, 'initdb не смог создать кластер PostgreSQL');
        } finally {
            @unlink($pw);
        }
    }

    /** Создать базу проекта (DB_DATABASE), если её ещё нет. */
    private function ensureDatabase(): void
    {
        $db = (string)($this->cfg['database'] ?? 'rig');
        $dsn = 'pgsql:host=127.0.0.1;port=' . $this->port() . ';dbname=postgres';
        $pdo = new \PDO($dsn, (string)($this->cfg['username'] ?? 'rig'), (string)($this->cfg['password'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $st = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $st->execute([$db]);
        if ($st->fetchColumn() === false) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $db)) {
                throw new \RuntimeException("Недопустимое имя базы «{$db}»");
            }
            $pdo->exec('CREATE DATABASE "' . $db . '"');
        }
    }

    private function exe(string $name): string
    {
        return $this->bin() . '/' . $name . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    private function run(array $cmd, string $error, bool $strict = true): void
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $out = Config::storage('postgres') . '/pg_ctl.out';
        $proc = proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new \RuntimeException($error);
        }
        $code = proc_close($proc);
        if ($code !== 0 && $strict) {
            $text = trim((string)@file_get_contents($out));
            $log = is_file($this->logFile()) ? "\n" . implode("\n", array_slice(file($this->logFile(), FILE_IGNORE_NEW_LINES) ?: [], -6)) : '';
            throw new \RuntimeException("{$error} (код {$code}).\n{$text}{$log}");
        }
    }

    private static function versionOf(string $bin): string
    {
        static $cache = [];
        if (isset($cache[$bin])) {
            return $cache[$bin];
        }
        $out = (string)shell_exec(escapeshellarg($bin . '/pg_ctl' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '')) . ' --version');
        return $cache[$bin] = preg_match('/(\d+(?:\.\d+)?)/', $out, $m) ? $m[1] : '0';
    }
}
