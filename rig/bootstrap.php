<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Rig\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// .env в корне проекта: до config.php, чтобы там работал env()
Rig\Env::load(dirname(__DIR__) . '/.env');

if (!function_exists('rig')) {
    /** Вставить компонент на страницу: <?= rig('Counter', ['count' => 5]) ?> */
    function rig(string $component, array $props = []): string
    {
        return \Rig\Page::component($component, $props);
    }
}

if (!function_exists('env')) {
    /** Переменная окружения или из .env: env('DB_PASSWORD'), env('APP_DEBUG', false). */
    function env(string $name, mixed $default = null): mixed
    {
        return \Rig\Env::get($name, $default);
    }
}

if (!function_exists('config')) {
    /** Настройка из config.php по ключу с точками: config('mail.from.address'). */
    function config(string $key, mixed $default = null): mixed
    {
        return \Rig\Config::get($key, $default);
    }
}

if (!function_exists('redirect')) {
    /** Перейти на другой адрес. Работает на странице, в действии компонента, в middleware и API. */
    function redirect(string $to, int $status = 302): never
    {
        throw new \Rig\Redirect($to, $status);
    }
}

if (!function_exists('abort')) {
    /** Остановить запрос с HTTP-ошибкой: abort(403, 'Нет доступа'). */
    function abort(int $status, string $message = ''): never
    {
        throw new \Rig\HttpError($status, $message !== '' ? $message : match ($status) {
            401 => 'Нужно войти',
            403 => 'Нет доступа',
            404 => 'Не найдено',
            default => 'Ошибка',
        });
    }
}

if (!function_exists('csrf_token')) {
    /** CSRF-токен текущей сессии. */
    function csrf_token(): string
    {
        return \Rig\Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    /** Скрытое поле с CSRF-токеном для обычной HTML-формы с method="post". */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(\Rig\Csrf::token(), ENT_QUOTES) . '">';
    }
}

if (!function_exists('t')) {
    /** Перевод: t('cart.items', ['n' => 3]). Тот же t() работает в шаблонах компонентов. */
    function t(string $key, array $params = []): string
    {
        return \Rig\I18n::t($key, $params);
    }
}
