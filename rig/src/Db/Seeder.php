<?php

declare(strict_types=1);

namespace Rig\Db;

use Rig\Config;

/**
 * Начальные данные: файлы database/seeds/*.php, по порядку имён. Каждый возвращает функцию
 * или объект с методом run():
 *
 *   <?php
 *   use App\Models\User;
 *   return function () {
 *       User::create(['email' => 'admin@example.com', 'password' => Rig\Auth::hash('secret'), 'role' => 'admin']);
 *   };
 *
 * rig db:seed выполняет все файлы, rig db:seed --only=users только database/seeds/users.php.
 */
final class Seeder
{
    public static function dir(): string
    {
        return Config::path('database/seeds');
    }

    /** @return list<string> выполненные файлы */
    public static function run(?string $only = null, ?\Closure $log = null): array
    {
        $files = glob(self::dir() . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $ran = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if ($only !== null && $name !== $only) {
                continue;
            }
            $started = microtime(true);
            $seed = require $file;
            Schema::db()->transaction(static function () use ($seed, $name): void {
                if ($seed instanceof \Closure) {
                    $seed();
                } elseif (is_object($seed) && method_exists($seed, 'run')) {
                    $seed->run();
                } else {
                    throw new \RuntimeException("Сид {$name}: файл должен вернуть функцию или объект с методом run()");
                }
            });
            $ran[] = $name;
            if ($log !== null) {
                $log($name, microtime(true) - $started);
            }
        }
        if ($only !== null && !$ran) {
            throw new \RuntimeException("Нет файла database/seeds/{$only}.php");
        }
        return $ran;
    }
}
