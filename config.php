<?php

/**
 * Настройки приложения. Значения, которые отличаются между машинами (пароли, адреса), хранятся в .env.
 *
 * Режим задаёт переменная окружения RIG_ENV. На сервере ставится RIG_ENV=production
 * (в nginx: fastcgi_param RIG_ENV production; для хаба и очереди: Environment= в systemd).
 * Без неё работает режим разработки: HMR, подробные ошибки, хаб на 127.0.0.1:35730.
 */

$prod = env('RIG_ENV', 'development') === 'production';

return [
    // подробные ошибки, HMR, /_rig/refresh. На продакшене только false.
    'debug' => !$prod,

    // адрес хаба событий для браузера. На продакшене хаб стоит за nginx на том же домене (deploy/nginx.conf).
    // false: события с сервера выключены (тогда rig push не нужен).
    'hub' => $prod ? '/_rig/hub' : null,

    // спекулятивная загрузка страниц: 'conservative' | 'moderate' | 'eager' | false
    'speculation' => 'moderate',

    // сколько секунд браузер держит показанные страницы и открывает их мгновенно; false выключает кеш
    'page_cache' => 600,

    // редактор для кнопки «Открыть в редакторе» на странице ошибки: code, cursor, windsurf, phpstorm, subl.
    // Можно переопределить переменной окружения RIG_EDITOR.
    'editor' => 'code',

    // теги <head> по умолчанию; страница меняет их через $title и Rig\Head::set()
    'head' => [
        'title' => 'rig: реактивный интерфейс на PHP',
        'description' => 'rig: PHP-фреймворк с реактивными компонентами, без JavaScript в приложении.',
        'site' => 'rig',
    ],

    // база данных. managed: rig dev сам запускает PostgreSQL проекта (только для разработки)
    'db' => [
        'default' => env('DB_CONNECTION', 'pgsql'),
        'managed' => !$prod && env('DB_MANAGED', false),
        'connections' => [
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int)env('DB_PORT', 5432),
                'database' => env('DB_DATABASE', 'rig'),
                'username' => env('DB_USERNAME', 'rig'),
                'password' => env('DB_PASSWORD', ''),
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int)env('DB_PORT', 3306),
                'database' => env('DB_DATABASE', 'rig'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
            ],
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => env('DB_DATABASE', 'storage/database.sqlite'),
            ],
        ],
    ],

    // сессии: cookie rig_session, файлы в storage/sessions; lifetime: минуты без активности
    'session' => [
        'lifetime' => 120,
    ],

    // вход: таблица пользователей и куда отправлять гостей со страниц с middleware 'auth'
    'auth' => [
        'table' => 'users',
        'login' => '/login',
        'home' => '/demo/account',
    ],

    // загрузка файлов из форм компонентов: предел размера в мегабайтах
    'uploads' => [
        'max' => 10,
    ],

    // очередь задач: файлы в storage/queue. tries: сколько раз пробовать задачу, прежде чем считать её проваленной
    'queue' => [
        'driver' => 'file',
        'tries' => 3,
    ],

    'mail' => [
        'driver' => env('MAIL_DRIVER', 'log'),
        'host' => env('MAIL_HOST', ''),
        'port' => (int)env('MAIL_PORT', 587),
        'encryption' => env('MAIL_ENCRYPTION', 'tls'),
        'username' => env('MAIL_USERNAME', ''),
        'password' => env('MAIL_PASSWORD', ''),
        'from' => ['address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'), 'name' => env('MAIL_FROM_NAME', 'rig')],
    ],

    'log' => [
        'level' => env('LOG_LEVEL', $prod ? 'info' : 'debug'),
    ],

    // языки интерфейса: файлы lang/{язык}.php. Язык выбирается по cookie, затем по Accept-Language
    'i18n' => [
        'locales' => ['ru', 'en'],
        'default' => 'ru',
    ],

    // middleware для каждого запроса (страницы, действия, API): имена из app/middleware или встроенные
    'middleware' => [],
];
