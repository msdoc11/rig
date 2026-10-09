<?php

declare(strict_types=1);

namespace Rig\Attr;

/** Метод вызывается, когда другой компонент сделал $this->dispatch('имя', $data). */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class On
{
    public function __construct(public readonly string $event)
    {
    }
}
