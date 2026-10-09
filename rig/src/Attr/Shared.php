<?php

declare(strict_types=1);

namespace Rig\Attr;

/**
 * Общее состояние в браузере: все компоненты с одинаковым ключом видят одно значение.
 * Меняет один, обновляются все. Живёт, пока открыта вкладка (переходы без перезагрузки его не сбрасывают);
 * с persist: true сохраняется и после перезагрузки (localStorage).
 *
 *   #[Shared('cart')]
 *   public array $cart = [];
 *
 *   #[Shared('theme', persist: true)]
 *   public string $theme = 'light';
 *
 * Как и #[Client], на сервер не уходит. Данные, которые нужны PHP, храните в сессии или базе.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Shared
{
    public function __construct(public readonly string $key, public readonly bool $persist = false)
    {
    }
}
