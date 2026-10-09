<?php

declare(strict_types=1);

namespace Rig;

/**
 * Теги <head> страницы: заголовок, описание, Open Graph, canonical, свои meta и link.
 *
 *   <?php
 *   $title = 'Каталог';
 *   Rig\Head::set(description: 'Кофе, чай и посуда', image: '/img/catalog.jpg');
 *
 * Open Graph и Twitter-карточка строятся из заголовка, описания и картинки сами.
 * В layout теги выводит <?= Rig\Page::head() ?>; при переходах без перезагрузки rig.js меняет их сам.
 * Значения по умолчанию берутся из 'head' в config.php: title, description, image, site.
 */
final class Head
{
    private static string $title = '';
    private static ?string $description = null;
    private static ?string $image = null;
    private static ?string $canonical = null;
    private static string $type = 'website';
    private static ?string $robots = null;
    /** @var list<array{0:string,1:array<string,string>}> [тег, атрибуты] */
    private static array $extra = [];

    public static function reset(): void
    {
        self::$title = '';
        self::$description = self::$image = self::$canonical = self::$robots = null;
        self::$type = 'website';
        self::$extra = [];
    }

    /**
     * Задать теги страницы. Все параметры необязательны.
     * $robots: false запрещает индексацию (noindex, nofollow), строка выводится как есть.
     */
    public static function set(
        ?string $title = null,
        ?string $description = null,
        ?string $image = null,
        ?string $canonical = null,
        ?string $type = null,
        string|bool|null $robots = null,
    ): void {
        if ($title !== null) {
            self::$title = $title;
        }
        self::$description = $description ?? self::$description;
        self::$image = $image ?? self::$image;
        self::$canonical = $canonical ?? self::$canonical;
        self::$type = $type ?? self::$type;
        if ($robots !== null) {
            self::$robots = $robots === false ? 'noindex, nofollow' : ($robots === true ? null : $robots);
        }
    }

    /** Заголовок из переменной $title страницы (если Head::set() его не задал). */
    public static function title(string $title): void
    {
        if (self::$title === '' && $title !== '') {
            self::$title = $title;
        }
    }

    public static function meta(string $name, string $content): void
    {
        self::$extra[] = ['meta', ['name' => $name, 'content' => $content]];
    }

    public static function property(string $property, string $content): void
    {
        self::$extra[] = ['meta', ['property' => $property, 'content' => $content]];
    }

    public static function link(string $rel, string $href, array $attrs = []): void
    {
        self::$extra[] = ['link', ['rel' => $rel, 'href' => $href] + $attrs];
    }

    public static function fullTitle(): string
    {
        $t = self::$title !== '' ? self::$title : (string)Config::get('head.title', Config::get('app.name', 'rig'));
        return $t;
    }

    /** HTML тегов. Все помечены data-rig-head, rig.js заменяет их при переходе на другую страницу. */
    public static function render(): string
    {
        $title = self::fullTitle();
        $desc = self::$description ?? Config::get('head.description');
        $image = self::$image ?? Config::get('head.image');
        $site = Config::get('head.site', Config::get('app.name'));
        $url = self::$canonical ?? self::absolute(Request::current()->path());
        $tags = [];
        $tags[] = '<title data-rig-head>' . Renderer::e($title) . '</title>';
        $add = static function (string $tag, array $attrs) use (&$tags): void {
            $tags[] = "<{$tag}" . Renderer::attrs($attrs + ['data-rig-head' => '']) . '>';
        };
        if (is_string($desc) && $desc !== '') {
            $add('meta', ['name' => 'description', 'content' => $desc]);
        }
        if (self::$robots !== null) {
            $add('meta', ['name' => 'robots', 'content' => self::$robots]);
        }
        $add('link', ['rel' => 'canonical', 'href' => self::absolute($url)]);
        $add('meta', ['property' => 'og:type', 'content' => self::$type]);
        $add('meta', ['property' => 'og:title', 'content' => $title]);
        if (is_string($desc) && $desc !== '') {
            $add('meta', ['property' => 'og:description', 'content' => $desc]);
        }
        $add('meta', ['property' => 'og:url', 'content' => self::absolute($url)]);
        if (is_string($site) && $site !== '') {
            $add('meta', ['property' => 'og:site_name', 'content' => $site]);
        }
        if (is_string($image) && $image !== '') {
            $add('meta', ['property' => 'og:image', 'content' => self::absolute($image)]);
            $add('meta', ['name' => 'twitter:card', 'content' => 'summary_large_image']);
        }
        foreach (self::$extra as [$tag, $attrs]) {
            $add($tag, $attrs);
        }
        return implode("\n  ", $tags);
    }

    /** Из /path делает https://site/path: Open Graph и canonical требуют полный адрес. */
    private static function absolute(string $url): string
    {
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }
        $base = Env::get('APP_URL');
        if (!is_string($base) || $base === '') {
            $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }
}
