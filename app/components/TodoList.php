<?php

declare(strict_types=1);

namespace App\Components;

use App\ActivityLog;
use App\TodoStore;
use Rig\Attr\Action;
use Rig\Attr\Client;
use Rig\Attr\Computed;
use Rig\Attr\Locked;
use Rig\Attr\On;
use Rig\Component;

final class TodoList extends Component
{
    /** Список приходит из хранилища. Locked: подменить его из браузера нельзя. */
    #[Locked]
    public array $todos = [];

    public string $title = '';

    /** Вкладки фильтра работают только в браузере, без запросов. */
    #[Client]
    public string $filter = 'all';

    #[Computed("filter == 'open' ? where(todos, 'done', false) : (filter == 'done' ? where(todos, 'done', true) : todos)")]
    public array $visible;

    #[Computed("where(todos, 'done', false) | len")]
    public int $left;

    public function mount(): void
    {
        $this->todos = TodoStore::all();
    }

    #[Action]
    public function add(): void
    {
        $this->title = trim($this->title);
        $this->validate(['title' => 'required|min:3|max:80']);
        TodoStore::add($this->title);
        $this->changed("Добавлена задача «{$this->title}»");
        $this->title = '';
    }

    #[Action]
    public function setDone(int $id, bool $done): void
    {
        $todo = TodoStore::find($id);
        TodoStore::setDone($id, $done);
        $this->changed($todo ? ($done ? 'Выполнена' : 'Снова в работе') . " «{$todo['title']}»" : null);
    }

    #[Action]
    public function rename(int $id, string $title): void
    {
        $todo = TodoStore::find($id);
        $title = trim($title);
        if ($todo && $title !== '' && $title !== $todo['title']) {
            TodoStore::rename($id, $title);
            $this->changed("Переименована: «{$todo['title']}» теперь «{$title}»");
            return;
        }
        $this->changed();
    }

    #[Action(optimistic: "todos = whereNot(todos, 'id', id)")]
    public function remove(int $id): void
    {
        $todo = TodoStore::find($id);
        TodoStore::remove($id);
        $this->changed($todo ? "Удалена задача «{$todo['title']}»" : null);
    }

    #[Action(optimistic: "todos = where(todos, 'done', false)")]
    public function clearDone(): void
    {
        $count = count(array_filter(TodoStore::all(), fn (array $t) => $t['done']));
        TodoStore::clearDone();
        $this->changed($count ? "Убраны готовые задачи: {$count}" : null);
    }

    /** Кто-то изменил список в другой вкладке или с другого компьютера. */
    #[On('todos-changed')]
    public function reload(): void
    {
        $this->todos = TodoStore::all();
    }

    /** Список поменялся: перечитать, сообщить другим вкладкам и записать в журнал событий. */
    private function changed(?string $activity = null): void
    {
        $this->todos = TodoStore::all();
        $this->broadcast('todos-changed');
        if ($activity !== null) {
            ActivityLog::add($activity);
            $this->dispatch('activity');   // ленте на этой странице
            $this->broadcast('activity');  // и во всех остальных вкладках
        }
    }
}
?>

<template>
  <Card title="Задачи">
    <form class="add" @submit.prevent="add">
      <input r-model="title" placeholder="Что сделать?" aria-label="Новая задача" :aria-invalid="$errors.title ? 'true' : null">
      <button :disabled="title == ''">Добавить</button>
    </form>
    <p r-if="$errors.title" class="error">{{ $errors.title }}</p>

    <nav class="tabs">
      <button r-for="f in ['all', 'open', 'done']" :key="f" :class="{active: filter == f}" @click="filter = f">
        {{ f == 'all' ? 'Все' : (f == 'open' ? 'Активные' : 'Готовые') }}
      </button>
    </nav>

    <ul>
      <TodoItem r-for="t in visible" :key="t.id" :todo="t"
                @toggle="t.done = $event; setDone(t.id, $event)"
                @rename="t.title = $event; rename(t.id, $event)"
                @remove="remove(t.id)" />
      <li r-if="len(visible) == 0" class="empty">Здесь пусто</li>
    </ul>

    <template #footer>
      <span>Осталось {{ left }} {{ left | plural('задача', 'задачи', 'задач') }}</span>
      <button class="link" r-show="len(todos) > left" @click="clearDone">Убрать готовые</button>
    </template>
  </Card>
</template>

<style>
  .add { display: flex; gap: 8px; }
  .add input { flex: 1; min-width: 0; }
  .add input[aria-invalid="true"] { border-color: var(--danger); }
  .error { color: var(--danger); margin: 6px 0 0; font-size: 14px; }
  .tabs { display: flex; gap: 4px; margin: 16px 0 8px; }
  .tabs button { background: none; border-color: transparent; color: var(--muted); padding: 4px 10px; }
  .tabs button.active { background: var(--chip); color: var(--text); }
  ul { list-style: none; margin: 0; padding: 0; }
  li { display: flex; align-items: center; gap: 8px; min-height: 48px; padding: 4px 0; border-bottom: 1px solid var(--line); }
  li.empty { color: var(--muted); justify-content: center; }
</style>
