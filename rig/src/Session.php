<?php

declare(strict_types=1);

namespace Rig;

/**
 * Сессия пользователя: cookie rig_session (HttpOnly, SameSite=Lax, Secure на https),
 * данные лежат в storage/sessions/{id}.json. Без блокировок: параллельные запросы одной вкладки не ждут друг друга.
 *
 *   Session::put('cart', [1, 2]);
 *   Session::get('cart', []);
 *   Session::flash('message', 'Сохранено');   // доступно только в следующем запросе
 *
 * Сессия создаётся, только когда что-то записывают (или нужен CSRF-токен); 'session.lifetime' задаёт минуты без активности.
 */
final class Session
{
    public const COOKIE = 'rig_session';

    private static ?array $data = null;
    private static ?string $id = null;
    private static array $flashNow = [];
    private static bool $dirty = false;
    private static bool $cookie = false;
    private static ?string $drop = null;
    private static int $loadedAt = 0;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return self::$data[$key] ?? self::$flashNow[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        self::start();
        return isset(self::$data[$key]) || isset(self::$flashNow[$key]);
    }

    public static function put(string $key, mixed $value): void
    {
        self::start(true);
        self::$data[$key] = $value;
        self::$dirty = true;
    }

    public static function forget(string $key): void
    {
        self::start();
        if (self::$data !== null && array_key_exists($key, self::$data)) {
            unset(self::$data[$key]);
            self::$dirty = true;
        }
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $v = self::get($key, $default);
        self::forget($key);
        return $v;
    }

    /** Значение только для следующего запроса: сообщения после перенаправления. */
    public static function flash(string $key, mixed $value): void
    {
        self::start(true);
        self::$data['_flash'][$key] = $value;
        self::$dirty = true;
    }

    public static function all(): array
    {
        self::start();
        return array_diff_key((self::$data ?? []) + self::$flashNow, ['_flash' => 1]);
    }

    /** Новый id сессии с теми же данными. Вызывается после входа, чтобы нельзя было подсунуть свой id заранее. */
    public static function regenerate(): void
    {
        self::start(true);
        self::$drop = self::$id;
        self::$id = self::newId();
        self::$cookie = self::$dirty = true;
    }

    /** Очистить сессию полностью (выход). */
    public static function invalidate(): void
    {
        self::start(true);
        self::$drop = self::$id;
        self::$data = [];
        self::$flashNow = [];
        self::$id = self::newId();
        self::$cookie = self::$dirty = true;
    }

    public static function id(): ?string
    {
        self::start();
        return self::$id;
    }

    /** Есть ли у запроса cookie сессии (браузер, а не внешний API-клиент). */
    public static function present(): bool
    {
        return self::valid((string)($_COOKIE[self::COOKIE] ?? ''));
    }

    /**
     * Записать изменения и поставить cookie. Вызывается фреймворком перед отправкой ответа;
     * повторные вызовы безопасны.
     */
    public static function commit(): void
    {
        if (self::$data === null || self::$id === null) {
            return;
        }
        $lifetime = max(1, (int)Config::get('session.lifetime', 120)) * 60;
        // время активности обновляем не чаще раза в минуту, чтобы не писать файл на каждый запрос
        if (self::$dirty || time() - self::$loadedAt > 60) {
            $dir = Config::storage('sessions');
            $file = "{$dir}/" . self::$id . '.json';
            $tmp = $file . '.' . bin2hex(random_bytes(3));
            file_put_contents($tmp, json_encode(['t' => time(), 'd' => self::$data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), LOCK_EX);
            rename($tmp, $file);
            self::$dirty = false;
            self::$loadedAt = time();
            if (random_int(1, 100) <= 2) {
                self::gc($lifetime);
            }
        }
        if (self::$drop !== null) {
            @unlink(Config::path('storage/sessions/' . self::$drop . '.json'));
            self::$drop = null;
        }
        if (self::$cookie && !headers_sent() && PHP_SAPI !== 'cli') {
            setcookie(self::COOKIE, self::$id, [
                'expires' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            ]);
        }
        self::$cookie = false;
    }

    /** Для тестов и долгих процессов: забыть текущую сессию в памяти. */
    public static function reset(): void
    {
        self::$data = self::$id = self::$drop = null;
        self::$flashNow = [];
        self::$dirty = self::$cookie = false;
        self::$loadedAt = 0;
    }

    /** @param bool $create создать новую сессию, если её нет */
    private static function start(bool $create = false): void
    {
        if (self::$data !== null) {
            return;
        }
        $id = (string)($_COOKIE[self::COOKIE] ?? '');
        if (self::valid($id)) {
            $file = Config::path("storage/sessions/{$id}.json");
            $raw = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
            $lifetime = max(1, (int)Config::get('session.lifetime', 120)) * 60;
            if (is_array($raw) && is_array($raw['d'] ?? null) && time() - (int)($raw['t'] ?? 0) <= $lifetime) {
                self::$id = $id;
                self::$data = $raw['d'];
                self::$loadedAt = (int)$raw['t'];
                self::$flashNow = self::$data['_flash'] ?? [];
                if (isset(self::$data['_flash'])) {
                    unset(self::$data['_flash']);
                    self::$dirty = true;
                }
                return;
            }
        }
        if (!$create) {
            return; // читать нечего, а создавать сессию ради чтения не нужно
        }
        self::$id = self::newId();
        self::$data = [];
        self::$cookie = true;
        self::$dirty = true;
    }

    private static function valid(string $id): bool
    {
        return (bool)preg_match('/^[a-f0-9]{40}$/', $id);
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(20));
    }

    private static function gc(int $lifetime): void
    {
        foreach (glob(Config::path('storage/sessions') . '/*.json') ?: [] as $f) {
            if (@filemtime($f) < time() - $lifetime) {
                @unlink($f);
            }
        }
    }
}
