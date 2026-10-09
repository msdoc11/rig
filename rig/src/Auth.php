<?php

declare(strict_types=1);

namespace Rig;

/**
 * Вход пользователей. Таблица задаётся в 'auth.table' (users), колонки: email, password (хеш), role.
 *
 *   if (Auth::attempt(['email' => $this->email, 'password' => $this->password])) redirect('/');
 *   Auth::user();          // ['id' => 1, 'email' => …, 'role' => 'admin'] без пароля, или null
 *   Auth::hasRole('admin');
 *   Auth::logout();
 *
 * Пароли хешируются Auth::hash() (password_hash, bcrypt/argon2). Подбор пароля ограничен:
 * 5 неудачных попыток в минуту для одного email и IP, затем 429.
 */
final class Auth
{
    private const KEY = '_auth';
    private static array|false|null $user = null;
    private static bool $changed = false;

    /** В этом запросе был вход или выход: layout (меню, имя пользователя) нужно перерисовать целиком. */
    public static function changed(): bool
    {
        return self::$changed;
    }

    /** Проверить email и пароль; при успехе войти. Остальные поля, кроме password, должны совпасть. */
    public static function attempt(array $credentials): bool
    {
        $password = (string)($credentials['password'] ?? '');
        unset($credentials['password']);
        if ($credentials === []) {
            throw new \InvalidArgumentException('Auth::attempt(): нужен хотя бы один признак пользователя, например email');
        }
        $throttle = 'auth:' . sha1(json_encode($credentials) . '|' . Request::current()->ip());
        if ((int)Cache::get($throttle, 0) >= 5) {
            throw new HttpError(429, 'Слишком много попыток входа. Подождите минуту.');
        }
        $row = DB::table(self::table())->where($credentials)->first();
        if (!is_array($row) || !is_string($row['password'] ?? null) || !password_verify($password, $row['password'])) {
            Cache::increment($throttle);
            if (Cache::get($throttle) === 1) {
                Cache::set($throttle, 1, 60);
            }
            return false;
        }
        Cache::forget($throttle);
        if (password_needs_rehash($row['password'], PASSWORD_DEFAULT)) {
            DB::table(self::table())->where('id', $row['id'])->update(['password' => self::hash($password)]);
        }
        self::login($row);
        return true;
    }

    /** Войти без пароля: после регистрации, по ссылке из письма. */
    public static function login(array|Db\Model|int|string $user): void
    {
        $id = is_array($user) ? ($user['id'] ?? null) : ($user instanceof Db\Model ? $user->getKey() : $user);
        if ($id === null) {
            throw new \InvalidArgumentException('Auth::login(): у пользователя нет id');
        }
        Session::regenerate();
        Session::put(self::KEY, $id);
        self::$user = null;
        self::$changed = true;
    }

    public static function logout(): void
    {
        Session::invalidate();
        self::$user = false;
        self::$changed = true;
    }

    /** Текущий пользователь без пароля, или null. */
    public static function user(): ?array
    {
        if (self::$user === null) {
            $id = Session::get(self::KEY);
            $row = $id === null ? null : DB::table(self::table())->where('id', $id)->first();
            self::$user = is_array($row) ? array_diff_key($row, ['password' => 1, 'remember_token' => 1]) : false;
        }
        return self::$user === false ? null : self::$user;
    }

    public static function id(): int|string|null
    {
        return self::user()['id'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function guest(): bool
    {
        return self::user() === null;
    }

    /** Роль пользователя берётся из колонки role (строка) или roles (JSON-список или через запятую). */
    public static function hasRole(string ...$roles): bool
    {
        $u = self::user();
        if ($u === null) {
            return false;
        }
        $have = [];
        if (isset($u['role'])) {
            $have[] = (string)$u['role'];
        }
        if (isset($u['roles'])) {
            $r = is_string($u['roles']) ? (json_decode($u['roles'], true) ?? explode(',', $u['roles'])) : (array)$u['roles'];
            array_push($have, ...array_map(static fn ($x) => trim((string)$x), $r));
        }
        return array_intersect($roles, $have) !== [];
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /** Для тестов и долгих процессов. */
    public static function forgetUser(): void
    {
        self::$user = null;
    }

    private static function table(): string
    {
        return (string)Config::get('auth.table', 'users');
    }
}
