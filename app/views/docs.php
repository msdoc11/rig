<?php

/**
 * Страница документации: меню разделов, текст, оглавление и «назад/вперёд».
 * Ожидает $doc из App\Docs::page().
 *
 * @var array $doc
 */

use App\Docs;

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wrap docs">
  <details class="docs-nav" open>
    <summary>Разделы документации</summary>
    <nav aria-label="Документация">
      <?php foreach (Docs::NAV as $group => $pages): ?>
        <p class="docs-group"><?= $e($group) ?></p>
        <ul>
          <?php foreach ($pages as $slug => $label): ?>
            <li><a href="/docs<?= $slug === '' ? '' : '/' . $e((string)$slug) ?>"><?= $e($label) ?></a></li>
          <?php endforeach ?>
        </ul>
      <?php endforeach ?>
    </nav>
  </details>

  <article class="prose">
    <?= $doc['html'] ?>
    <nav class="pager" aria-label="Соседние разделы">
      <?php if ($doc['prev']): ?>
        <a class="prev" href="<?= $e($doc['prev']['href']) ?>"><span>Назад</span><strong><?= $e($doc['prev']['title']) ?></strong></a>
      <?php endif ?>
      <?php if ($doc['next']): ?>
        <a class="next" href="<?= $e($doc['next']['href']) ?>"><span>Дальше</span><strong><?= $e($doc['next']['title']) ?></strong></a>
      <?php endif ?>
    </nav>
  </article>

  <?php if (count($doc['toc']) > 1): ?>
    <aside class="toc" aria-label="На этой странице">
      <p>На этой странице</p>
      <ul>
        <?php foreach ($doc['toc'] as $h): ?>
          <li><a href="#<?= $e($h['id']) ?>"><?= $e($h['text']) ?></a></li>
        <?php endforeach ?>
      </ul>
    </aside>
  <?php endif ?>
</div>
