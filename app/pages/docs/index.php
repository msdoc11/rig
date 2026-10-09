<?php

$doc = App\Docs::page('');
$title = $doc['title'] . ' — документация rig';
require dirname(__DIR__, 2) . '/views/docs.php';
