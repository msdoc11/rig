<?php

/**
 * Тесты бэкенда: .env, кеш, база (запросы, модели, схема, миграции), сессии, CSRF, авторизация,
 * загрузки, очередь, почта, планировщик. Всё во временной папке и SQLite в памяти, проект не трогается.
 * Запуск: php rig/tests/backend.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Rig\Config;
use Rig\DB;

$tmp = sys_get_temp_dir() . '/rig-backend-' . getmypid();
@mkdir($tmp . '/storage', 0777, true);
Config::init($tmp, [
    'db' => ['default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']]],
]);

$fail = 0;
$total = 0;
$section = '';

function check(string $name, bool $ok, mixed $got = null): void
{
    global $fail, $total, $section;
    $total++;
    if (!$ok) {
        $fail++;
        echo "✗ {$section}: {$name}" . ($got !== null ? '  получено: ' . json_encode($got, JSON_UNESCAPED_UNICODE) : '') . "\n";
    }
}

function throws(\Closure $fn, string $contains = ''): bool
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $contains === '' || str_contains($e->getMessage(), $contains);
    }
    return false;
}

function rrmdir(string $dir): void
{
    foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $f) {
        is_dir($f) ? rrmdir($f) : @unlink($f);
    }
    @rmdir($dir);
}

foreach (glob(__DIR__ . '/backend/*.php') ?: [] as $file) {
    $section = basename($file, '.php');
    require $file;
}

// те же проверки базы на PostgreSQL проекта (rig dev запускает его сам), в отдельной базе rig_test
$pgPort = (int)env('DB_PORT', 5432);
$pgUser = (string)env('DB_USERNAME', 'rig');
$pgPass = (string)env('DB_PASSWORD', '');
$pgUp = env('DB_CONNECTION') === 'pgsql' && extension_loaded('pdo_pgsql') && ($s = @fsockopen('127.0.0.1', $pgPort, $errno, $errstr, 0.3)) !== false;
if ($pgUp) {
    fclose($s);
    try {
        $admin = new PDO("pgsql:host=127.0.0.1;port={$pgPort};dbname=postgres", $pgUser, $pgPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($admin->query("SELECT 1 FROM pg_database WHERE datname = 'rig_test'")->fetchColumn() === false) {
            $admin->exec('CREATE DATABASE rig_test');
        }
        $admin = null;
    } catch (Throwable $e) {
        $pgUp = false;
        echo "PostgreSQL недоступен для тестов: {$e->getMessage()}\n";
    }
}
if ($pgUp) {
    Config::set('db.connections.test', ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $pgPort, 'database' => 'rig_test', 'username' => $pgUser, 'password' => $pgPass]);
    DB::disconnect();
    Rig\Db\Schema::dropAll();
    $section = '2_db (PostgreSQL)';
    require __DIR__ . '/backend/2_db.php';
    Rig\Db\Schema::dropAll();
    DB::disconnect();
} else {
    echo "(PostgreSQL не запущен — проверки базы только на SQLite)\n";
}

rrmdir($tmp);
echo $fail ? "Бэкенд: {$fail} из {$total} не прошли\n" : "Бэкенд: все {$total} проверок ✓\n";
exit($fail ? 1 : 0);
