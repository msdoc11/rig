<?php

/**
 * Тесты языка выражений: одинаковый ли результат у PHP (SSR) и JS (браузер).
 * Запуск: php rig/tests/run.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Rig\Expr\Evaluator;
use Rig\Expr\Parser;

function same(mixed $a, mixed $b): bool
{
    if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
        return $a == $b;
    }
    if (is_array($a) && is_array($b)) {
        if (array_keys($a) !== array_keys($b)) {
            return false;
        }
        foreach ($a as $k => $v) {
            if (!same($v, $b[$k])) {
                return false;
            }
        }
        return true;
    }
    return $a === $b;
}

$data = json_decode((string)file_get_contents(__DIR__ . '/expr.json'), true, 512, JSON_THROW_ON_ERROR);
$fail = 0;
$forJs = ['expr' => [], 'statements' => []];

foreach ($data['expr'] as $i => [$src, $scope, $want]) {
    try {
        $ast = Parser::expression($src);
        $got = Evaluator::run($ast, $scope);
    } catch (Throwable $e) {
        $fail++;
        echo "PHP ✗ {$src}: {$e->getMessage()}\n";
        continue;
    }
    if (!same($got, $want)) {
        $fail++;
        echo "PHP ✗ {$src}: получено " . json_encode($got, JSON_UNESCAPED_UNICODE) . ', ожидалось ' . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
    }
    $forJs['expr'][] = [$src, $ast, $scope, $want, Evaluator::str($got)];
}
foreach ($data['statements'] as [$src, $scope, $want]) {
    $forJs['statements'][] = [$src, Parser::statements($src), $scope, $want];
}

$total = count($data['expr']);
echo $fail ? "PHP: {$fail} из {$total} не прошли\n" : "PHP: все {$total} выражений ✓\n";

$tmp = tempnam(sys_get_temp_dir(), 'rig');
file_put_contents($tmp, json_encode($forJs, JSON_UNESCAPED_UNICODE));
passthru('node ' . escapeshellarg(__DIR__ . '/expr.test.cjs') . ' ' . escapeshellarg($tmp), $code);
unlink($tmp);
exit($fail || $code ? 1 : 0);
