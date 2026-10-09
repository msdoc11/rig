<?php
// гостям показывается форма входа, вошедших middleware 'guest' отправит на главную
Rig\Page::middleware('guest');
$title = 'Вход — rig';
$layout = 'demo';
$next = (string)($_GET['next'] ?? '/demo/account');
?>
<section class="intro">
  <h1>Вход</h1>
  <p>Демо-пользователи: <code>admin@rig.local</code> и <code>anna@rig.local</code>, пароль <code>rig</code>.
    После входа очередь отправляет письмо-уведомление, его текст появится в <code>storage/logs/mail.log</code>.</p>
</section>
<LoginForm next="<?= htmlspecialchars($next, ENT_QUOTES) ?>" />
