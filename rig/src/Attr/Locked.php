<?php

declare(strict_types=1);

namespace Rig\Attr;

/**
 * Клиент не может прислать новое значение (id, роли, списки из БД).
 * В браузере его всё ещё можно менять локально как optimistic-обновление.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Locked
{
}
