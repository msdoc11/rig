<?php

declare(strict_types=1);

namespace Rig\Attr;

/**
 * Свойство задаёт родитель: <TodoItem :todo="t" />. Внутри компонента его можно только читать.
 * Значение приходит из браузера вместе с каждым действием, поэтому проверяйте его, как любой ввод.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Prop
{
}
