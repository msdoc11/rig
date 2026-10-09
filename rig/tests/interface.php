<?php

/**
 * Тесты интерфейса: директивы шаблонов (модификаторы времени, r-transition, r-js, r-model на компоненте),
 * переводы (формы числа, выбор языка, t() одинаково в PHP и rig.js), теги <head>.
 * Запуск: php rig/tests/interface.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Rig\Compiler\CompileError;
use Rig\Compiler\TemplateCompiler;
use Rig\Config;
use Rig\Head;
use Rig\I18n;
use Rig\Request;

$tmp = sys_get_temp_dir() . '/rig-interface-' . getmypid();
@mkdir("{$tmp}/app/components", 0777, true);
@mkdir("{$tmp}/app/js", 0777, true);
@mkdir("{$tmp}/lang", 0777, true);
file_put_contents("{$tmp}/app/js/chart.js", 'export function mount() {}');
Config::init($tmp, ['i18n' => ['locales' => ['ru', 'en'], 'default' => 'ru'], 'head' => ['title' => 'Сайт', 'description' => 'По умолчанию']]);

$fail = 0;
$total = 0;

function check(string $name, bool $ok, mixed $got = null): void
{
    global $fail, $total;
    $total++;
    if (!$ok) {
        $fail++;
        echo "✗ {$name}" . ($got !== null ? '  получено: ' . json_encode($got, JSON_UNESCAPED_UNICODE) : '') . "\n";
    }
}

/** Скомпилировать шаблон как компонент app/components/T.php; при ошибке вернуть её текст. */
function tpl(string $html, array $vars = ['q' => true, 'n' => true, 'items' => true, 'score' => true], ?Closure $resolve = null): array|string
{
    global $tmp;
    $tc = new TemplateCompiler("{$tmp}/app/components/T.php", $vars, ['save'], null, $resolve);
    try {
        return $tc->compile($html, 1);
    } catch (CompileError $e) {
        return $e->getMessage();
    }
}

// --- debounce / throttle
$t = tpl('<input @input.debounce.400ms="q = $event.target.value">');
check('@input.debounce.400ms: [debounce, 400]', ($t[0]['on'][0]['m'] ?? null) === ['debounce', '400'], $t);
$t = tpl('<button @click.throttle.1s="save">x</button>');
check('.throttle.1s: 1000 мс', ($t[0]['on'][0]['m'] ?? null) === ['throttle', '1000'], $t);
$t = tpl('<button @click.debounce="save">x</button>');
check('.debounce без времени', ($t[0]['on'][0]['m'] ?? null) === ['debounce'], $t);
$t = tpl('<input r-model.live.debounce.300ms="q">');
check('r-model.live.debounce.300ms', ($t[0]['m']['m'] ?? null) === ['live', 'debounce', '300'], $t);
check('время без debounce — ошибка', str_contains((string)tpl('<button @click.300ms="save">x</button>'), 'сразу после'));
check('неизвестный модификатор — ошибка', str_contains((string)tpl('<button @click.later="save">x</button>'), 'Неизвестный модификатор'));

// --- r-transition, r-js
$t = tpl('<p r-if="n" r-transition="slide">x</p>');
check('r-transition на r-if', ($t[0]['br'][0][1]['tr'] ?? null) === 'slide', $t);
$t = tpl('<p r-transition>x</p>');
check('r-transition без имени — fade', ($t[0]['tr'] ?? null) === 'fade', $t);
check('r-transition: недопустимое имя', str_contains((string)tpl('<p r-transition="a b">x</p>'), 'r-transition'));
$t = tpl('<div r-js="chart" r-props="{items: items}"></div>');
check('r-js + r-props', ($t[0]['js'] ?? null) === 'chart' && ($t[0]['jp']['k'] ?? null) === 'obj', $t);
check('r-js: нет файла — ошибка', str_contains((string)tpl('<div r-js="nope"></div>'), 'app/js/nope.js'));
check('r-js: путь с .. запрещён', str_contains((string)tpl('<div r-js="../x"></div>'), 'r-js'));

