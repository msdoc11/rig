<?php

declare(strict_types=1);

namespace App\Components;

use Rig\Attr\Client;
use Rig\Attr\Prop;
use Rig\Component;

/**
 * Строка списка задач. Данные получает от родителя через #[Prop],
 * а о действиях пользователя сообщает ему через emit(): родитель решает, что делать.
 */
final class TodoItem extends Component
{
    public const TAG = 'li';

    #[Prop]
    public array $todo = [];

    /** Режим переименования живёт только в браузере. */
    #[Client]
    public bool $editing = false;

    #[Client]
    public string $draft = '';
}
?>

<template>
  <label r-if="!editing">
    <input type="checkbox" :checked="todo.done" @change="emit('toggle', $event.target.checked)">
    <span :class="{done: todo.done}" @dblclick="draft = todo.title; editing = true" title="Дважды щёлкните, чтобы переименовать">{{ todo.title }}</span>
  </label>
  <input r-else class="edit" r-model="draft" autofocus aria-label="Новое название"
         @keydown.enter="emit('rename', draft); editing = false"
         @keydown.esc="editing = false"
         @blur="editing = false">
  <button class="del" @click="emit('remove')" aria-label="Удалить">×</button>
</template>

<style>
  label { flex: 1; display: flex; align-items: center; gap: 10px; cursor: pointer; min-width: 0; }
  span.done { color: var(--muted); text-decoration: line-through; }
  .edit { flex: 1; min-width: 0; }
  .del { background: none; border: 0; color: var(--muted); font-size: 20px; width: 32px; height: 32px; min-height: 0; padding: 0; }
  .del:hover { color: var(--danger); }
</style>
