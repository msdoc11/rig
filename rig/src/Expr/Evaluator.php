<?php

declare(strict_types=1);

namespace Rig\Expr;

/**
 * Серверный интерпретатор AST выражений (для SSR).
 *
 * Правила значений совпадают с rig.js: истинность, сравнение,
 * сложение строк и чисел, форматирование чисел. Изменение здесь нужно
 * повторить в runtime/rig.js, совпадение проверяют тесты в rig/tests.
 */
final class Evaluator
{
    /** Функции, доступные в шаблонах. Тот же список реализован в rig.js. */
    public const HELPERS = [
        'len', 'upper', 'lower', 'trim', 'round', 'fixed', 'abs', 'floor', 'ceil', 'min', 'max',
        'json', 'join', 'keys', 'values', 'includes', 'push', 'without', 'where', 'whereNot',
        'pluck', 'sum', 'slice', 'plural', 'string', 'number', 't',
    ];

    private const NUM_RE = '/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/';

    public static function run(array $n, array $s): mixed
    {
        switch ($n['k']) {
            case 'lit':
                return $n['v'];
            case 'id':
                return $s[$n['n']] ?? null;
            case 'mem':
                return self::get(self::run($n['o'], $s), self::run($n['p'], $s));
            case 'un':
                $a = self::run($n['a'], $s);
                return match ($n['op']) { '!' => !self::truthy($a), '-' => -self::num($a), default => self::num($a) };
            case 'bin':
                return self::binary($n, $s);
            case 'cond':
                return self::truthy(self::run($n['c'], $s)) ? self::run($n['t'], $s) : self::run($n['f'], $s);
            case 'arr':
                return array_map(fn (array $x) => self::run($x, $s), $n['i']);
            case 'obj':
                $o = [];
                foreach ($n['p'] as [$k, $v]) {
                    $o[$k] = self::run($v, $s);
                }
                return $o;
            case 'call':
                return self::call($n['n'], array_map(fn (array $x) => self::run($x, $s), $n['a']));
        }
        throw new \LogicException("Узел «{$n['k']}» выполняется только в браузере");
    }

    public static function get(mixed $o, mixed $p): mixed
    {
        if (!is_array($o)) {
            return null;
        }
        if (is_float($p) && floor($p) === $p) {
            $p = (int)$p;
        }
        if (!is_int($p) && !is_string($p)) {
            return null;
        }
        return $o[$p] ?? null;
    }

    private static function binary(array $n, array $s): mixed
    {
        $l = self::run($n['l'], $s);
        switch ($n['op']) {
            case '&&':
                return self::truthy($l) ? self::run($n['r'], $s) : $l;
            case '||':
                return self::truthy($l) ? $l : self::run($n['r'], $s);
            case '??':
                return $l ?? self::run($n['r'], $s);
        }
        $r = self::run($n['r'], $s);
        switch ($n['op']) {
            case '+':
                return self::add($l, $r);
            case '-':
                return self::num($l) - self::num($r);
            case '*':
                return self::num($l) * self::num($r);
            case '/':
                $d = self::num($r);
                return $d == 0 ? null : self::num($l) / $d;
            case '%':
                $d = self::num($r);
                $a = self::num($l);
                if ($d == 0) {
                    return null;
                }
                return is_int($a) && is_int($d) ? $a % $d : fmod($a, $d);
            case '==':
            case '===':
                return self::eq($l, $r);
            case '!=':
            case '!==':
                return !self::eq($l, $r);
            case '<':
                return self::cmp($l, $r) < 0;
            case '>':
                return self::cmp($l, $r) > 0;
            case '<=':
                return self::cmp($l, $r) <= 0;
            case '>=':
                return self::cmp($l, $r) >= 0;
        }
        throw new \LogicException("Неизвестный оператор {$n['op']}");
    }

    public static function add(mixed $a, mixed $b): mixed
    {
        if (is_array($a) && is_array($b) && array_is_list($a) && array_is_list($b)) {
            return array_merge($a, $b);
        }
        if (is_string($a) || is_string($b)) {
            return self::str($a) . self::str($b);
        }
        return self::num($a) + self::num($b);
    }

