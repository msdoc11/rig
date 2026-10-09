<?php

declare(strict_types=1);

namespace App\Components;

use App\Catalog;
use Rig\Attr\Client;
use Rig\Attr\Computed;
use Rig\Component;

/**
 * Поиск: r-model.live.debounce.400ms отправляет запрос к серверу после паузы в наборе, а не на каждую букву.
 * Кнопка «+1» с @click.throttle.1s срабатывает не чаще раза в секунду, сколько ни нажимай.
 */
final class SearchBox extends Component
{
    public string $q = '';

    #[Client]
    public int $clicks = 0;

    #[Computed]
    public function found(): array
    {
        return Catalog::search($this->q);
    }

    /** Когда сервер последний раз отвечал. По нему видно, что на паузу в наборе приходится один запрос. */
    #[Computed]
    public function answered(): string
    {
        return date('H:i:s');
    }
}
?>

<template>
  <Card title="debounce и throttle">
    <input r-model.live.debounce.400ms="q" placeholder="Поиск по каталогу: «чай», «кофе»…" aria-label="Поиск">
    <p class="meta">Сервер ответил в {{ answered }}. Запрос уходит один раз, когда в наборе пауза 400 мс.</p>
    <ul>
      <li r-for="p in found" :key="p.id" r-transition="slide">{{ p.name }} · {{ p.price }} ₽</li>
    </ul>
    <p class="row">
      <button @click.throttle.1s="clicks = clicks + 1">+1 (не чаще раза в секунду)</button>
      <span>{{ clicks }}</span>
    </p>
  </Card>
</template>

<style>
  input { width: 100%; min-height: 40px; padding: 6px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: var(--text); font: inherit; }
  .meta { color: var(--muted); font-size: 14px; margin: 8px 0; }
  ul { list-style: none; margin: 0; padding: 0; min-height: 8px; }
  li { padding: 6px 0; border-bottom: 1px solid var(--line); }
  .row { display: flex; gap: 12px; align-items: center; margin: 12px 0 0; }
</style>
