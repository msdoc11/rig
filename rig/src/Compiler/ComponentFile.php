<?php

declare(strict_types=1);

namespace Rig\Compiler;

/**
 * Блоки файла компонента. PHP-класс идёт первым, после него разметка и стили:
 *
 *   <?php … final class Hello extends Component { … } ?>
 *
 *   <template> … </template>
 *   <style> … </style>
 *   <skeleton> … </skeleton>   заглушка для #[Lazy]
 *
 * При подключении файла этот HTML не выводится: автозагрузчик глушит вывод (App).
 * Общий отступ блока убирается, как у nowdoc.
 */
final class ComponentFile
{
    private const BLOCKS = ['template', 'style', 'skeleton'];

    /** @return array<string,array{0:string,1:int}> блок => [текст, строка, с которой он начинается] */
    public static function blocks(string $file): array
    {
        $src = str_replace("\r\n", "\n", (string)file_get_contents($file));
        $tail = null;
        $tailLine = 1;
        foreach (token_get_all($src) as $t) {
            [$id, $text, $line] = is_array($t) ? $t : [null, $t, 0];
            if ($tail === null) {
                if ($id === T_CLOSE_TAG) {
                    $tail = '';
                    $tailLine = $line + substr_count($text, "\n");
                }
                continue;
            }
            if ($id === T_OPEN_TAG || $id === T_OPEN_TAG_WITH_ECHO) {
                throw new CompileError(
                    'PHP-код после класса не выполняется: шаблон работает и в браузере. '
                    . 'Перенесите логику в класс — в #[Computed], mount() или действие — и выводите результат через {{ }}',
                    $file,
                    $line,
                );
            }
            $tail .= $text;
        }
        if ($tail === null) {
            return [];
        }

        $blocks = [];
        $pos = 0;
        $n = strlen($tail);
        $lineAt = static fn (int $p): int => $tailLine + substr_count($tail, "\n", 0, $p);
        while (true) {
            // между блоками допустимы только пробелы и HTML-комментарии
            preg_match('~\G(?:\s+|<!--.*?-->)*~s', $tail, $m, 0, $pos);
            $pos += strlen($m[0]);
            if ($pos >= $n) {
                break;
            }
            if (!preg_match('~\G<(' . implode('|', self::BLOCKS) . ')(?:\s[^>]*)?>~i', $tail, $m, 0, $pos)) {
                $got = mb_strimwidth(trim((string)strtok(substr($tail, $pos), "\n")), 0, 50, '…');
                throw new CompileError("После класса ожидаются блоки <template>, <style> или <skeleton>, а здесь «{$got}»", $file, $lineAt($pos));
            }
            $name = strtolower($m[1]);
            if (isset($blocks[$name])) {
                throw new CompileError("Блок <{$name}> встречается дважды", $file, $lineAt($pos));
            }
            $start = $pos + strlen($m[0]);
            $close = self::closing($tail, $name, $start);
            if ($close === null) {
                throw new CompileError("Блок <{$name}> не закрыт: нет </{$name}>", $file, $lineAt($pos));
            }
            $blocks[$name] = [self::dedent(substr($tail, $start, $close[0] - $start)), $lineAt($start)];
            $pos = $close[0] + $close[1];
        }
        return $blocks;
    }

    /** Закрывающий тег блока с учётом вложенных <template #слот>: [смещение, длина]. */
    private static function closing(string $s, string $name, int $from): ?array
    {
        $depth = 0;
        preg_match_all('~<(/?)' . $name . '\b[^>]*>~i', $s, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $from);
        foreach ($mm as $m) {
            if ($m[1][0] === '') {
                if ($name !== 'style') {
                    $depth++;
                }
                continue;
            }
            if ($depth === 0) {
                return [$m[0][1], strlen($m[0][0])];
            }
            $depth--;
        }
        return null;
    }

    private static function dedent(string $s): string
    {
        $lines = explode("\n", $s);
        $min = null;
        foreach ($lines as $l) {
            if (trim($l) !== '') {
                $min = min($min ?? PHP_INT_MAX, strlen($l) - strlen(ltrim($l, " \t")));
            }
        }
        if (!$min) {
            return $s;
        }
        return implode("\n", array_map(static fn (string $l): string => trim($l) === '' ? '' : substr($l, $min), $lines));
    }
}
