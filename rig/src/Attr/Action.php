<?php

declare(strict_types=1);

namespace Rig\Attr;

/**
 * Метод можно вызывать из шаблона: @click="increment" или @click="remove(t.id)".
 * optimistic: выражение, которое браузер применит сразу, не дожидаясь сервера:
 *   #[Action(optimistic: 'count = count + 1')]
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Action
{
    public function __construct(public readonly ?string $optimistic = null)
    {
    }
}
