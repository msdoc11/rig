<?php

declare(strict_types=1);

namespace Rig;

/**
 * Вывод в терминал: цвета, шаги со спиннером и временем, рамки, таблицы, лог с метками.
 * Если вывод идёт не в терминал (перенаправлен в файл, CI), цвета и анимация отключаются.
 */
final class Console
{
    private const SPIN = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    public readonly bool $tty;

    public function __construct()
    {
        $this->tty = function_exists('sapi_windows_vt100_support')
            ? @sapi_windows_vt100_support(STDOUT, true)
            : (function_exists('posix_isatty') && @posix_isatty(STDOUT));
    }

    // ---------------------------------------------------------------- цвета

    public function c(string $codes, string $text): string
    {
        return $this->tty ? "\e[{$codes}m{$text}\e[0m" : $text;
    }

    public function bold(string $t): string { return $this->c('1', $t); }
    public function dim(string $t): string { return $this->c('2', $t); }
    public function green(string $t): string { return $this->c('32', $t); }
    public function cyan(string $t): string { return $this->c('36', $t); }
    public function yellow(string $t): string { return $this->c('33', $t); }
    public function red(string $t): string { return $this->c('31', $t); }
    public function magenta(string $t): string { return $this->c('35', $t); }

    // ---------------------------------------------------------------- вывод

    public function line(string $text = ''): void
    {
        echo $text . "\n";
    }

    /** Шапка: «rig v0.3.0 · PHP 8.4.19 · dev». */
    public function header(string $mode): void
    {
        $this->line();
        $this->line('  ' . $this->c('1;32', 'rig') . ' ' . $this->dim('v' . App::VERSION)
            . $this->dim('  ·  PHP ' . PHP_VERSION . '  ·  ') . $this->cyan($mode));
        $this->line();
    }

    /**
     * Шаг: пока выполняется, крутится спиннер, затем выводится «✔ подпись  123 ms».
     * $fn может вернуть строку, она допишется к подписи (например, «(6)»).
     */
    public function task(string $label, callable $fn): mixed
    {
        $start = microtime(true);
        if ($this->tty) {
            echo '  ' . $this->cyan(self::SPIN[0]) . ' ' . $label . $this->dim('…');
        }
        try {
            $result = $fn(fn () => $this->spin($label, $start));
        } catch (\Throwable $e) {
            $this->finish('✖', 'red', $label, $start);
            throw $e;
        }
        $suffix = is_string($result) ? ' ' . $this->dim($result) : '';
        $this->finish('✔', 'green', $label . $suffix, $start);
        return $result;
    }

    /** Обновить кадр спиннера (вызывать из долгих циклов внутри task). */
    private function spin(string $label, float $start): void
    {
        if (!$this->tty) {
            return;
        }
        $frame = self::SPIN[(int)((microtime(true) - $start) * 12) % count(self::SPIN)];
        echo "\r\e[2K  " . $this->cyan($frame) . ' ' . $label . $this->dim('…');
    }

    private function finish(string $mark, string $color, string $label, float $start): void
    {
        $ms = $this->ms(microtime(true) - $start);
        $text = '  ' . $this->{$color}($mark) . ' ' . $label;
        $pad = max(2, 52 - $this->width($text));
        $out = $text . str_repeat(' ', $pad) . $this->dim($ms);
        echo ($this->tty ? "\r\e[2K" : '') . $out . "\n";
    }

    public function ok(string $text): void
    {
        $this->line('  ' . $this->green('✔') . ' ' . $text);
    }

    public function warn(string $text): void
    {
        $this->line('  ' . $this->yellow('▲') . ' ' . $text);
    }

    public function error(string $text): void
    {
        $this->line('  ' . $this->red('✖') . ' ' . $text);
    }

    /** Рамка со скруглёнными углами вокруг строк. */
    public function box(array $lines, string $color = '32'): void
    {
        $w = 0;
        foreach ($lines as $l) {
            $w = max($w, $this->width($l));
        }
        $w += 6;
        $edge = fn (string $s) => $this->c($color, $s);
        $this->line('  ' . $edge('╭' . str_repeat('─', $w) . '╮'));
        foreach (['', ...$lines, ''] as $l) {
            $this->line('  ' . $edge('│') . '   ' . $l . str_repeat(' ', $w - 3 - $this->width($l)) . $edge('│'));
        }
        $this->line('  ' . $edge('╰' . str_repeat('─', $w) . '╯'));
    }

    /** Таблица: колонки выравниваются по видимой ширине (цвета не мешают). */
    public function table(array $rows, array $align = []): void
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, $this->width((string)$cell));
            }
        }
        foreach ($rows as $row) {
            $out = [];
            foreach (array_values($row) as $i => $cell) {
                $pad = str_repeat(' ', $widths[$i] - $this->width((string)$cell));
                $out[] = ($align[$i] ?? 'l') === 'r' ? $pad . $cell : $cell . $pad;
            }
            $this->line('    ' . implode('   ', $out));
        }
    }

    /** Строка лога: «10:42:01 [hmr] текст» с цветной меткой. */
    public function log(string $tag, string $text): void
    {
        $colors = ['hmr' => '32', 'push' => '35', 'http' => '33', 'error' => '31', 'tab' => '2', 'queue' => '36', 'cron' => '34'];
        $label = $this->c($colors[$tag] ?? '36', "[{$tag}]");
        $this->line($this->dim(date('H:i:s')) . ' ' . $label . ' ' . $text);
    }

    // ---------------------------------------------------------------- форматы

    public function size(int $bytes): string
    {
        return $bytes < 1024 ? "{$bytes} B" : number_format($bytes / 1024, 1, '.', '') . ' kB';
    }

    public function ms(float $seconds): string
    {
        return $seconds < 1 ? round($seconds * 1000) . ' ms' : number_format($seconds, 2, '.', '') . ' s';
    }

    /** Видимая ширина строки: без ANSI-кодов, с учётом юникода. */
    public function width(string $s): int
    {
        return mb_strwidth((string)preg_replace('/\e\[[0-9;]*m/', '', $s), 'UTF-8');
    }
}
