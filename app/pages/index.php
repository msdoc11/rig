<?php

// Главная. Лендинг сайта лежит в app/landing и в репозиторий не входит; без него открываем демо.
if (is_file(dirname(__DIR__) . '/landing/index.php')) {
    require dirname(__DIR__) . '/landing/index.php';
    return;
}
redirect('/demo');
