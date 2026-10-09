<?php

/**
 * Любая другая ошибка: app/errors/{код}.php не нашлось.
 * В режиме разработки ошибки 5xx показывает страница разработчика с кодом и стеком,
 * на продакшене ('debug' => false) эта. Подробности пишутся в error_log.
 * Доступны $status и $message (текст только для ожидаемых ошибок, например 403).
 */
$title = 'Ошибка — rig';
?>
<section class="section error-page">
  <div class="wrap">
    <p class="error-code"><?= (int)$status ?></p>
    <h1><?= $status >= 500 ? 'Что-то сломалось' : 'Не получилось' ?></h1>
    <p><?= $message !== null ? htmlspecialchars($message) : 'Мы уже знаем о проблеме. Попробуйте обновить страницу чуть позже.' ?></p>
    <div class="hero-actions">
      <a class="btn btn-primary" href="/">На главную</a>
    </div>
  </div>
</section>
