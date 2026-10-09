<?php

declare(strict_types=1);

namespace App\Components;

use Rig\Attr\Model;
use Rig\Attr\Prop;
use Rig\Component;

/**
 * Оценка звёздами. Своё поле ввода с r-model: <Rating r-model="score" />.
 * Присваивание value в шаблоне уходит родителю, его выражение обновляется.
 */
final class Rating extends Component
{
    public const TAG = 'span';

    #[Prop, Model]
    public int $value = 0;

    #[Prop]
    public int $max = 5;
}
?>

<template>
  <span class="stars" role="radiogroup" aria-label="Оценка">
    <button r-for="n in slice([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 0, max)" :key="n" type="button"
            role="radio" :aria-checked="n == value ? 'true' : 'false'" :aria-label="n"
            :class="{on: n <= value}" @click="value = n">★</button>
  </span>
</template>

<style>
  .stars { display: inline-flex; gap: 2px; }
  button { background: none; border: 0; min-height: 0; padding: 0 2px; font-size: 26px; line-height: 1; color: var(--line); cursor: pointer; }
  button.on { color: #f0a202; }
  button:hover { transform: scale(1.15); }
</style>
