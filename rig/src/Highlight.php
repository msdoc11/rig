<?php

declare(strict_types=1);

namespace Rig;

/**
 * Подсветка кода на сервере, без JS: php (вместе с блоками <template>/<style> после класса), html/rig-шаблоны, bash, css.
 * Классы: kw, str, var, num, cm, tag, cmp (компонент), attr, dir (директива), expr ({{ }}), fn.
 */
final class Highlight
{
    public static function html(string $code, string $lang): string
    {
        return implode("\n", self::lines($code, $lang));
    }

    /** @return list<string> строки HTML (теги закрыты в пределах строки, чтобы строки можно было нумеровать) */
    public static function lines(string $code, string $lang): array
    {
        $code = str_replace("\r\n", "\n", $code);
        $html = match (strtolower($lang)) {
            'php' => self::php($code),
            'html', 'rig', 'template', 'vue' => self::markup($code),
            'bash', 'sh', 'shell', 'powershell', 'cmd' => self::shell($code),
            'css' => self::css($code),
            default => Renderer::e($code),
        };
        return self::splitLines($html);
    }

    private static function php(string $code): string
    {
        $wrapped = !str_contains($code, '<?');
        $tokens = \PhpToken::tokenize($wrapped ? "<?php\n" . $code : $code);
        $out = '';
        $heredocHtml = false;
        foreach ($tokens as $i => $t) {
            if ($wrapped && $i === 0) {
                continue; // служебный <?php, которого не было в примере
            }
            if ($t->is(T_START_HEREDOC)) {
                $heredocHtml = (bool)preg_match('/HTML/i', $t->text);
            } elseif ($t->is(T_END_HEREDOC)) {
                $heredocHtml = false;
            }
            if ($t->is(T_INLINE_HTML) || ($heredocHtml && $t->is(T_ENCAPSED_AND_WHITESPACE))) {
                $out .= self::markup($t->text); // шаблон компонента подсвечиваем как шаблон
                continue;
            }
            $class = match (true) {
                $t->is([T_COMMENT, T_DOC_COMMENT]) => 'cm',
                $t->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC, T_END_HEREDOC]) => 'str',
                $t->is(T_VARIABLE) => 'var',
                $t->is([T_LNUMBER, T_DNUMBER]) => 'num',
                $t->is(self::keywords()) => 'kw',
                $t->is(T_STRING) && ($tokens[$i + 1] ?? null)?->text === '(' => 'fn',
                default => null,
            };
            $out .= self::span($class, Renderer::e($t->text));
        }
        return $out;
    }

    /** HTML и шаблоны rig: теги, компоненты, директивы (:attr, @event, r-*), выражения {{ }}, CSS внутри <style>. */
    private static function markup(string $code): string
    {
        $re = '~(<style\b[^>]*>)(.*?)(</style>)|(<!--.*?-->)|(\{\{.*?\}\})|(</?[A-Za-z][\w:.-]*(?:[^>"\']|"[^"]*"|\'[^\']*\')*/?>)~s';
        $out = '';
        $pos = 0;
        preg_match_all($re, $code, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        foreach ($all as $m) {
            $at = $m[0][1];
            $out .= Renderer::e(substr($code, $pos, $at - $pos));
            if ($m[1][0] !== null) {
                $out .= self::tag($m[1][0]) . self::css((string)$m[2][0]) . self::tag($m[3][0]);
            } elseif ($m[4][0] !== null) {
                $out .= self::span('cm', Renderer::e($m[4][0]));
            } elseif ($m[5][0] !== null) {
                $out .= self::span('expr', Renderer::e($m[5][0]));
            } else {
                $out .= self::tag($m[6][0]);
            }
            $pos = $at + strlen($m[0][0]);
        }
        return $out . Renderer::e(substr($code, $pos));
    }

    private static function tag(string $tag): string
    {
        preg_match('~^(</?)([A-Za-z][\w:.-]*)(.*?)(/?>)$~s', $tag, $m);
        $name = $m[2];
        $class = ctype_upper($name[0]) ? 'cmp' : 'tag';
        $attrs = (string)preg_replace_callback(
            '~(\s+)([^\s=>"\'/]+)(?:(\s*=\s*)("[^"]*"|\'[^\']*\'))?~',
            static function (array $a): string {
                $n = $a[2];
                $cls = str_starts_with($n, ':') || str_starts_with($n, '@') || str_starts_with($n, 'r-') || str_starts_with($n, '#') ? 'dir' : 'attr';
                $v = isset($a[4]) ? Renderer::e($a[3]) . self::span('str', Renderer::e($a[4])) : '';
                return $a[1] . self::span($cls, Renderer::e($n)) . $v;
            },
            $m[3],
        );
        return self::span($class, Renderer::e($m[1] . $name)) . $attrs . self::span($class, Renderer::e($m[4]));
    }

    private static function shell(string $code): string
    {
        $out = [];
        foreach (explode("\n", $code) as $line) {
            if (preg_match('/^(\s*)(#.*)$/', $line, $m)) {
                $out[] = Renderer::e($m[1]) . self::span('cm', Renderer::e($m[2]));
                continue;
            }
            // первое слово подсвечиваем как команду; в конце строки может быть комментарий
            [$body, $comment] = array_pad(preg_split('/(?<=\s)#(?=\s)/', $line, 2), 2, null);
            $html = (string)preg_replace_callback('/^(\s*)([\w.\\\\\/-]+)/', static fn (array $m) => $m[1] . self::span('kw', $m[2]), Renderer::e($body));
            $html = (string)preg_replace('/(?<=\s)(--?[\w-]+(?:=[^\s<]+)?)/', '<span class="attr">$1</span>', $html);
            $out[] = $html . ($comment !== null ? self::span('cm', '#' . Renderer::e($comment)) : '');
        }
        return implode("\n", $out);
    }

    private static function css(string $code): string
    {
        $e = Renderer::e($code);
        $e = (string)preg_replace('~(/\*.*?\*/)~s', '<span class="cm">$1</span>', $e);
        $e = (string)preg_replace('~([\w-]+)(\s*:)(?![^{]*\{)~', '<span class="attr">$1</span>$2', $e);
        return (string)preg_replace('~^([^{}\n]+)(\{)~m', '<span class="tag">$1</span>$2', $e);
    }

    private static function span(?string $class, string $html): string
    {
        return $class === null || $html === '' ? $html : "<span class=\"{$class}\">{$html}</span>";
    }

    /** Разрезать HTML по строкам, закрывая и снова открывая span на переносах. */
    private static function splitLines(string $html): array
    {
        $lines = [];
        $open = [];
        $cur = '';
        foreach (preg_split('~(<span class="[^"]*">|</span>|\n)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if ($part === "\n") {
                $lines[] = $cur . str_repeat('</span>', count($open));
                $cur = implode('', $open);
            } elseif (str_starts_with($part, '<span ')) {
                $open[] = $part;
                $cur .= $part;
            } elseif ($part === '</span>') {
                array_pop($open);
                $cur .= $part;
            } else {
                $cur .= $part;
            }
        }
        $lines[] = $cur;
        return $lines;
    }

    /** @return list<int> */
    private static function keywords(): array
    {
        static $k = null;
        return $k ??= [
            T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG, T_ATTRIBUTE, T_ABSTRACT, T_ARRAY, T_AS, T_BREAK, T_CASE, T_CATCH,
            T_CLASS, T_CONST, T_CONTINUE, T_DECLARE, T_DEFAULT, T_DO, T_ECHO, T_ELSE, T_ELSEIF, T_ENUM, T_EXTENDS, T_FINAL,
            T_FINALLY, T_FN, T_FOR, T_FOREACH, T_FUNCTION, T_GLOBAL, T_IF, T_IMPLEMENTS, T_INCLUDE, T_INCLUDE_ONCE,
            T_INSTANCEOF, T_INTERFACE, T_ISSET, T_MATCH, T_NAMESPACE, T_NEW, T_PRIVATE, T_PROTECTED, T_PUBLIC, T_READONLY,
            T_REQUIRE, T_REQUIRE_ONCE, T_RETURN, T_STATIC, T_SWITCH, T_THROW, T_TRAIT, T_TRY, T_UNSET, T_USE, T_WHILE, T_YIELD,
        ];
    }
}
