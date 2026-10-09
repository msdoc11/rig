<?php

declare(strict_types=1);

namespace Rig\Attr;

/**
 * На свойстве задаёт выражение, которое считается и на сервере, и в браузере:
 *   #[Computed('count * 2')] public int $double;
 * На методе без параметров значение считает только сервер (например, запрос в БД).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class Computed
{
    public function __construct(public readonly ?string $expr = null)
    {
    }
}
