<?php

declare(strict_types=1);

namespace Rig\Attr;

/** Состояние живёт только в браузере: меняется мгновенно и не уходит на сервер. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Client
{
}
