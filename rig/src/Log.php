<?php

declare(strict_types=1);

namespace Rig;

/**
 * Журнал приложения: storage/logs/rig-ГГГГ-ММ-ДД.log, файл на каждый день.
 *
 *   Log::info('Заказ оформлен', ['id' => 42]);
 *   Log::error($exception);
 *
 * Уровень задаётся в 'log.level' в config.php (или LOG_LEVEL в .env), записи ниже уровня не пишутся.
 * Ошибки 5xx фреймворк записывает сам.
 */
final class Log
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5];

    public static function debug(string|\Stringable $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string|\Stringable $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function notice(string|\Stringable $message, array $context = []): void
    {
        self::write('notice', $message, $context);
    }

    public static function warning(string|\Stringable $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string|\Stringable|\Throwable $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function critical(string|\Stringable|\Throwable $message, array $context = []): void
    {
        self::write('critical', $message, $context);
    }

    public static function write(string $level, string|\Stringable|\Throwable $message, array $context = []): void
    {
        $min = self::LEVELS[strtolower((string)Config::get('log.level', 'debug'))] ?? 0;
        if ((self::LEVELS[$level] ?? 4) < $min) {
            return;
        }
        if ($message instanceof \Throwable) {
            $e = $message;
            $message = get_class($e) . ': ' . $e->getMessage() . ' в ' . self::rel($e->getFile()) . ':' . $e->getLine();
            $context += ['trace' => self::trace($e)];
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $level . ': ' . $message;
        if ($context) {
            $trace = $context['trace'] ?? null;
            unset($context['trace']);
            if ($context) {
                $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
            if (is_string($trace)) {
                $line .= "\n" . $trace;
            }
        }
        @file_put_contents(self::path(), $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Файл журнала за день (сегодня по умолчанию). */
    public static function path(?string $date = null): string
    {
        return Config::storage('logs') . '/rig-' . ($date ?? date('Y-m-d')) . '.log';
    }

    private static function trace(\Throwable $e): string
    {
        $out = [];
        foreach (array_slice($e->getTrace(), 0, 12) as $i => $f) {
            $at = isset($f['file']) ? self::rel($f['file']) . ':' . ($f['line'] ?? 0) : '[внутри PHP]';
            $out[] = "  #{$i} " . ($f['class'] ?? '') . ($f['type'] ?? '') . $f['function'] . "() {$at}";
        }
        return implode("\n", $out);
    }

    private static function rel(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $root = Config::root() . '/';
        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }
}
