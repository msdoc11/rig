<?php

declare(strict_types=1);

namespace App\Components;

use Rig\Attr\Shared;
use Rig\Component;

/** Корзина: то же #[Shared('cart')], что у ShopShelf, поэтому оба компонента показывают одно значение. */
final class MiniCart extends Component
{
    #[Shared('cart', persist: true)]
    public array $cart = [];
}
?>

<template>
  <Card :title="t('demo.cart')">
    <p class="count">{{ t('demo.items', {n: len(cart)}) }}</p>
    <ul r-if="cart">
      <li r-for="(item, i) in cart" :key="item + i" r-transition="slide">{{ item }}
        <button class="link" @click="cart = without(cart, item)" aria-label="Убрать">×</button>
      </li>
    </ul>
    <p r-else class="empty">{{ t('demo.empty') }}</p>
    <template #footer>
      <span>#[Shared('cart', persist: true)]</span>
      <button class="link" r-show="cart" @click="cart = []">Очистить</button>
    </template>
  </Card>
</template>

<style>
  .count { font-size: 22px; font-weight: 650; margin: 0 0 8px; }
  ul { list-style: none; margin: 0; padding: 0; }
  li { display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid var(--line); }
  .empty { color: var(--muted); margin: 0; }
</style>
