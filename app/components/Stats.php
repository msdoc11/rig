<?php

declare(strict_types=1);

namespace App\Components;

use Rig\Attr\Action;
use Rig\Attr\Client;
use Rig\Component;

/**
 * Свой JS там, где он нужен: график рисует app/js/sparkline.js (canvas), данные приходят из PHP.
 * Новые точки уходят в модуль через update(), клик по графику возвращается событием @pick.
 */
final class Stats extends Component
{
    public array $points = [3, 5, 4, 7, 6, 9, 8, 11];

    #[Client]
    public ?int $picked = null;

    #[Action]
    public function addPoint(): void
    {
        $last = (int)end($this->points);
        $this->points[] = max(1, $last + random_int(-3, 4));
        $this->points = array_slice($this->points, -16);
    }
}
?>

<template>
  <Card title="r-js: свой JS для графика">
    <div class="chart" r-js="sparkline" r-props="{points: points}" @pick="picked = $event.detail">
      <span class="fallback">График загружается…</span>
    </div>
    <p class="meta">{{ picked === null ? 'Нажмите на точку графика' : 'Выбрана точка со значением ' + picked }}</p>
    <button @click="addPoint" :disabled="$busy">Добавить точку с сервера</button>
  </Card>
</template>

<style>
  .chart { height: 90px; border-radius: 10px; background: var(--chip); position: relative; }
  .fallback { position: absolute; inset: 0; display: grid; place-items: center; color: var(--muted); font-size: 14px; }
  .meta { color: var(--muted); font-size: 14px; margin: 10px 0; }
</style>
