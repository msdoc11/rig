<?php

// /docs/state, /docs/actions …: текст раздела из app/docs/{slug}.md; на неизвестный раздел ответит 404
$doc = App\Docs::page($params['slug']);
$title = $doc['title'] . ' — документация rig';
require dirname(__DIR__, 2) . '/views/docs.php';
