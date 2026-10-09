<?php

declare(strict_types=1);

namespace App\Components;

use Rig\Attr\Shared;
use Rig\Component;

/** Витрина: кладёт товары в общую корзину #[Shared('cart')]; MiniCart обновляется сам, без событий. */
final class ShopShelf extends Component
{
    #[Shared('cart', persist: true)]
    public array $cart = [];
}
?>

<template>
  <Card title="#[Shared]: общее состояние">
    <div class="shelf">
      <button r-for="p in ['Кофе', 'Чай', 'Кружка', 'Френч-пресс']" :key="p" @click="cart = push(cart, p)"
              :class="{in: includes(cart, p)}">{{ p }} +</button>
    </div>
    <p class="hint">Корзина рядом сделана отдельным компонентом. Её содержимое хранится в браузере и синхронизируется между вкладками.</p>
  </Card>
</template>

<style>
  .shelf { display: flex; flex-wrap: wrap; gap: 8px; }
  .shelf .in { border-color: var(--accent); }
  .hint { color: var(--muted); font-size: 14px; margin: 12px 0 0; }
</style>
