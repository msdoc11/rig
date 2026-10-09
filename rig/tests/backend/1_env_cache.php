<?php

use Rig\Cache;
use Rig\Env;

file_put_contents("{$tmp}/.env", <<<'ENV'
# комментарий
APP_NAME=rig
QUOTED="с пробелами # не комментарий"
SINGLE='$NOT_INTERPOLATED'
REF=${APP_NAME}-app
FLAG=true
NOTHING=null
TAIL=value # комментарий
export EXPORTED=1
ENV);
Env::load("{$tmp}/.env");
check('простое значение', Env::get('APP_NAME') === 'rig');
check('двойные кавычки и # внутри', Env::get('QUOTED') === 'с пробелами # не комментарий', Env::get('QUOTED'));
check('одинарные кавычки как есть', Env::get('SINGLE') === '$NOT_INTERPOLATED');
check('подстановка ${…}', Env::get('REF') === 'rig-app');
check('true читается как true', Env::get('FLAG') === true);
check('null читается как null', Env::get('NOTHING', 'x') === null);
check('комментарий после значения', Env::get('TAIL') === 'value');
check('export', Env::get('EXPORTED') === '1');
check('значение по умолчанию', Env::get('MISSING', 42) === 42);
check('env() из bootstrap', env('APP_NAME') === 'rig');

Cache::set('a', ['x' => 1], 60);
check('кеш: set/get', Cache::get('a') === ['x' => 1]);
check('кеш: remember не пересчитывает', Cache::remember('a', 60, fn () => 'новое') === ['x' => 1]);
$calls = 0;
Cache::remember('b', 60, function () use (&$calls) { $calls++; return 5; });
Cache::remember('b', 60, function () use (&$calls) { $calls++; return 6; });
check('кеш: remember считает один раз', $calls === 1 && Cache::get('b') === 5);
check('кеш: increment', Cache::increment('n') === 1 && Cache::increment('n', 2) === 3);
Cache::set('old', 1, -1);
check('кеш: просроченное значение', Cache::get('old', 'нет') === 'нет');
Cache::forget('a');
check('кеш: forget', !Cache::has('a'));
check('кеш: flush', Cache::flush() >= 2 && !Cache::has('b'));
