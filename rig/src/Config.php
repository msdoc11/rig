<?php

declare(strict_types=1);

namespace Rig;

/**
 * Настройки приложения (config.php) и пути проекта. В коде: config('db.default'), Config::path('storage/logs').
 */
final class Config
{
    private static array $data = [];
    private static string $root = '';

    public static function init(string $root, array $config): void
    {
        self::$root = rtrim(str_replace('\\', '/', $root), '/');
        self::$data = $config;
    }

    public static function root(): string
    {
        if (self::$root === '') {
            self::$root = str_replace('\\', '/', dirname(__DIR__, 2));
        }
        return self::$root;
    }

    /** Путь от корня проекта. */
    public static function path(string $rel = ''): string
    {
        return self::root() . ($rel === '' ? '' : '/' . ltrim($rel, '/'));
    }

    /** Значение по ключу с точками: get('mail.from.address'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $v = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($v) || !array_key_exists($part, $v)) {
                return $default;
            }
            $v = $v[$part];
        }
        return $v;
    }

    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$data;
        foreach (explode('.', $key) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }

    /** Ключ подписи приложения: APP_KEY из .env или storage/app.key. */
    public static function key(): string
    {
        $k = Env::get('APP_KEY');
        return is_string($k) && $k !== '' ? $k : App::key(self::path('storage'));
    }

    /** Создать папку в storage (если её нет) и вернуть путь. */
    public static function storage(string $rel): string
    {
        $dir = self::path('storage/' . $rel);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }
}
