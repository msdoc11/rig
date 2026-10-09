<?php

declare(strict_types=1);

namespace Rig;

/**
 * Middleware: проверка перед страницей, действием компонента или API-маршрутом.
 * Остановить запрос можно через redirect('/login'), abort(403) или любое исключение.
 *
 *   // app/middleware/Admin.php
 *   namespace App\Middleware;
 *
 *   final class Admin implements \Rig\Middleware
 *   {
 *       public function handle(\Rig\Request $request, string ...$args): void
 *       {
 *           if (!\Rig\Auth::hasRole('admin')) abort(403);
 *       }
 *   }
 *
 * Подключение: на странице Page::middleware('admin'); на компоненте #[Guard('admin')];
 * в API через 'middleware' => ['admin']; для всех запросов через 'middleware' в config.php.
 *
 * Встроенные: auth (только вошедшие), guest (только гости), role:admin,editor, throttle:60,1 (60 запросов в минуту).
 */
interface Middleware
{
    public function handle(Request $request, string ...$args): void;
}
