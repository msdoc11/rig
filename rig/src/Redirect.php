<?php

declare(strict_types=1);

namespace Rig;

/**
 * Перенаправление: redirect('/login'). Работает со страницы, из действия компонента
 * (браузер перейдёт сам), из middleware и API.
 */
final class Redirect extends \RuntimeException
{
    public function __construct(public readonly string $to, public readonly int $status = 302)
    {
        parent::__construct("Перенаправление на {$to}");
    }
}
