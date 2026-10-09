<?php
// только администраторам: гостя отправит на вход, остальным ответит 403
Rig\Page::middleware('role:admin');
$title = 'Пользователи — rig';
$layout = 'demo';
$users = App\Models\User::query()->orderBy('id')->get();
?>
<section class="intro">
  <h1>Пользователи</h1>
  <p>Страница закрыта middleware <code>role:admin</code>. Список берётся из таблицы <code>users</code> в PostgreSQL.</p>
</section>
<table class="users">
  <thead><tr><th>id</th><th>Имя</th><th>Email</th><th>Роль</th><th>Задач</th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= (int)$u->id ?></td>
      <td><?= htmlspecialchars($u->name) ?></td>
      <td><?= htmlspecialchars($u->email) ?></td>
      <td><?= htmlspecialchars($u->role) ?></td>
      <td><?= $u->todos()->count() ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<style>
  .users { border-collapse: collapse; width: 100%; background: var(--card); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
  .users th, .users td { text-align: left; padding: 10px 14px; border-bottom: 1px solid var(--line); }
  .users th { color: var(--muted); font-weight: 600; font-size: 14px; }
</style>
