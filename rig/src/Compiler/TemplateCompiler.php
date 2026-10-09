<?php

declare(strict_types=1);

namespace Rig\Compiler;

use Rig\Expr\Evaluator;
use Rig\Expr\Parser;
use Rig\Expr\SyntaxError;

/**
 * Переводит HTML-шаблон компонента в дерево узлов (JSON-совместимые массивы).
 *
 * Узлы: tx (текст с {{ }}), el (элемент), if (r-if / r-else-if / r-else), for (r-for).
 * Все выражения разбираются и проверяются здесь. Неизвестная переменная или функция
 * даёт ошибку компиляции с номером строки и до браузера не доходит.
 */
final class TemplateCompiler
{
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];
    private const EVENT_MODS = ['prevent', 'stop', 'self', 'enter', 'esc', 'space', 'tab', 'up', 'down', 'debounce', 'throttle'];
    private const MODEL_MODS = ['live', 'lazy', 'number', 'trim', 'debounce'];

    private string $html = '';
    private int $firstLine = 1;
    /** @var list<string> вложенные компоненты */
    private array $uses = [];
    /** глубина <pre>/<textarea>: внутри них пробелы не схлопываются */
    private int $pre = 0;

    /**
     * @param array<string,bool> $vars    имя переменной => можно ли ей присваивать
     * @param list<string>       $actions имена действий (#[Action] / #[On])
     * @param \Closure|null      $resolve по имени компонента возвращает ['props' => [...]] или null (рекурсия)
     */
    public function __construct(
        private readonly string $file,
        private readonly array $vars,
        private readonly array $actions,
        private readonly ?string $scopeAttr,
        private readonly ?\Closure $resolve = null,
    ) {
    }

    /** @return list<string> */
    public function uses(): array
    {
        return array_values(array_unique($this->uses));
    }

    public function compile(string $html, int $firstLine): array
    {
        $this->html = str_replace("\r\n", "\n", $html);
        $this->firstLine = $firstLine;
        return $this->children($this->parseHtml()->c, $this->vars);
    }

    /** Выражение: {{ }}, :attr, r-if, computed. */
    public function expression(string $src, array $vars, int $line): array
    {
        try {
            $ast = Parser::expression($src);
        } catch (SyntaxError $e) {
            throw new CompileError($e->getMessage(), $this->file, $line);
        }
        $this->check($ast, $vars, false, $line);
        return $ast;
    }

    /** Обработчик события или optimistic: присваивания и вызовы действий. */
    public function statements(string $src, array $vars, int $line, bool $allowActions): array
    {
        try {
            $ast = Parser::statements($src);
        } catch (SyntaxError $e) {
            throw new CompileError($e->getMessage(), $this->file, $line);
        }
        $ast = $this->normalize($ast);
        $this->check($ast, $vars, $allowActions, $line);
        return $ast;
    }

    // ---------------------------------------------------------------- HTML

    private function parseHtml(): object
    {
        $h = $this->html;
        $n = strlen($h);
        $pos = 0;
        $root = (object)['tag' => '#root', 'attrs' => [], 'c' => [], 'line' => $this->firstLine];
        $stack = [$root];
        while ($pos < $n) {
            $lt = $this->nextTag($pos);
            if ($lt > $pos) {
                end($stack)->c[] = (object)['text' => substr($h, $pos, $lt - $pos), 'line' => $this->line($pos)];
            }
            if ($lt >= $n) {
                break;
            }
            if (substr($h, $lt, 4) === '<!--') {
                $end = strpos($h, '-->', $lt + 4);
                if ($end === false) {
                    $this->fail('Незакрытый комментарий', $lt);
                }
                $pos = $end + 3;
                continue;
            }
            if (preg_match('~\G</([a-zA-Z][\w:-]*)\s*>~', $h, $m, 0, $lt)) {
                $tag = strtolower($m[1]);
                for ($k = count($stack) - 1; $k > 0 && strtolower($stack[$k]->tag) !== $tag; $k--);
                if ($k === 0) {
                    $this->fail("Лишний закрывающий тег </{$m[1]}>", $lt);
                }
                if ($k !== count($stack) - 1) {
                    $this->fail('Тег <' . end($stack)->tag . '> не закрыт', $lt);
                }
                array_pop($stack);
                $pos = $lt + strlen($m[0]);
                continue;
            }
            if (!preg_match('~\G<([a-zA-Z][\w:-]*)~', $h, $m, 0, $lt)) {
                end($stack)->c[] = (object)['text' => '<', 'line' => $this->line($lt)];
                $pos = $lt + 1;
                continue;
            }
            $tag = $m[1];
            $p = $lt + strlen($m[0]);
            $attrs = [];
            while (true) {
                if (preg_match('~\G\s*(/?)>~', $h, $mm, 0, $p)) {
                    $p += strlen($mm[0]);
                    $selfClosing = $mm[1] === '/';
                    break;
                }
                if (!preg_match('~\G\s+([^\s"\'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~', $h, $mm, PREG_UNMATCHED_AS_NULL, $p)) {
                    $this->fail("Ошибка в атрибутах тега <{$tag}>", $p);
                }
                $attrs[] = [$mm[1], $mm[2] ?? $mm[3] ?? $mm[4], $this->line($p)];
                $p += strlen($mm[0]);
            }
            $el = (object)['tag' => $tag, 'attrs' => $attrs, 'c' => [], 'line' => $this->line($lt)];
            end($stack)->c[] = $el;
            if (!$selfClosing && !in_array(strtolower($tag), self::VOID, true)) {
                $stack[] = $el;
            }
            $pos = $p;
        }
        if (count($stack) > 1) {
            $this->fail('Тег <' . end($stack)->tag . '> не закрыт', $n);
        }
        return $root;
    }

    /** Позиция следующего «<», пропуская {{ … }} (там бывает a < b). */
    private function nextTag(int $pos): int
    {
        $h = $this->html;
        $n = strlen($h);
        while (true) {
            $lt = strpos($h, '<', $pos);
            if ($lt === false) {
                return $n;
            }
            $mu = strpos($h, '{{', $pos);
            if ($mu === false || $lt < $mu) {
                return $lt;
            }
            $close = strpos($h, '}}', $mu + 2);
            if ($close === false) {
                return $lt;
            }
            $pos = $close + 2;
        }
    }

    // ---------------------------------------------------------------- узлы

    private function children(array $raw, array $vars): array
    {
        $out = [];
        foreach ($raw as $r) {
            if (isset($r->text)) {
                $t = $this->text($r, $vars);
                if ($t !== null) {
                    $out[] = $t;
                }
                continue;
            }
            $a = $this->attrs($r);
            if (isset($a['r-else']) || isset($a['r-else-if'])) {
                $last = array_key_last($out);
                if ($last !== null && $out[$last]['t'] === 'tx' && trim(implode('', array_filter($out[$last]['p'], 'is_string'))) === '' && count($out[$last]['p']) === 1) {
                    array_pop($out);
                    $last = array_key_last($out);
                }
                if ($last === null || $out[$last]['t'] !== 'if' || end($out[$last]['br'])[0] === null) {
                    $this->failAt('r-else / r-else-if должен идти сразу после элемента с r-if', $r->line);
                }
                $cond = isset($a['r-else-if']) ? $this->expression($a['r-else-if'][0], $vars, $a['r-else-if'][1]) : null;
                $out[$last]['br'][] = [$cond, $this->element($r, $vars)];
                continue;
            }
            if (isset($a['r-if'])) {
                $out[] = ['t' => 'if', 'br' => [[$this->expression($a['r-if'][0], $vars, $a['r-if'][1]), $this->element($r, $vars)]]];
                continue;
            }
            $out[] = $this->element($r, $vars);
        }
        return $out;
    }

    private function text(object $r, array $vars): ?array
    {
        $raw = $r->text;
        // внутри <pre> пробелы и переносы сохраняются как есть
        if (trim($raw) === '' && $this->pre === 0) {
            return str_contains($raw, "\n") ? null : ['t' => 'tx', 'p' => [' ']];
        }
        $parts = [];
        $line = $r->line;
        foreach (preg_split('/\{\{(.*?)\}\}/s', $raw, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $chunk) {
            if ($i % 2 === 1) {
                $parts[] = $this->expression($chunk, $vars, $line);
            } else {
                $s = html_entity_decode($this->pre > 0 ? $chunk : preg_replace('/\s+/', ' ', $chunk), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($s !== '') {
                    $parts[] = $s;
                }
            }
            $line += substr_count($chunk, "\n");
        }
        return ['t' => 'tx', 'p' => $parts];
    }

    private function element(object $r, array $vars): array
    {
        $a = $this->attrs($r);
        if (!isset($a['r-for'])) {
            return $this->single($r, $vars);
        }
        if (isset($a['r-if'])) {
            $this->failAt('r-if и r-for на одном элементе: перенесите r-if на внутренний элемент', $r->line);
        }
        [$src, $line] = $a['r-for'];
        $re = '/^\s*(?:\(\s*([A-Za-z_]\w*)\s*(?:,\s*([A-Za-z_]\w*))?\s*\)|([A-Za-z_]\w*))\s+in\s+(.+)$/s';
        if (!preg_match($re, $src, $m, PREG_UNMATCHED_AS_NULL)) {
            $this->failAt('r-for: ожидается «item in list» или «(item, index) in list»', $line);
        }
        $item = $m[3] ?? $m[1];
        $index = $m[2];
        $source = $this->expression($m[4], $vars, $line);
        $inner = [$item => false] + ($index !== null ? [$index => false] : []) + $vars;
        return ['t' => 'for', 'v' => $item, 'i' => $index, 's' => $source, 'n' => $this->single($r, $inner)];
    }

    /** Элемент, вложенный компонент (тег с заглавной буквы) или <slot>. */
    private function single(object $r, array $vars): array
    {
        if (ctype_upper($r->tag[0])) {
            return $this->component($r, $vars);
        }
        if (strtolower($r->tag) === 'slot') {
            $name = 'default';
            foreach ($r->attrs as [$attr, $value]) {
                if ($attr === 'name') {
                    $name = (string)$value;
                }
            }
            return ['t' => 'slot', 'n' => $name, 'c' => $this->children($r->c, $vars)];
        }
        return $this->plain($r, $vars);
    }

    /**
     * <TodoItem :todo="t" class="row" @remove="remove(t.id)">…</TodoItem>
     * Атрибуты, совпадающие с #[Prop] компонента, становятся его свойствами,
     * остальные становятся атрибутами элемента-обёртки. Содержимое уходит в слоты (<template #имя> для именованных).
     */
    private function component(object $r, array $vars): array
    {
        $name = $r->tag;
        try {
            $info = $this->resolve !== null ? ($this->resolve)($name) : null;
        } catch (\Throwable $e) {
            $this->failAt($e->getMessage(), $r->line);
        }
        $props = $info['props'] ?? null;
        $isProp = static fn (string $a): bool => $props === null
            ? !in_array($a, ['class', 'style', 'id'], true) && !str_starts_with($a, 'aria-') && !str_starts_with($a, 'data-')
            : in_array($a, $props, true);
        $this->uses[] = $name;

        $node = ['t' => 'cmp', 'name' => $name, 'p' => [], 'a' => [], 'b' => [], 'on' => [], 'slots' => []];
        foreach ($r->attrs as [$attr, $value, $line]) {
            $value ??= '';
            if (in_array($attr, ['r-if', 'r-else', 'r-else-if', 'r-for'], true)) {
                continue;
            }
            if ($attr === ':key') {
                $node['key'] = $this->expression($value, $vars, $line);
            } elseif ($attr === 'r-show') {
                $node['show'] = $this->expression($value, $vars, $line);
            } elseif ($attr === 'r-transition') {
                $node['tr'] = trim($value) !== '' ? trim($value) : 'fade';
            } elseif ($attr === 'r-model') {
                // <Rating r-model="score" />: значение идёт вниз в свойство компонента с #[Model], присваивание этому свойству возвращается родителю
                $model = $info['model'] ?? null;
                if ($info !== null && $model === null) {
                    $this->failAt("r-model на <{$name}>: у компонента нет свойства с #[Model] (#[Prop, Model] public int \$value)", $line);
                }
                $path = $this->expression($value, $vars, $line);
                if ($path['k'] !== 'id' && $path['k'] !== 'mem') {
                    $this->failAt('r-model: укажите переменную или поле, например r-model="score"', $line);
                }
                $this->check(['k' => 'set', 'op' => '=', 't' => $path, 'v' => ['k' => 'lit', 'v' => null]], $vars, false, $line);
                if ($model !== null) {
                    $node['p'][$model] = $path;
                    $node['m'] = ['p' => $path, 'prop' => $model];
                }
            } elseif ($attr[0] === ':') {
                $prop = substr($attr, 1);
                if ($isProp($prop)) {
                    $node['p'][$prop] = $this->expression($value, $vars, $line);
                } else {
                    $node['b'][$prop] = $this->expression($value, $vars, $line);
                }
            } elseif ($attr[0] === '@') {
                if (str_contains($attr, '.')) {
                    $this->failAt("У событий компонента нет модификаторов: {$attr}", $line);
                }
                $node['on'][] = ['e' => substr($attr, 1), 'h' => $this->statements($value, $vars + ['$event' => false], $line, true)];
            } elseif (str_starts_with($attr, 'r-')) {
                $this->failAt("Директива {$attr} не работает на компоненте <{$name}>", $line);
            } elseif ($isProp($attr)) {
                $node['p'][$attr] = ['k' => 'lit', 'v' => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
            } else {
                $node['a'][$attr] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        if ($this->scopeAttr !== null) {
            $node['a'][$this->scopeAttr] = '';
        }

        $default = [];
        foreach ($r->c as $child) {
            if (!isset($child->text) && strtolower($child->tag) === 'template') {
                $slot = null;
                foreach ($child->attrs as [$attr]) {
                    if ($attr[0] === '#') {
                        $slot = substr($attr, 1);
                    }
                }
                if ($slot === null) {
                    $this->failAt('У <template> внутри компонента нужно имя слота: <template #footer>', $child->line);
                }
                $node['slots'][$slot] = $this->children($child->c, $vars);
                continue;
            }
            $default[] = $child;
        }
        $compiled = $this->children($default, $vars);
        $blank = array_filter($compiled, fn (array $n) => !($n['t'] === 'tx' && trim(implode('', array_filter($n['p'], 'is_string'))) === '' && count($n['p']) === 1));
        if ($blank) {
            $node['slots']['default'] = $compiled;
        }
        return $node;
    }

    private function plain(object $r, array $vars): array
    {
        $node = ['t' => 'el', 'tag' => $r->tag, 'a' => [], 'b' => [], 'on' => [], 'c' => []];
        foreach ($r->attrs as [$name, $value, $line]) {
            $value ??= '';
            if (in_array($name, ['r-if', 'r-else', 'r-else-if', 'r-for'], true)) {
                continue;
            }
            if ($name === 'r-show') {
                $node['show'] = $this->expression($value, $vars, $line);
            } elseif ($name === ':key') {
                $node['key'] = $this->expression($value, $vars, $line);
            } elseif ($name === 'r-model' || str_starts_with($name, 'r-model.')) {
                $node['m'] = $this->model($r, $name, $value, $vars, $line);
            } elseif ($name[0] === ':') {
                $node['b'][substr($name, 1)] = $this->expression($value, $vars, $line);
            } elseif ($name[0] === '@') {
                $mods = explode('.', substr($name, 1));
                $event = array_shift($mods);
                $mods = $this->mods($mods, self::EVENT_MODS, '.', $line);
                $node['on'][] = ['e' => $event, 'm' => $mods, 'h' => $this->statements($value, $vars + ['$event' => false], $line, true)];
            } elseif ($name === 'r-transition') {
                // анимация появления и исчезновения: классы {имя}-enter-from/-active/-to и {имя}-leave-…
                $tr = trim($value) !== '' ? trim($value) : 'fade';
                if (!preg_match('/^[a-z][\w-]*$/i', $tr)) {
                    $this->failAt("r-transition: имя анимации из букв, цифр и дефиса, а не «{$tr}»", $line);
                }
                $node['tr'] = $tr;
            } elseif ($name === 'r-js') {
                // свой JS: модуль app/js/{имя}.js управляет содержимым элемента
                $js = trim($value);
                if (!preg_match('/^[a-z0-9][\w\-\/]*$/i', $js) || str_contains($js, '..')) {
                    $this->failAt("r-js: имя модуля из app/js — например r-js=\"chart\", а не «{$js}»", $line);
                }
                $jsFile = dirname($this->file, 2) . "/js/{$js}.js";
                if (str_contains(str_replace('\\', '/', $this->file), '/app/components/') && !is_file($jsFile)) {
                    $this->failAt("r-js: нет файла app/js/{$js}.js", $line);
                }
                $node['js'] = $js;
            } elseif ($name === 'r-props') {
                $node['jp'] = $this->expression($value, $vars, $line);
            } elseif (str_starts_with($name, 'r-')) {
                $this->failAt("Неизвестная директива {$name}", $line);
            } else {
                $node['a'][$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        if ($this->scopeAttr !== null) {
            $node['a'][$this->scopeAttr] = '';
        }
        $pre = in_array(strtolower($r->tag), ['pre', 'textarea'], true);
        $this->pre += $pre ? 1 : 0;
        $node['c'] = $this->children($r->c, $vars);
        $this->pre -= $pre ? 1 : 0;
        return $node;
    }

    /**
     * Модификаторы; после debounce/throttle может идти время: .debounce.300ms, .throttle.1s
     * (без времени 300 мс). Время сохраняется числом миллисекунд сразу за модификатором.
     */
    private function mods(array $mods, array $allowed, string $prefix, int $line): array
    {
        $out = [];
        foreach ($mods as $i => $mod) {
            if (preg_match('/^(\d+)(ms|s)$/', $mod, $m)) {
                $prev = $mods[$i - 1] ?? null;
                if (!in_array($prev, ['debounce', 'throttle'], true)) {
                    $this->failAt("Время .{$mod} пишется сразу после .debounce или .throttle", $line);
                }
                $out[] = (string)((int)$m[1] * ($m[2] === 's' ? 1000 : 1));
                continue;
            }
            if (!in_array($mod, $allowed, true)) {
                $this->failAt("Неизвестный модификатор {$prefix}{$mod} (есть: " . implode(', ', $allowed) . ')', $line);
            }
            $out[] = $mod;
        }
        return $out;
    }

    private function model(object $r, string $name, string $value, array $vars, int $line): array
    {
        $mods = $this->mods(array_slice(explode('.', $name), 1), self::MODEL_MODS, 'r-model.', $line);
        if (!in_array(strtolower($r->tag), ['input', 'select', 'textarea'], true)) {
            $this->failAt('r-model работает только на input, select и textarea', $line);
        }
        $path = $this->expression($value, $vars, $line);
        if ($path['k'] !== 'id' && $path['k'] !== 'mem') {
            $this->failAt('r-model: укажите переменную или поле, например r-model="title"', $line);
        }
        $this->check(['k' => 'set', 'op' => '=', 't' => $path, 'v' => ['k' => 'lit', 'v' => null]], $vars, false, $line);
        return ['p' => $path, 'm' => $mods];
    }

    /** @return array<string,array{0:string,1:int}> */
    private function attrs(object $r): array
    {
        $out = [];
        foreach ($r->attrs as [$name, $value, $line]) {
            $out[$name] = [$value ?? '', $line];
        }
        return $out;
    }

    // ---------------------------------------------------------------- проверка выражений

    /** Голое имя действия в обработчике (@click="save") превращается в вызов save(). */
    private function normalize(array $ast): array
    {
        if ($ast['k'] === 'seq') {
            $ast['l'] = array_map(fn (array $x) => $this->normalize($x), $ast['l']);
            return $ast;
        }
        if ($ast['k'] === 'id' && in_array($ast['n'], $this->actions, true) && !array_key_exists($ast['n'], $this->vars)) {
            return ['k' => 'call', 'n' => $ast['n'], 'a' => []];
        }
        return $ast;
    }

    private function check(array $n, array $vars, bool $actions, int $line): void
    {
        switch ($n['k']) {
            case 'lit':
                return;
            case 'id':
                if (!array_key_exists($n['n'], $vars)) {
                    $this->failAt("Неизвестная переменная «{$n['n']}»" . $this->suggest($n['n'], array_keys($vars)), $line);
                }
                return;
            case 'mem':
                $this->check($n['o'], $vars, $actions, $line);
                $this->check($n['p'], $vars, $actions, $line);
                return;
            case 'un':
                $this->check($n['a'], $vars, $actions, $line);
                return;
            case 'bin':
                $this->check($n['l'], $vars, $actions, $line);
                $this->check($n['r'], $vars, $actions, $line);
                return;
            case 'cond':
                $this->check($n['c'], $vars, $actions, $line);
                $this->check($n['t'], $vars, $actions, $line);
                $this->check($n['f'], $vars, $actions, $line);
                return;
            case 'arr':
                foreach ($n['i'] as $x) {
                    $this->check($x, $vars, $actions, $line);
                }
                return;
            case 'obj':
                foreach ($n['p'] as [, $x]) {
                    $this->check($x, $vars, $actions, $line);
                }
                return;
            case 'call':
                $isHelper = in_array($n['n'], Evaluator::HELPERS, true);
                // emit('событие', данные) шлёт событие родителю, copy(текст) копирует в буфер обмена; обе только в обработчиках
                $isAction = in_array($n['n'], $this->actions, true) || $n['n'] === 'emit' || $n['n'] === 'copy';
                if (!$isHelper && !($actions && $isAction)) {
                    $this->failAt($isAction
                        ? "Действие «{$n['n']}» можно вызывать только в обработчиках событий (@click и т.п.)"
                        : "Неизвестная функция «{$n['n']}»" . $this->suggest($n['n'], [...Evaluator::HELPERS, ...($actions ? $this->actions : [])]), $line);
                }
                foreach ($n['a'] as $x) {
                    $this->check($x, $vars, $actions, $line);
                }
                return;
            case 'set':
                $t = $n['t'];
                if ($t['k'] === 'id') {
                    if (!array_key_exists($t['n'], $vars)) {
                        $this->failAt("Неизвестная переменная «{$t['n']}»" . $this->suggest($t['n'], array_keys($vars)), $line);
                    }
                    if (!$vars[$t['n']]) {
                        $this->failAt("«{$t['n']}» нельзя изменить: это computed, переменная цикла или служебное имя", $line);
                    }
                } else {
                    $this->check($t, $vars, $actions, $line);
                }
                $this->check($n['v'], $vars, $actions, $line);
                return;
            case 'seq':
                foreach ($n['l'] as $x) {
                    $this->check($x, $vars, $actions, $line);
                }
                return;
        }
    }

    private function suggest(string $name, array $known): string
    {
        $best = null;
        $dist = 3;
        foreach ($known as $k) {
            $d = levenshtein($name, (string)$k);
            if ($d < $dist) {
                $dist = $d;
                $best = $k;
            }
        }
        return $best !== null ? " — может быть, «{$best}»?" : '';
    }

    private function line(int $offset): int
    {
        return $this->firstLine + ($offset > 0 ? substr_count($this->html, "\n", 0, $offset) : 0);
    }

    private function fail(string $message, int $offset): never
    {
        $this->failAt($message, $this->line($offset));
    }

    private function failAt(string $message, int $line): never
    {
        throw new CompileError($message, $this->file, $line);
    }
}
