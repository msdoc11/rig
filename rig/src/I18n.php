<?php

declare(strict_types=1);

namespace Rig;

/**
 * Переводы интерфейса: файлы lang/{язык}.php возвращают массив (можно вложенный).
 *
 *   // lang/ru.php
 *   return [
 *       'cart' => [
 *           'title' => 'Корзина',
 *           'items' => '{n} товар|{n} товара|{n} товаров',   // формы числа: одна|несколько|много
 *       ],
 *   ];
 *
 * В PHP и в шаблонах компонентов одинаково:  t('cart.title'),  t('cart.items', {n: count})
 * Язык берётся из cookie locale, затем из заголовка Accept-Language, затем из 'i18n.default'. Сменить: I18n::setLocale('en') в действии.
 */
final class I18n
{
    private static ?string $locale = null;
    private static bool $changed = false;

    /** В этом запросе сменили язык: после redirect() страница загрузится целиком (layout тоже переведён). */
    public static function changed(): bool
    {
        return self::$changed;
    }
    /** @var array<string,array<string,string>> язык => плоский словарь */
    private static array $dict = [];

    /** @return list<string> */
    public static function locales(): array
    {
        return array_values((array)Config::get('i18n.locales', ['ru']));
    }

    public static function locale(): string
    {
        if (self::$locale !== null) {
            return self::$locale;
        }
        $locales = self::locales();
        $cookie = $_COOKIE['locale'] ?? null;
        if (is_string($cookie) && in_array($cookie, $locales, true)) {
            return self::$locale = $cookie;
        }
        // Accept-Language: ru-RU,ru;q=0.9,en;q=0.8, выбираем по убыванию q
        $best = [];
        foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
            if (preg_match('/^\s*([a-z]{2,3})(?:-[A-Za-z]+)?\s*(?:;\s*q=([\d.]+))?/i', $part, $m)) {
                $lang = strtolower($m[1]);
                $q = isset($m[2]) ? (float)$m[2] : 1.0;
                if (in_array($lang, $locales, true) && $q > ($best[$lang] ?? -1)) {
                    $best[$lang] = $q;
                }
            }
        }
        arsort($best);
        return self::$locale = (string)(array_key_first($best) ?? Config::get('i18n.default', $locales[0] ?? 'ru'));
    }

    /** Сменить язык: на этот запрос и (cookie на год) на следующие. */
    public static function setLocale(string $locale): void
    {
        if (!in_array($locale, self::locales(), true)) {
            throw new \InvalidArgumentException("Язык «{$locale}» не указан в 'i18n.locales'");
        }
        self::$locale = $locale;
        self::$changed = true;
        if (!headers_sent() && PHP_SAPI !== 'cli') {
            setcookie('locale', $locale, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
        }
    }

    public static function t(string $key, array $params = [], ?string $locale = null): string
    {
        $locale ??= self::locale();
        $text = self::dict($locale)[$key] ?? self::dict((string)Config::get('i18n.default', 'ru'))[$key] ?? $key;
        $n = $params['n'] ?? $params['count'] ?? null;
        if ($n !== null && str_contains($text, '|')) {
            $forms = explode('|', $text);
            $text = $forms[min(count($forms) - 1, self::form($locale, (float)$n))];
        }
        return (string)preg_replace_callback('/\{(\w+)\}/', static fn (array $m) => array_key_exists($m[1], $params) ? Expr\Evaluator::str($params[$m[1]]) : $m[0], $text);
    }

    /** Номер формы множественного числа. Русский и другие славянские: 3 формы, остальные: 2. */
    public static function form(string $locale, float $n): int
    {
        $n = abs($n);
        $i = (int)$n;
        if (in_array($locale, ['ru', 'uk', 'be', 'sr', 'hr', 'bs'], true)) {
            if ($i != $n) {
                return 1; // дробные: «1,5 товара»
            }
            $m10 = $i % 10;
            $m100 = $i % 100;
            return $m10 === 1 && $m100 !== 11 ? 0 : ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14) ? 1 : 2);
        }
        return $n == 1 ? 0 : 1;
    }

    /** Для rig.js: язык и словарь, чтобы t() в шаблонах работал в браузере без запросов. */
    public static function client(): ?array
    {
        $dir = Config::path('lang');
        if (!is_dir($dir)) {
            return null;
        }
        $locale = self::locale();
        $dict = self::dict($locale) + self::dict((string)Config::get('i18n.default', 'ru'));
        return ['locale' => $locale, 'messages' => (object)$dict];
    }

    /** Для тестов и долгих процессов. */
    public static function reset(): void
    {
        self::$locale = null;
        self::$dict = [];
    }

    /** @return array<string,string> */
    private static function dict(string $locale): array
    {
        if (!isset(self::$dict[$locale])) {
            self::$dict[$locale] = [];
            if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale)) {
                $file = Config::path("lang/{$locale}.php");
                if (is_file($file)) {
                    self::$dict[$locale] = self::flatten((array)(require $file));
                }
            }
        }
        return self::$dict[$locale];
    }

    private static function flatten(array $a, string $prefix = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $key = $prefix === '' ? (string)$k : "{$prefix}.{$k}";
            if (is_array($v)) {
                $out += self::flatten($v, $key);
            } else {
                $out[$key] = (string)$v;
            }
        }
        return $out;
    }
}
