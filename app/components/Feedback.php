<?php

declare(strict_types=1);

namespace App\Components;

use Rig\Attr\Action;
use Rig\Component;

/** Родитель для <Rating r-model="score" />. Оценка хранится в серверном свойстве и уходит с действием. */
final class Feedback extends Component
{
    public int $score = 3;
    public string $saved = '';

    #[Action]
    public function save(): void
    {
        $this->saved = "Сервер получил оценку {$this->score} из 5";
    }
}
?>

<template>
  <Card title="r-model на своём компоненте">
    <Rating r-model="score" />
    <p class="meta">Оценка в родителе: <b>{{ score }}</b></p>
    <button @click="save" :disabled="$busy">Отправить на сервер</button>
    <p r-if="saved" r-transition="slide" class="ok">{{ saved }}</p>
  </Card>
</template>

<style>
  .meta { color: var(--muted); margin: 8px 0 12px; }
  .ok { margin: 10px 0 0; color: #2e8b57; }
</style>
