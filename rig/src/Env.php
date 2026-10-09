<?php

declare(strict_types=1);

namespace Rig;

/**
 * Переменные окружения из файла .env в корне проекта.
 *
 *   APP_URL=https://example.com
 *   DB_PASSWORD="пароль с пробелами"   # комментарий
 *   MAIL_FROM=${APP_NAME} <noreply@example.com>
 *
 * Настоящие переменные окружения (сервер, systemd, nginx fastcgi_param) важнее файла: .env их не перезаписывает.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                throw new \RuntimeException(basename($file) . ', строка ' . ($i + 1) . ': ожидается ИМЯ=значение');
            }
            [, $name, $raw] = $m;
            if (self::real($name) !== null) {
                continue; // настоящая переменная окружения важнее
            }
            self::$vars[$name] = self::parse($raw);
        }
    }

    /**
     * Значение переменной. Слова true/false/null/empty превращаются в true, false, null и ''.
     */
    public static function get(string $name, mixed $default = null): mixed
    {
        $v = self::real($name) ?? self::$vars[$name] ?? null;
        if ($v === null) {
            return $default;
        }
        return match (strtolower($v)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $v,
        };
    }

    private static function real(string $name): ?string
    {
        $v = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        return is_string($v) ? $v : null;
    }

    private static function parse(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $q = $raw[0];
        if ($q === '"' || $q === "'") {
            $end = strpos($raw, $q, 1);
            while ($end !== false && $q === '"' && $raw[$end - 1] === '\\') {
                $end = strpos($raw, $q, $end + 1);
            }
            $value = substr($raw, 1, ($end === false ? strlen($raw) : $end) - 1);
            if ($q === "'") {
                return $value; // в одинарных кавычках значение берётся как есть
            }
            $value = strtr($value, ['\\n' => "\n", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\']);
            return self::interpolate($value);
        }
        // без кавычек: комментарий начинается с « #»
        $value = trim((string)preg_replace('/\s+#.*$/', '', $raw));
        return self::interpolate($value);
    }

    /** ${ИМЯ} подставляет значение другой переменной. */
    private static function interpolate(string $v): string
    {
        return (string)preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/', static fn (array $m): string => (string)(self::real($m[1]) ?? self::$vars[$m[1]] ?? ''), $v);
    }
}
