<?php

declare(strict_types=1);

namespace App\Components;

use Rig\Attr\Prop;
use Rig\Component;

/**
 * Карточка со слотами: компонент без состояния и без запросов к серверу.
 *
 *   <Card title="Задачи">
 *     …содержимое…
 *     <template #footer>…подвал…</template>
 *   </Card>
 */
final class Card extends Component
{
    #[Prop]
    public string $title = '';
}
?>

<template>
  <section class="card">
    <h2 r-if="title">{{ title }}</h2>
    <slot></slot>
    <footer r-if="$slots.footer"><slot name="footer"></slot></footer>
  </section>
</template>

<style>
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; }
  h2 { font-size: 15px; margin: 0 0 12px; color: var(--muted); font-weight: 600; }
  footer { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: 12px; color: var(--muted); font-size: 14px; }
</style>
