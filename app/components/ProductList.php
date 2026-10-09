<?php

declare(strict_types=1);

namespace App\Components;

use App\Catalog;
use Rig\Attr\Action;
use Rig\Attr\Client;
use Rig\Attr\Computed;
use Rig\Attr\Lazy;
use Rig\Attr\Locked;
use Rig\Component;

/**
 * Каталог с ленивой загрузкой: страница открывается сразу со скелетоном,
 * а товары приходят следом, и медленная выборка не задерживает переход.
 */
#[Lazy]
final class ProductList extends Component
{
    #[Locked]
    public array $items = [];

    #[Locked]
    public int $page = 1;

    #[Locked]
    public bool $hasMore = false;

    /** Фильтр по категории работает в браузере, без запросов. */
    #[Client]
    public string $category = 'Все';

    #[Computed("category == 'Все' ? items : where(items, 'category', category)")]
    public array $visible;

    public function mount(): void
    {
        usleep(1_200_000); // имитация медленной БД
        [$this->items, $this->hasMore] = Catalog::page(1);
    }

    /**
     * Страницу открыли из кеша браузера, а данные могли поменяться: перечитать столько же
     * страниц каталога, сколько уже показано, чтобы список не сжался обратно до первых девяти.
     */
    public function revalidate(): void
    {
        $items = [];
        for ($p = 1; $p <= $this->page; $p++) {
            [$chunk, $this->hasMore] = Catalog::page($p);
            $items = [...$items, ...$chunk];
        }
        $this->items = $items;
    }

    #[Action]
    public function more(): void
    {
        usleep(800_000);
        $this->page++;
        [$next, $this->hasMore] = Catalog::page($this->page);
        $this->items = [...$this->items, ...$next];
    }
}
?>

<template>
  <nav class="cats" aria-label="Категории">
    <button r-for="c in ['Все', 'Кофе', 'Чай', 'Посуда']" :key="c"
            :class="{active: category == c}" :aria-pressed="category == c ? 'true' : 'false'"
            @click="category = c">{{ c }}</button>
  </nav>

  <ul class="grid">
    <li r-for="p in visible" :key="p.id" class="product">
      <div class="pic" :style="{background: 'hsl(' + p.hue + ' 45% 82%)'}">{{ slice(p.name, 0, 1) }}</div>
      <span class="cat">{{ p.category }}</span>
      <span class="name">{{ p.name }}</span>
      <span class="price">{{ p.price }} ₽</span>
    </li>
    <li r-for="i in ($pending.more ? [1, 2, 3] : [])" :key="'sk' + i" class="product" aria-hidden="true">
      <div class="r-sk pic"></div>
      <div class="r-sk r-sk-line" style="width: 40%"></div>
      <div class="r-sk r-sk-line" style="width: 80%"></div>
    </li>
  </ul>

  <p r-if="len(visible) == 0" class="empty">В этой категории пока ничего не загружено</p>

  <button r-if="hasMore" class="more" @click="more" :disabled="$busy">
    {{ $pending.more ? 'Загружаем…' : 'Показать ещё' }}
  </button>
</template>

<!-- то, что видно, пока идёт mount(): повторяет раскладку каталога -->
<skeleton>
  <div class="cats" aria-hidden="true">
    <div r-for="i in [1, 2, 3, 4]" :key="i" class="r-sk" style="width: 72px; height: 32px"></div>
  </div>
  <ul class="grid" aria-hidden="true">
    <li r-for="i in [1, 2, 3, 4, 5, 6]" :key="i" class="product">
      <div class="r-sk pic"></div>
      <div class="r-sk r-sk-line" style="width: 40%"></div>
      <div class="r-sk r-sk-line" style="width: 80%"></div>
    </li>
  </ul>
  <span class="visually-hidden">Загружаем товары…</span>
</skeleton>

<style>
  .cats { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; }
  .cats button { background: none; border-color: var(--line); }
  .cats button.active { background: var(--text); color: var(--bg); border-color: var(--text); }
  .grid { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; }
  .product { display: flex; flex-direction: column; gap: 2px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 12px; min-height: 230px; }
  .pic { height: 130px; border-radius: 8px; margin-bottom: 8px; display: grid; place-items: center; font-size: 40px; font-weight: 600; color: rgba(0, 0, 0, .35); }
  .cat { color: var(--muted); font-size: 13px; }
  .name { font-weight: 550; }
  .price { margin-top: auto; padding-top: 6px; font-variant-numeric: tabular-nums; }
  .empty { color: var(--muted); }
  .more { margin-top: 16px; width: 100%; }
  .visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
</style>
