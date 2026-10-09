<?php
// только для вошедших: гостя отправит на /login?next=/demo/account
Rig\Page::middleware('auth');
$title = 'Кабинет — rig';
$layout = 'demo';
Rig\Head::set(description: 'Личный кабинет демо rig: данные из PostgreSQL, загрузка аватара', robots: false);
?>
<section class="intro">
  <h1>Кабинет</h1>
  <p>Данные пользователя берутся из PostgreSQL. Картинка уходит на сервер сразу после выбора, превью видно до сохранения.</p>
</section>
<div class="grid">
  <Profile />
  <?php if (Rig\Auth::hasRole('admin')): ?>
    <p class="hint">Вы администратор, вам доступна <a href="/demo/admin">страница пользователей</a>.</p>
  <?php endif ?>
</div>
