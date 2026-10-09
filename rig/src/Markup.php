<?php

declare(strict_types=1);

namespace Rig;

use Rig\Expr\Evaluator;
use Rig\Expr\Parser;
use Rig\Expr\SyntaxError;

/**
 * Компоненты тегами прямо в страницах и layout:
 *
 *   <TodoList />
 *   <Counter label="Клики" :count="5" />
 *   <Counter :count="<?= $n ?>" />
 *   <Card title="Заголовок">содержимое <template #footer>подвал</template></Card>
 *
 * Страница пишется на обычном PHP: сначала выполняется он, затем в получившемся HTML теги
 * компонентов (PascalCase) заменяются на отрендеренные компоненты.
 * label="…" передаёт строку, :count="…" передаёт выражение (числа, true/false/null, [списки], {объекты}, JSON).
 * Содержимое <script>, <style>, <textarea>, <pre> не трогается.
 */
final class Markup
{
    /** Атрибуты, которые без объявления в компоненте уходят на элемент-обёртку. */
    private const WRAPPER = ['class', 'style', 'id', 'title', 'role'];

    public function __construct(private readonly App $app)
    {
    }

    public function expand(string $html): string
    {
        $stash = [];
        $html = (string)preg_replace_callback('~<(script|style|textarea|pre)\b[^>]*>.*?</\1\s*>~is', static function (array $m) use (&$stash): string {
            $stash[] = $m[0];
            return "\x01" . (count($stash) - 1) . "\x01";
        }, $html);
        $html = $this->tags($html);
        return (string)preg_replace_callback('~\x01(\d+)\x01~', static fn (array $m): string => $stash[(int)$m[1]], $html);
    }

    private function tags(string $html): string
    {
        $out = '';
        $pos = 0;
        while (preg_match('~<([A-Z][a-z][A-Za-z0-9]*)(?=[\s/>])~', $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $m[0][1];
            $name = $m[1][0];
            [$attrs, $end, $selfClosing] = $this->attrs($html, $start + strlen($m[0][0]), $name);
            $out .= substr($html, $pos, $start - $pos);
            $inner = null;
            if ($selfClosing) {
                $pos = $end;
            } else {
                [$inner, $pos] = $this->body($html, $end, $name);
            }
            $out .= $this->render($name, $attrs, $inner);
        }
        return $out . substr($html, $pos);
    }

    /** @return array{0: array<string,?string>, 1: int, 2: bool} атрибуты, конец тега, самозакрывающийся ли */
    private function attrs(string $h, int $p, string $name): array
    {
        $attrs = [];
        while (true) {
            if (preg_match('~\G\s*(/?)>~', $h, $mm, 0, $p)) {
                return [$attrs, $p + strlen($mm[0]), $mm[1] === '/'];
            }
            if (!preg_match('~\G\s+([^\s"\'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~', $h, $mm, PREG_UNMATCHED_AS_NULL, $p)) {
                throw new \RuntimeException("Ошибка в атрибутах <{$name}> на странице");
            }
            $attrs[$mm[1]] = $mm[2] ?? $mm[3] ?? $mm[4];
            $p += strlen($mm[0]);
        }
    }

    /** Содержимое <Name>…</Name> с учётом вложенных <Name> того же имени. */
    private function body(string $h, int $from, string $name): array
    {
        $depth = 1;
        $p = $from;
        while (preg_match('~<(/?)' . $name . '(?=[\s/>])~', $h, $m, PREG_OFFSET_CAPTURE, $p)) {
            $at = $m[0][1];
            if ($m[1][0] === '/') {
                $close = (int)strpos($h, '>', $at);
                if (--$depth === 0) {
                    return [substr($h, $from, $at - $from), $close + 1];
                }
                $p = $close + 1;
            } else {
                [, $end, $selfClosing] = $this->attrs($h, $at + strlen($m[0][0]), $name);
                if (!$selfClosing) {
                    $depth++;
                }
                $p = $end;
            }
        }
        throw new \RuntimeException("Нет закрывающего </{$name}> на странице");
    }

    private function render(string $name, array $attrs, ?string $inner): string
    {
        $def = $this->app->compiler->load($name);
        $known = [...$def['server'], ...array_keys($def['client'] ?: [])];
        $props = $wrapper = [];
        foreach ($attrs as $attr => $raw) {
            $attr = (string)$attr;
            $bound = $attr[0] === ':';
            $key = $bound ? substr($attr, 1) : $attr;
            if ($bound) {
                try {
                    $value = Evaluator::run(Parser::expression((string)$raw), []);
                } catch (SyntaxError $e) {
                    throw new \RuntimeException("<{$name} {$attr}>: {$e->getMessage()}");
                }
            } else {
                $value = html_entity_decode((string)($raw ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (in_array($key, $known, true)) {
                $props[$key] = $value;
            } elseif (in_array($key, self::WRAPPER, true) || str_starts_with($key, 'aria-') || str_starts_with($key, 'data-')) {
                if ($value !== null && $value !== false) {
                    $wrapper[$key] = $value === true ? '' : Evaluator::str($value);
                }
            } else {
                throw new \InvalidArgumentException("<{$name}>: у компонента нет свойства «{$key}». Есть: " . implode(', ', $known));
            }
        }
        $slots = [];
        if ($inner !== null) {
            $inner = (string)preg_replace_callback('~<template\s+#([\w-]+)\s*>(.*?)</template>~s', function (array $m) use (&$slots): string {
                $slots[$m[1]] = $this->slot($m[1], $m[2]);
                return '';
            }, $inner);
            if (trim($inner) !== '') {
                $slots['default'] = $this->slot('default', $inner);
            }
        }
        return $this->app->mount($name, $props, $slots, $wrapper);
    }

    /**
     * Слот со страницы: статичный HTML. Обёртка r-slot говорит rig.js сохранить его как есть
     * (компоненты внутри оживают сами), а display:contents не влияет на вёрстку.
     */
    private function slot(string $name, string $html): string
    {
        return '<div r-slot="' . Renderer::e($name) . '" style="display:contents">' . trim($this->tags($html)) . '</div>';
    }
}