// --- r-model на компоненте
$withModel = static fn (string $name) => ['props' => ['value', 'max'], 'model' => 'value'];
$t = tpl('<Rating r-model="score" :max="5" />', resolve: $withModel);
check('r-model на компоненте: свойство и обратная связь', ($t[0]['m']['prop'] ?? null) === 'value' && ($t[0]['p']['value']['n'] ?? null) === 'score', $t);
$noModel = static fn (string $name) => ['props' => ['value'], 'model' => null];
check('r-model на компоненте без #[Model] — ошибка', str_contains((string)tpl('<Rating r-model="score" />', resolve: $noModel), '#[Model]'));
check('r-model на компоненте: только переменная', str_contains((string)tpl('<Rating r-model="score + 1" />', resolve: $withModel), 'укажите переменную'));
check('t() доступна в шаблоне', is_array(tpl('<p>{{ t("demo.hello", {name: q}) }}</p>')));

// --- переводы
file_put_contents("{$tmp}/lang/ru.php", "<?php return ['shop' => ['items' => '{n} товар|{n} товара|{n} товаров', 'hi' => 'Привет, {name}!'], 'only_ru' => 'Только по-русски'];");
file_put_contents("{$tmp}/lang/en.php", "<?php return ['shop' => ['items' => '{n} item|{n} items', 'hi' => 'Hello, {name}!']];");
$ru = [[1, '1 товар'], [2, '2 товара'], [5, '5 товаров'], [11, '11 товаров'], [21, '21 товар'], [22, '22 товара'], [112, '112 товаров'], [1.5, '1.5 товара']];
foreach ($ru as [$n, $want]) {
    check("ru: {$want}", I18n::t('shop.items', ['n' => $n], 'ru') === $want, I18n::t('shop.items', ['n' => $n], 'ru'));
}
check('en: 1 item / 3 items', I18n::t('shop.items', ['n' => 1], 'en') === '1 item' && I18n::t('shop.items', ['n' => 3], 'en') === '3 items');
check('подстановка {name}', I18n::t('shop.hi', ['name' => 'Анна'], 'ru') === 'Привет, Анна!');
check('нет перевода — из языка по умолчанию', I18n::t('only_ru', [], 'en') === 'Только по-русски');
check('нет ключа — сам ключ', I18n::t('nope.key', [], 'en') === 'nope.key');
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9,en;q=0.8,ru;q=0.5';
I18n::reset();
check('Accept-Language: лучший из доступных', I18n::locale() === 'en');
$_COOKIE['locale'] = 'ru';
I18n::reset();
check('cookie важнее Accept-Language', I18n::locale() === 'ru');
$_COOKIE['locale'] = 'xx';
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr';
I18n::reset();
check('неизвестный язык — по умолчанию', I18n::locale() === 'ru');
unset($_COOKIE['locale']);
check('setLocale: неизвестный язык — ошибка', (function () {
    try {
        I18n::setLocale('xx');
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
})());
check('t() в выражениях шаблона', Rig\Expr\Evaluator::call('t', ['shop.items', ['n' => 4]]) === '4 товара');

// t(): то же самое в rig.js
$cases = [];
foreach (['ru', 'en'] as $loc) {
    I18n::reset();
    $_COOKIE['locale'] = $loc;
    $client = I18n::client();
    foreach ([0, 1, 2, 4, 5, 11, 21, 104, 1.5] as $n) {
        $cases[] = [$client, 'shop.items', ['n' => $n], I18n::t('shop.items', ['n' => $n])];
    }
    $cases[] = [$client, 'shop.hi', ['name' => 'rig'], I18n::t('shop.hi', ['name' => 'rig'])];
    $cases[] = [$client, 'nope', [], I18n::t('nope')];
}
$json = "{$tmp}/i18n.json";
file_put_contents($json, json_encode($cases, JSON_UNESCAPED_UNICODE));
exec('node ' . escapeshellarg(__DIR__ . '/i18n.test.cjs') . ' ' . escapeshellarg($json) . ' 2>&1', $lines, $code);
check('t() в rig.js совпадает с PHP (' . count($cases) . ' случаев)', $code === 0, implode("\n", $lines));
unset($_COOKIE['locale']);

// --- <head>
Request::set(new Request(['REQUEST_URI' => '/catalog?page=2', 'HTTP_HOST' => 'shop.test'], [], [], [], []));
Head::reset();
Head::title('Каталог');
Head::set(description: 'Кофе и чай <лучшие>', image: '/img/og.jpg', robots: false);
Head::meta('author', 'rig');
$h = Head::render();
check('title', str_contains($h, '<title data-rig-head>Каталог</title>'));
check('description экранирован', str_contains($h, 'content="Кофе и чай &lt;лучшие&gt;"'));
check('og:image — полный адрес', (bool)preg_match('~og:image" content="https?://[^"]+/img/og\.jpg"~', $h), $h);
check('canonical без параметров', (bool)preg_match('~rel="canonical" href="https?://[^"]+/catalog"~', $h), $h);
check('robots: noindex', str_contains($h, 'content="noindex, nofollow"'));
check('twitter:card с картинкой', str_contains($h, 'summary_large_image'));
check('свой meta', str_contains($h, 'name="author" content="rig"'));
check('все теги помечены data-rig-head', substr_count($h, 'data-rig-head') === substr_count($h, '<') - substr_count($h, '</'), $h);
Head::reset();
check('по умолчанию — из config', str_contains(Head::render(), '<title data-rig-head>Сайт</title>') && str_contains(Head::render(), 'По умолчанию'));

// --- события с параметрами: #[On('chat.{roomId}')]
/** Записать компонент в app/components, подключить и скомпилировать; при ошибке вернуть её текст. */
function component(string $name, string $body): array|string
{
    global $tmp;
    $file = "{$tmp}/app/components/{$name}.php";
    file_put_contents($file, "<?php\nnamespace App\\Components;\nuse Rig\\Attr\\{Action, Locked, On, Prop};\n"
        . "final class {$name} extends \\Rig\\Component\n{\n{$body}\n}\n?>\n<template><p>{{ 1 }}</p></template>\n");
    ob_start();
    require $file;
    ob_end_clean();
    try {
        return (new Rig\Compiler\Compiler("{$tmp}/cache"))->load($name);
    } catch (CompileError $e) {
        return $e->getMessage();
    }
}
@mkdir("{$tmp}/cache");
$chat = component('ChatRoom', "#[Locked] public int \$roomId = 5;\n#[Prop] public array \$user = ['id' => 7];\n"
    . "#[On('chat.{roomId}')] public function message(mixed \$d = null): void {}\n"
    . "#[On('dm.{user.id}')] public function direct(mixed \$d = null): void {}\n"
    . "#[On('ping')] public function ping(): void {}");
check('#[On] с параметрами компилируется', is_array($chat) && isset($chat['on']['chat.{roomId}'], $chat['on']['dm.{user.id}']), $chat);
check('#[On]: параметр без #[Locked] — ошибка', str_contains((string)component('ChatOpen', "public int \$roomId = 5;\n#[On('chat.{roomId}')] public function m(): void {}"), '#[Locked] или #[Prop]'));
check('#[On]: в скобках не имя — ошибка', str_contains((string)component('ChatBad', "#[Locked] public int \$roomId = 5;\n#[On('chat.{roomId + 1}')] public function m(): void {}"), 'имя свойства'));
check('#[On]: непарная скобка — ошибка', str_contains((string)component('ChatOdd', "#[Locked] public int \$roomId = 5;\n#[On('chat.{roomId')] public function m(): void {}"), 'непарные'));

$app = (new ReflectionClass(Rig\App::class))->newInstanceWithoutConstructor();
(fn () => $this->key = 'test-key')->call($app); // readonly: задаётся только изнутри класса
$topics = (new ReflectionMethod(Rig\App::class, 'topics'))->getClosure($app);
$room = new App\Components\ChatRoom();
$tp = $topics($chat, $room);
check('подстановка значений: chat.5 и dm.7', array_column($tp, 0) === ['chat.5', 'dm.7'] && array_column($tp, 1) === ['chat.{roomId}', 'dm.{user.id}'], $tp);
check('подпись совпадает с Snapshot::topic', ($tp[0][2] ?? null) === Rig\Snapshot::topic('chat.5', 'test-key'));
check('подпись зависит от имени и ключа', Rig\Snapshot::topic('chat.6', 'test-key') !== $tp[0][2] && Rig\Snapshot::topic('chat.5', 'other') !== $tp[0][2]);
$room->user = [];
check('пустое значение — без подписки', array_column($topics($chat, $room), 0) === ['chat.5'], $topics($chat, $room));

// то же в rig.js: доставка chat.5 по подстановке и r-model через computed-список
exec('node ' . escapeshellarg(__DIR__ . '/events.test.cjs') . ' 2>&1', $lines2, $code2);
check('rig.js: события с параметрами и r-model через computed-список', $code2 === 0, implode("\n", $lines2));

// уборка
$rm = static function (string $d) use (&$rm): void {
    foreach (glob($d . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $f) {
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($d);
};
$rm($tmp);
echo $fail ? "Интерфейс: {$fail} из {$total} не прошли\n" : "Интерфейс: все {$total} проверок ✓\n";
exit($fail ? 1 : 0);
