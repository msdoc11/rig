<?php

declare(strict_types=1);

namespace Rig\Attr;

/**
 * Свойство для r-model на компоненте: <Rating r-model="score" />.
 * Ставится вместе с #[Prop]; присваивание этому свойству в шаблоне компонента уходит родителю.
 *
 *   #[Prop, Model]
 *   public int $value = 0;
 *
 *   <button r-for="n in [1, 2, 3, 4, 5]" :key="n" @click="value = n">★</button>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Model
{
}
