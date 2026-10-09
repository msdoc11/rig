<?php

/**
 * Страница «не найдено». Обычная страница: работает layout, компоненты и переходы без перезагрузки.
 * Доступны $status и $message.
 */
$title = 'Страница не найдена — rig';
?>
<section class="section error-page">
  <div class="wrap">
    <p class="error-code">404</p>
    <h1>Такой страницы нет</h1>
    <p>Возможно, ссылка устарела или в адресе опечатка.</p>
    <div class="hero-actions">
      <a class="btn btn-primary" href="/">На главную</a>
      <a class="btn btn-ghost" href="/docs">Документация</a>
    </div>
  </div>
</section>
