<?php
$title = 'Демо: интерфейс — rig';
$layout = 'demo';
Rig\Head::set(description: 'Демо rig: r-model на компонентах, общее состояние, debounce, анимации, свой JS и переводы');
?>
<section class="intro">
  <h1>Интерфейс</h1>
  <p>Свои поля ввода с r-model, общее состояние компонентов, debounce и throttle, анимации появления,
    график на своём JS и переводы. Компоненты описаны в PHP и шаблонах.</p>
</section>
<div class="grid ui-grid">
  <Feedback />
  <ShopShelf />
  <MiniCart />
  <SearchBox />
  <Motion />
  <Stats />
  <LangSwitch />
</div>
<style>
  .ui-grid { grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
</style>
