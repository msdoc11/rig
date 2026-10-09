<?php

declare(strict_types=1);

namespace App\Components;

use App\ActivityLog;
use Rig\Attr\Locked;
use Rig\Attr\On;
use Rig\Component;

/**
 * Лента событий. Стоит в layout, поэтому переживает переходы между страницами.
 * Записи лежат на сервере (ActivityLog), поэтому видны после перезагрузки и во всех вкладках.
 * Обновляется по событию activity: и с этой страницы (dispatch), и из других вкладок (broadcast).
 */
final class ActivityFeed extends Component
{
    #[Locked]
    public array $items = [];

    public function mount(): void
    {
        $this->items = ActivityLog::recent();
    }

    #[On('activity')]
    public function reload(): void
    {
        $this->items = ActivityLog::recent();
    }
}
?>

<template>
  <Card title="События">
    <ol r-if="items">
      <li r-for="e in items" :key="e.id"><time>{{ e.at }}</time> {{ e.text }}</li>
    </ol>
    <p r-else class="empty">Событий пока нет. Добавьте или отметьте задачу: запись появится здесь и во всех открытых вкладках.</p>
  </Card>
</template>

<style>
  ol { list-style: none; margin: 0; padding: 0; }
  li { padding: 6px 0; border-bottom: 1px solid var(--line); }
  li:last-child { border-bottom: 0; }
  time { color: var(--muted); font-variant-numeric: tabular-nums; margin-right: 6px; }
  .empty { color: var(--muted); margin: 0; }
</style>
