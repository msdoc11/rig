<?php

declare(strict_types=1);

namespace Rig;

/**
 * Запуск middleware по именам: 'auth', 'role:admin,editor', 'throttle:10,1'; своё имя 'admin' вызывает App\Middleware\Admin.
 */
final class Guards
{
    /** Тип текущего запроса: page (страница), action (действие компонента, JSON), api (API-маршрут). */
    public static string $context = 'page';

    /** @param list<string> $names */
    public static function run(array $names, ?Request $request = null): void
    {
        $request ??= Request::current();
        foreach ($names as $spec) {
            [$name, $args] = array_pad(explode(':', trim((string)$spec), 2), 2, '');
            $args = $args === '' ? [] : array_map('trim', explode(',', $args));
            match ($name) {
                'auth' => self::auth($request),
                'guest' => Auth::check() ? redirect((string)Config::get('auth.home', '/')) : null,
                'role' => self::role($args),
                'throttle' => self::throttle($request, $args),
                default => self::custom($name)->handle($request, ...$args),
            };
        }
    }

    /** Проверить, что имя middleware известно (при компиляции #[Guard]). */
    public static function exists(string $spec): bool
    {
        $name = explode(':', $spec, 2)[0];
        return in_array($name, ['auth', 'guest', 'role', 'throttle'], true) || class_exists(self::className($name));
    }

    private static function auth(Request $request): void
    {
        if (Auth::check()) {
            return;
        }
        $login = (string)Config::get('auth.login', '/login');
        if (self::$context === 'api') {
            throw new HttpError(401, 'Нужно войти');
        }
        // со страницы отправляем на вход и обратно; из действия компонента так же, браузер перейдёт сам
        $back = self::$context === 'page' ? $request->path() : (string)parse_url((string)$request->header('Referer'), PHP_URL_PATH);
        redirect($login . ($back !== '' && $back !== $login ? '?next=' . rawurlencode($back) : ''));
    }

    private static function role(array $roles): void
    {
        if (!$roles) {
            throw new \InvalidArgumentException("Middleware role: укажите роли — 'role:admin'");
        }
        if (Auth::guest()) {
            self::auth(Request::current());
        }
        if (!Auth::hasRole(...$roles)) {
            throw new HttpError(403, 'Нет доступа');
        }
    }

    /** throttle:60,1: не больше 60 запросов за 1 минуту с одного IP (для вошедшего считается по пользователю) на этот адрес. */
    private static function throttle(Request $request, array $args): void
    {
        $max = max(1, (int)($args[0] ?? 60));
        $minutes = max(1, (int)($args[1] ?? 1));
        $who = Auth::id() ?? $request->ip();
        $window = intdiv(time(), $minutes * 60);
        $key = 'throttle:' . sha1($who . '|' . $request->path() . '|' . $window);
        $n = Cache::increment($key);
        if ($n === 1) {
            Cache::set($key, 1, $minutes * 60);
        }
        if ($n > $max) {
            throw new HttpError(429, 'Слишком много запросов. Попробуйте чуть позже.');
        }
    }

    private static function custom(string $name): Middleware
    {
        $class = self::className($name);
        if (!class_exists($class)) {
            throw new \RuntimeException("Неизвестный middleware «{$name}»: нет класса {$class} в app/middleware/");
        }
        $m = new $class();
        if (!$m instanceof Middleware) {
            throw new \RuntimeException("{$class} должен реализовать Rig\\Middleware");
        }
        return $m;
    }

    /** is-owner: App\Middleware\IsOwner */
    private static function className(string $name): string
    {
        return 'App\\Middleware\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
    }
}
