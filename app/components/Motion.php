<?php

declare(strict_types=1);

namespace App\Components;

use Rig\Attr\Client;
use Rig\Component;

/** r-transition: появление и исчезновение для r-if, r-show и элементов списка. */
final class Motion extends Component
{
    #[Client]
    public bool $open = false;

    #[Client]
    public bool $note = true;

    #[Client]
    public array $tags = ['PHP', 'PostgreSQL'];

    #[Client]
    public int $n = 0;
}
?>

<template>
  <Card title="r-transition">
    <div class="row">
      <button @click="open = !open">{{ open ? 'Скрыть панель' : 'Показать панель' }} (r-if, scale)</button>
      <button @click="note = !note">Заметка (r-show, fade)</button>
    </div>
    <div r-if="open" r-transition="scale" class="panel">Панель появилась с анимацией scale и так же исчезнет.</div>
    <p r-show="note" r-transition="fade" class="note">Эта заметка скрывается через r-show, элемент при этом остаётся в DOM.</p>
    <div class="row">
      <button @click="n = n + 1; tags = push(tags, 'Тег ' + n)">Добавить тег (slide)</button>
    </div>
    <div class="tags">
      <span r-for="t in tags" :key="t" r-transition="slide" class="tag" @click="tags = without(tags, t)" title="Убрать">{{ t }} ×</span>
    </div>
  </Card>
</template>

<style>
  .row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
  .panel { padding: 14px; border-radius: 10px; background: var(--chip); margin-bottom: 12px; }
  .note { margin: 0 0 12px; color: var(--muted); }
  .tags { display: flex; flex-wrap: wrap; gap: 6px; }
  .tag { padding: 4px 10px; border-radius: 999px; background: var(--chip); cursor: pointer; }
</style>
