<?php

declare(strict_types=1);

namespace Rig;

/**
 * Защита от подделки запросов (CSRF). Токен живёт в сессии; rig.js отправляет его с каждым действием
 * в заголовке X-CSRF-Token, обычная HTML-форма отправляет его полем _token (csrf_field()).
 * Проверяются: действия компонентов, загрузки файлов, POST/PUT/PATCH/DELETE страниц и API из браузера.
 */
final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_token');
        if (!is_string($t) || strlen($t) !== 64) {
            $t = bin2hex(random_bytes(32));
            Session::put('_token', $t);
        }
        return $t;
    }

    /** Токен из заголовка или поля формы совпадает с токеном сессии. */
    public static function valid(?string $given): bool
    {
        $t = Session::get('_token');
        return is_string($t) && $t !== '' && is_string($given) && hash_equals($t, $given);
    }

    /** 419, если токен не совпал. */
    public static function verify(Request $request): void
    {
        $given = $request->header('X-CSRF-Token') ?? $request->input('_token');
        if (!self::valid(is_string($given) ? $given : null)) {
            throw new HttpError(419, 'Страница устарела: обновите её и повторите действие');
        }
    }
}
