<?php

/**
 * Тесты разбора файла компонента: блоки <template>, <style>, <skeleton> после класса.
 * Запуск: php rig/tests/blocks.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Rig\Compiler\CompileError;
use Rig\Compiler\ComponentFile;

$class = "<?php\n\nnamespace App\\Components;\n\nfinal class T extends \\Rig\\Component\n{\n}\n?>\n";

/** [название, исходник, ожидание: массив блоков или фрагмент текста ошибки + строка] */
$cases = [
    ['старый формат без ?>', "<?php\nfinal class T {}\n", []],
    ['шаблон и стиль', $class . "\n<template>\n  <p>{{ a }}</p>\n</template>\n\n<style>\n  p { color: red }\n</style>\n", [
        'template' => ["\n<p>{{ a }}</p>\n", 10],
        'style' => ["\np { color: red }\n", 14],
    ]],
    ['вложенные <template #слот>', $class . "<template>\n  <Card>\n    <template #footer>x</template>\n  </Card>\n</template>\n", [
        'template' => ["\n<Card>\n  <template #footer>x</template>\n</Card>\n", 9],
    ]],
    ['комментарии между блоками', $class . "<!-- шаблон -->\n<template><b>1</b></template>\n<!-- ещё -->\n<skeleton><i></i></skeleton>", [
        'template' => ['<b>1</b>', 10],
        'skeleton' => ['<i></i>', 12],
    ]],
    ['отступ в <pre> сохраняется относительно блока', $class . "<template>\n  <pre>a\n    b</pre>\n</template>", [
        'template' => ["\n<pre>a\n  b</pre>\n", 9],
    ]],
    ['CRLF', str_replace("\n", "\r\n", $class . "<template>\n  <p>1</p>\n</template>\n"), [
        'template' => ["\n<p>1</p>\n", 9],
    ]],
    ['PHP-код после класса', $class . "<template>\n  <p><?= date('H') ?></p>\n</template>", ['PHP-код после класса', 10]],
    ['посторонний текст', $class . "<div>x</div>", ['ожидаются блоки', 9]],
    ['блок дважды', $class . "<template>a</template>\n<template>b</template>", ['встречается дважды', 10]],
    ['незакрытый блок', $class . "<template>\n  <p>a</p>\n", ['не закрыт', 9]],
];

$fail = 0;
$dir = sys_get_temp_dir() . '/rig-blocks-' . getmypid();
@mkdir($dir);
foreach ($cases as $i => [$name, $src, $want]) {
    $file = "{$dir}/c{$i}.php";
    file_put_contents($file, $src);
    try {
        $got = ComponentFile::blocks($file);
    } catch (CompileError $e) {
        $got = [$e->getMessage(), $e->getLine()];
    }
    $ok = isset($want[0]) && is_string($want[0])
        ? is_string($got[0] ?? null) && str_contains($got[0], $want[0]) && $got[1] === $want[1]
        : $got === $want;
    if (!$ok) {
        $fail++;
        echo "✗ {$name}\n  получено:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n  ожидалось: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
    }
    unlink($file);
}
@rmdir($dir);

$total = count($cases);
echo $fail ? "Блоки: {$fail} из {$total} не прошли\n" : "Блоки: все {$total} проверок ✓\n";
exit($fail ? 1 : 0);
