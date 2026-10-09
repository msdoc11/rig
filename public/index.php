<?php

declare(strict_types=1);

// встроенный сервер PHP (php -S): статические файлы отдаёт сам
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/rig/bootstrap.php';

(new Rig\App(dirname(__DIR__), require dirname(__DIR__) . '/config.php'))->handle();
