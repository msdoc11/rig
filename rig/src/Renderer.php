<?php

declare(strict_types=1);

namespace Rig;

use Rig\Expr\Evaluator as E;

/**
 * SSR: строит HTML из дерева шаблона и состояния.
 * Выдаёт ту же структуру, что строит rig.js, чтобы браузер мог «оживить» готовую разметку.
 */
final class Renderer
{
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /** @var list<mixed> значения r-model открытых <select> */
    private array $select = [];

    /**
     * @param \Closure|null        $mount вложенный компонент: fn(name, props, slotsHtml, attrs) => html
     * @param array<string,string> $slots HTML слотов, переданных родителем
     */
    public function __construct(private readonly ?\Closure $mount = null, private readonly array $slots = [])
    {
    }

    public function render(array $nodes, array $s): string
    {
        $h = '';
        foreach ($nodes as $n) {
            $h .= $this->node($n, $s);
        }
        return $h;
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function node(array $n, array $s): string
    {
        switch ($n['t']) {
            case 'tx':
                $h = '';
                foreach ($n['p'] as $p) {
                    $h .= self::e(is_string($p) ? $p : E::str(E::run($p, $s)));
                }
                return $h;
            case 'if':
                foreach ($n['br'] as [$cond, $node]) {
                    if ($cond === null || E::truthy(E::run($cond, $s))) {
                        return $this->node($node, $s);
                    }
                }
                return '<!--r-->';
            case 'for':
                $src = E::run($n['s'], $s);
                $h = '';
                if (is_array($src)) {
                    foreach ($src as $i => $item) {
                        $cs = $s;
                        $cs[$n['v']] = $item;
                        if ($n['i'] !== null) {
                            $cs[$n['i']] = $i;
                        }
                        $h .= $this->node($n['n'], $cs);
                    }
                }
                return $h;
            case 'slot':
                return $this->slots[$n['n']] ?? $this->render($n['c'], $s);
            case 'cmp':
                return $this->component($n, $s);
        }
        return $this->el($n, $s);
    }

    private function component(array $n, array $s): string
    {
        if ($this->mount === null) {
            throw new \LogicException("Компонент <{$n['name']}> нельзя отрендерить здесь");
        }
        $props = [];
        foreach ($n['p'] as $k => $e) {
            $props[$k] = E::run($e, $s);
        }
        $attrs = $n['a'] ?: [];
        foreach ($n['b'] as $k => $e) {
            self::setAttr($attrs, (string)$k, E::run($e, $s));
        }
        if (isset($n['show']) && !E::truthy(E::run($n['show'], $s))) {
            $attrs['style'] = self::join([$attrs['style'] ?? null, 'display:none'], ';');
        }
        $slots = [];
        foreach ($n['slots'] ?: [] as $name => $nodes) {
            $slots[$name] = $this->render($nodes, $s);
        }
        return ($this->mount)($n['name'], $props, $slots, $attrs);
    }

    /** Атрибуты элемента в HTML: при пустом значении атрибут выводится без значения. */
    public static function attrs(array $a): string
    {
        $h = '';
        foreach ($a as $k => $v) {
            $h .= $v === '' ? " {$k}" : " {$k}=\"" . self::e((string)$v) . '"';
        }
        return $h;
    }

    private function el(array $n, array $s): string
    {
        $tag = $n['tag'];
        $lower = strtolower($tag);
        $a = $n['a'] ?: [];
        foreach ($n['b'] as $k => $e) {
            self::setAttr($a, (string)$k, E::run($e, $s));
        }
        if (isset($n['show']) && !E::truthy(E::run($n['show'], $s))) {
            $a['style'] = self::join([$a['style'] ?? null, 'display:none'], ';');
        }
        $content = null;
        $isSelect = false;
        if (isset($n['m'])) {
            $val = E::run($n['m']['p'], $s);
            $type = strtolower((string)($a['type'] ?? ''));
            if ($lower === 'input' && $type === 'checkbox') {
                $own = E::str($a['value'] ?? null);
                $on = is_array($val) ? in_array($own, array_map([E::class, 'str'], $val), true) : E::truthy($val);
                self::toggle($a, 'checked', $on);
            } elseif ($lower === 'input' && $type === 'radio') {
                self::toggle($a, 'checked', E::str($val) === E::str($a['value'] ?? null));
            } elseif ($lower === 'input' && $type === 'file') {
                // значение файла браузер задаёт только сам; в состоянии хранится ссылка на загрузку
            } elseif ($lower === 'textarea') {
                $content = E::str($val);
            } elseif ($lower === 'select') {
                $isSelect = true;
                $this->select[] = E::str($val);
            } else {
                $a['value'] = E::str($val);
            }
        }
        if ($lower === 'option' && $this->select && E::str($a['value'] ?? null) === end($this->select)) {
            $a['selected'] = '';
        }

        $h = '<' . $tag . self::attrs($a) . '>';
        if (in_array($lower, self::VOID, true)) {
            return $h;
        }
        if ($content !== null) {
            $h .= (str_starts_with($content, "\n") ? "\n" : '') . self::e($content);
        } else {
            $h .= $this->render($n['c'], $s);
        }
        if ($isSelect) {
            array_pop($this->select);
        }
        return $h . "</{$tag}>";
    }

    /** Те же правила, что setAttr в rig.js: class/style объединяются, false/null убирают атрибут. */
    private static function setAttr(array &$a, string $k, mixed $v): void
    {
        if ($k === 'class' || $k === 'style') {
            $joined = $k === 'class'
                ? self::join([$a['class'] ?? null, self::classes($v)], ' ')
                : self::join([$a['style'] ?? null, self::styles($v)], ';');
            if ($joined === '') {
                unset($a[$k]);
            } else {
                $a[$k] = $joined;
            }
            return;
        }
        if ($v === null || $v === false) {
            unset($a[$k]);
            return;
        }
        $a[$k] = $v === true ? '' : E::str($v);
    }

    private static function toggle(array &$a, string $k, bool $on): void
    {
        if ($on) {
            $a[$k] = '';
        } else {
            unset($a[$k]);
        }
    }

    private static function classes(mixed $v): string
    {
        if (is_string($v)) {
            return trim($v);
        }
        if (!is_array($v)) {
            return E::str($v);
        }
        if (array_is_list($v)) {
            return implode(' ', array_filter(array_map([self::class, 'classes'], $v), fn ($x) => $x !== ''));
        }
        $out = [];
        foreach ($v as $k => $on) {
            if (E::truthy($on)) {
                $out[] = $k;
            }
        }
        return implode(' ', $out);
    }

    private static function styles(mixed $v): string
    {
        if (is_array($v) && !array_is_list($v)) {
            $out = [];
            foreach ($v as $k => $x) {
                if ($x !== null && $x !== false && $x !== '') {
                    $out[] = $k . ':' . E::str($x);
                }
            }
            return implode(';', $out);
        }
        if (is_array($v)) {
            return implode(';', array_filter(array_map([self::class, 'styles'], $v), fn ($x) => $x !== ''));
        }
        return trim(E::str($v));
    }

    private static function join(array $parts, string $sep): string
    {
        return implode($sep, array_filter($parts, fn ($p) => $p !== null && $p !== ''));
    }
}
