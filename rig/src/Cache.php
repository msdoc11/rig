<?php

declare(strict_types=1);

namespace Rig;

/**
 * Кеш данных в файлах storage/cache/data, без Redis и других служб.
 *
 *   $top = Cache::remember('top-products', 600, fn () => Product::top());
 *   Cache::forget('top-products');
 *
 * ttl в секундах; null означает без срока.
 */
final class Cache
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $item = self::read($key);
        return $item === null ? ($default instanceof \Closure ? $default() : $default) : $item['v'];
    }

    public static function has(string $key): bool
    {
        return self::read($key) !== null;
    }

    public static function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $file = self::file($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, serialize(['e' => $ttl === null ? 0 : time() + $ttl, 'v' => $value]), LOCK_EX);
        rename($tmp, $file); // запись целиком: другой запрос не прочитает половину
    }

    public static function forever(string $key, mixed $value): void
    {
        self::set($key, $value);
    }

    /** Значение из кеша; если его нет, посчитать, сохранить и вернуть. */
    public static function remember(string $key, ?int $ttl, \Closure $fn): mixed
    {
        $item = self::read($key);
        if ($item !== null) {
            return $item['v'];
        }
        $value = $fn();
        self::set($key, $value, $ttl);
        return $value;
    }

    /** Достать и удалить. */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $v = self::get($key, $default);
        self::forget($key);
        return $v;
    }

    public static function increment(string $key, int $by = 1): int
    {
        $item = self::read($key);
        $v = (int)($item['v'] ?? 0) + $by;
        $ttl = $item !== null && $item['e'] > 0 ? max(1, $item['e'] - time()) : null;
        self::set($key, $v, $ttl);
        return $v;
    }

    public static function forget(string $key): void
    {
        @unlink(self::file($key));
    }

    /** Очистить весь кеш данных (rig cache:clear). Возвращает число удалённых записей. */
    public static function flush(): int
    {
        $n = 0;
        foreach (glob(Config::storage('cache/data') . '/*.cache') ?: [] as $f) {
            $n += @unlink($f) ? 1 : 0;
        }
        return $n;
    }

    private static function read(string $key): ?array
    {
        $file = self::file($key);
        if (!is_file($file)) {
            return null;
        }
        $item = @unserialize((string)@file_get_contents($file));
        if (!is_array($item) || !array_key_exists('v', $item)) {
            return null;
        }
        if ($item['e'] > 0 && $item['e'] < time()) {
            @unlink($file);
            return null;
        }
        return $item;
    }

    private static function file(string $key): string
    {
        return Config::storage('cache/data') . '/' . sha1($key) . '.cache';
    }
}
