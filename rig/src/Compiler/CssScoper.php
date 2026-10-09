<?php

declare(strict_types=1);

namespace Rig\Compiler;

/**
 * Изолирует стили компонента: `h1 { … }` становится `h1[r-s-xxxx] { … }`.
 * Атрибут r-s-xxxx компилятор ставит на каждый элемент шаблона.
 */
final class CssScoper
{
    private const NESTED = ['@media', '@supports', '@container', '@layer', '@document'];

    public static function scope(string $css, string $attr): string
    {
        $css = preg_replace('~/\*.*?\*/~s', '', $css);
        return self::block($css, "[{$attr}]");
    }

    private static function block(string $css, string $sel): string
    {
        $out = '';
        $i = 0;
        $n = strlen($css);
        while ($i < $n) {
            $open = strpos($css, '{', $i);
            if ($open === false) {
                $out .= substr($css, $i);
                break;
            }
            $prelude = substr($css, $i, $open - $i);
            $depth = 1;
            $j = $open + 1;
            while ($j < $n && $depth > 0) {
                if ($css[$j] === '{') {
                    $depth++;
                } elseif ($css[$j] === '}') {
                    $depth--;
                }
                $j++;
            }
            $body = substr($css, $open + 1, $j - $open - 2);
            // `@import …;` и подобные правила без блока перед селектором
            $semi = strrpos($prelude, ';');
            if ($semi !== false) {
                $out .= substr($prelude, 0, $semi + 1);
                $prelude = substr($prelude, $semi + 1);
            }
            $p = ltrim($prelude);
            $at = strtolower((string)strtok($p, " \t\n("));
            if (in_array($at, self::NESTED, true)) {
                $out .= $prelude . '{' . self::block($body, $sel) . '}';
            } elseif (str_starts_with($p, '@')) {
                $out .= $prelude . '{' . $body . '}';
            } else {
                $out .= self::selectors($prelude, $sel) . '{' . $body . '}';
            }
            $i = $j;
        }
        return $out;
    }

    private static function selectors(string $list, string $sel): string
    {
        $parts = [];
        $depth = 0;
        $cur = '';
        foreach (str_split($list) as $c) {
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            }
            if ($c === ',' && $depth === 0) {
                $parts[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        $parts[] = $cur;
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            // атрибут ставится перед хвостовыми псевдоклассами: a:hover становится a[q-s]:hover
            preg_match('/^(.*?)((?:::?[\w-]+(?:\([^)]*\))?)*)$/s', $p, $m);
            $out[] = $m[1] . $sel . $m[2];
        }
        return implode(', ', $out) . ' ';
    }
}
