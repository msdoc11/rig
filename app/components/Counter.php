<?php

declare(strict_types=1);

namespace App\Components;

use App\ActivityLog;
use Rig\Attr\Action;
use Rig\Attr\Client;
use Rig\Attr\Computed;
use Rig\Attr\Locked;
use Rig\Component;

final class Counter extends Component
{
    /** Подпись передаётся со страницы: <Counter label="…" />. Из браузера не меняется. */
    #[Locked]
    public string $label = 'Счётчик';

    public int $count = 0;

    /** Связан с полем ввода через r-model и уходит на сервер вместе со следующим действием. */
    public int $step = 1;

    /** Только в браузере: показать или скрыть подсказку можно без запроса. */
    #[Client]
    public bool $help = false;

    /** Считается и на сервере, и в браузере, поэтому обновляется мгновенно. */
    #[Computed('count * 2')]
    public int $double;

    /** Считает только сервер: обновится, когда придёт ответ. */
    #[Computed]
    public function parity(): string
    {
        return $this->count % 2 === 0 ? 'чётное' : 'нечётное';
    }

    /** Сервер нарочно отвечает медленно, а число на экране меняется сразу благодаря optimistic. */
    #[Action(optimistic: 'count = count + step')]
    public function increment(): void
    {
        usleep(400_000);
        $this->count += $this->step;
    }

    #[Action(optimistic: 'count = count - step')]
    public function decrement(): void
    {
        usleep(400_000);
        $this->count -= $this->step;
    }

    #[Action]
    public function reset(): void
    {
        $this->count = 0;
        ActivityLog::add("Сброшен «{$this->label}»");
        $this->dispatch('activity');
        $this->broadcast('activity');
    }
}
?>

<template>
  <Card :title="label">
    <p class="value">{{ count }}</p>
    <p class="meta">×2 = {{ double }} · {{ parity }}</p>

    <div class="row">
      <button @click="decrement" aria-label="Уменьшить">−</button>
      <button @click="increment" aria-label="Увеличить">+</button>
      <button class="ghost" @click="reset" :disabled="count == 0">Сбросить</button>
    </div>

    <label class="step">Шаг <input type="number" min="1" r-model.number="step"></label>

    <button class="link" @click="help = !help">{{ help ? 'Скрыть' : 'Как это работает?' }}</button>
    <p r-show="help" class="help">
      «+» и «−» сразу меняют число в браузере, а сервер отвечает через 0,4 с.
      «×2» тоже меняется сразу, потому что это выражение. Подпись «чётное» считает PHP,
      поэтому она обновляется после ответа сервера.
    </p>
  </Card>
</template>

<style>
  .value { font-size: 56px; font-weight: 650; line-height: 1; margin: 8px 0 4px; font-variant-numeric: tabular-nums; }
  .meta { color: var(--muted); margin: 0 0 16px; }
  .row { display: flex; gap: 8px; flex-wrap: wrap; }
  .row button:not(.ghost) { width: 48px; font-size: 20px; }
  .step { display: flex; align-items: center; gap: 8px; margin: 16px 0 8px; color: var(--muted); }
  .step input { width: 80px; }
  .help { color: var(--muted); font-size: 14px; margin: 8px 0 0; }
</style>