    public static function num(mixed $v): int|float
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_string($v)) {
            $t = trim($v);
            return preg_match(self::NUM_RE, $t) ? $t + 0 : 0;
        }
        return 0;
    }

    public static function str(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'true' : 'false',
            is_int($v) => (string)$v,
            is_float($v) => self::fmt($v),
            is_string($v) => $v,
            is_array($v) => self::json($v),
            default => '',
        };
    }

    private static function fmt(float $f): string
    {
        if (!is_finite($f)) {
            return '';
        }
        if ($f == floor($f) && abs($f) < 1e15) {
            return (string)(int)$f;
        }
        return json_encode($f);
    }

    public static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null';
    }

    public static function truthy(mixed $v): bool
    {
        if (is_float($v) && is_nan($v)) {
            return false;
        }
        return !($v === null || $v === false || $v === 0 || $v === 0.0 || $v === '' || $v === []);
    }

    public static function eq(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }
        if (is_array($a) && is_array($b)) {
            return self::json($a) === self::json($b);
        }
        return $a === $b;
    }

    public static function cmp(mixed $a, mixed $b): int
    {
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }
        return self::num($a) <=> self::num($b);
    }

    private static function round(int|float $v, int $d): float
    {
        $f = 10 ** $d;
        return ($v <=> 0) * floor(abs($v) * $f + 0.5) / $f + 0;
    }

    public static function call(string $name, array $a): mixed
    {
        $x = $a[0] ?? null;
        switch ($name) {
            case 'len':
                return is_array($x) ? count($x) : mb_strlen(self::str($x));
            case 'upper':
                return mb_strtoupper(self::str($x));
            case 'lower':
                return mb_strtolower(self::str($x));
            case 'trim':
                return trim(self::str($x));
            case 'round':
                return self::round(self::num($x), (int)self::num($a[1] ?? 0));
            case 'fixed':
                $d = max(0, min(20, (int)self::num($a[1] ?? 2)));
                return sprintf("%.{$d}F", self::round(self::num($x), $d));
            case 'abs':
                return abs(self::num($x));
            case 'floor':
                return floor(self::num($x));
            case 'ceil':
                return ceil(self::num($x));
            case 'min':
            case 'max':
                $l = count($a) === 1 && is_array($x) ? array_values($x) : $a;
                if (!$l) {
                    return null;
                }
                $r = self::num($l[0]);
                foreach ($l as $v) {
                    $v = self::num($v);
                    if ($name === 'min' ? $v < $r : $v > $r) {
                        $r = $v;
                    }
                }
                return $r;
            case 'json':
                return self::json($x);
            case 'join':
                return is_array($x) ? implode(self::str($a[1] ?? ', '), array_map([self::class, 'str'], $x)) : self::str($x);
            case 'keys':
                return is_array($x) ? array_keys($x) : [];
            case 'values':
                return is_array($x) ? array_values($x) : [];
            case 'includes':
                if (is_array($x)) {
                    foreach ($x as $v) {
                        if (self::eq($v, $a[1] ?? null)) {
                            return true;
                        }
                    }
                    return false;
                }
                return str_contains(self::str($x), self::str($a[1] ?? null));
            case 'push':
                return [...(is_array($x) ? array_values($x) : []), ...array_slice($a, 1)];
            case 'without':
                return is_array($x) ? array_values(array_filter($x, fn ($v) => !self::eq($v, $a[1] ?? null))) : [];
            case 'where':
            case 'whereNot':
                if (!is_array($x)) {
                    return [];
                }
                $want = $name === 'where';
                return array_values(array_filter($x, fn ($v) => self::eq(self::get($v, $a[1] ?? null), $a[2] ?? null) === $want));
            case 'pluck':
                return is_array($x) ? array_values(array_map(fn ($v) => self::get($v, $a[1] ?? null), $x)) : [];
            case 'sum':
                if (!is_array($x)) {
                    return 0;
                }
                $t = 0;
                foreach ($x as $v) {
                    $t += self::num(isset($a[1]) ? self::get($v, $a[1]) : $v);
                }
                return $t;
            case 'slice':
                return self::slice($x, $a[1] ?? 0, $a[2] ?? null);
            case 'plural':
                $n = abs((int)self::num($x));
                $m10 = $n % 10;
                $m100 = $n % 100;
                $i = $m10 === 1 && $m100 !== 11 ? 1 : ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14) ? 2 : 3);
                return self::str($a[$i] ?? '');
            case 'string':
                return self::str($x);
            case 'number':
                return self::num($x);
            case 't':
                // перевод из lang/{язык}.php; в браузере работает тот же словарь (Rig\I18n::client)
                return \Rig\I18n::t(self::str($x), is_array($a[1] ?? null) ? $a[1] : []);
        }
        throw new \RuntimeException("Неизвестная функция «{$name}»");
    }

    private static function slice(mixed $x, mixed $start, mixed $end): mixed
    {
        $isArr = is_array($x);
        $len = $isArr ? count($x) : mb_strlen(self::str($x));
        $s = (int)self::num($start);
        $s = $s < 0 ? max($len + $s, 0) : min($s, $len);
        $e = $end === null ? $len : (int)self::num($end);
        $e = $e < 0 ? max($len + $e, 0) : min($e, $len);
        $c = max($e - $s, 0);
        return $isArr ? array_slice(array_values($x), $s, $c) : mb_substr(self::str($x), $s, $c);
    }
}
