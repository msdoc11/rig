<?php

declare(strict_types=1);

namespace Rig;

/** Вставки для страниц и шаблонов-обёрток (layouts). */
final class Page
{
    private static ?App $app = null;

    public static function boot(App $app): void
    {
        self::$app = $app;
    }

    public static function component(string $name, array $props = []): string
    {
        return self::app()->mount($name, $props);
    }

    public const STYLES = '<!--rig:styles-->';
    public const SCRIPTS = '<!--rig:scripts-->';
    public const HEAD = '<!--rig:head-->';

    /**
     * Middleware страницы. Вызывается первой строкой файла страницы: Page::middleware('auth', 'role:admin').
     * Проверяется сразу; действия компонентов этой страницы проверяются теми же middleware.
     */
    public static function middleware(string ...$names): void
    {
        self::app()->guardPage($names);
    }

    /** Теги <head>: title, description, Open Graph, canonical (см. Rig\Head). Ставится в <head> layout. */
    public static function head(): string
    {
        return self::HEAD;
    }

    /** Блоки для скелетонов: r-sk (мерцающая плашка), r-sk-line (строка текста), r-sk-block (картинка/карточка). */
    private const BASE_CSS = '.r-sk{display:block;border-radius:8px;background:linear-gradient(90deg,var(--r-sk-a) 25%,var(--r-sk-b) 37%,var(--r-sk-a) 63%);'
        . 'background-size:400% 100%;animation:r-sk 1.4s ease infinite;--r-sk-a:rgba(0,0,0,.06);--r-sk-b:rgba(0,0,0,.11)}'
        . '.r-sk-line{height:.9em;margin:.45em 0}.r-sk-block{height:120px}'
        . '@keyframes r-sk{from{background-position:100% 50%}to{background-position:0 50%}}'
        . '@media (prefers-color-scheme:dark){.r-sk{--r-sk-a:rgba(255,255,255,.06);--r-sk-b:rgba(255,255,255,.12)}}'
        . '@media (prefers-reduced-motion:reduce){.r-sk{animation:none}}'
        // r-transition: готовые fade, slide, scale; для своих нужны классы {имя}-enter-from/-active/-to и {имя}-leave-…
        . '.fade-enter-active,.fade-leave-active{transition:opacity .2s ease}.fade-enter-from,.fade-leave-to{opacity:0}'
        . '.slide-enter-active,.slide-leave-active{transition:opacity .22s ease,transform .22s ease}'
        . '.slide-enter-from,.slide-leave-to{opacity:0;transform:translateY(-8px)}'
        . '.scale-enter-active,.scale-leave-active{transition:opacity .18s ease,transform .18s ease}'
        . '.scale-enter-from,.scale-leave-to{opacity:0;transform:scale(.95)}'
        . '@media (prefers-reduced-motion:reduce){[class*="-enter-active"],[class*="-leave-active"]{transition:none!important;animation:none!important}}';

    /**
     * Стили компонентов страницы. Ставится в <head>.
     * Возвращает метку: настоящие стили подставляются, когда отрендерены все компоненты, в том числе из layout.
     */
    public static function styles(): string
    {
        return self::STYLES;
    }

    /** Описания компонентов и rig.js. Ставится в конце <body>. */
    public static function scripts(): string
    {
        return self::SCRIPTS;
    }

    /** @internal */
    public static function renderStyles(): string
    {
        $out = '<style id="rig-base">' . self::BASE_CSS . "</style>\n";
        foreach (self::app()->usedComponents() as $name => $def) {
            if ($def['css'] !== '') {
                $out .= "<style id=\"rig-css-{$name}\">{$def['css']}</style>\n";
            }
        }
        return $out;
    }

    /** @internal */
    public static function renderScripts(): string
    {
        $app = self::app();
        $static = (bool)Config::get('static', false); // rig generate: страница без сервера, то есть без сессии, хаба и переходов JSON-ом
        $boot = [
            'base' => '/_rig',
            'debug' => $app->debug,
            'layout' => $app->layoutName(),
            'hub' => $static ? null : $app->hubConfig(),
            'speculation' => $static ? false : $app->speculation,
            'pageCache' => $static ? 0 : $app->pageCache,
            'csrf' => $static ? null : Csrf::token(),
            'i18n' => I18n::client(),
            'jsv' => $app->jsVersion(),
            'static' => $static,
            'defs' => (object)$app->clientDefs(),
        ];
        $json = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        $out = "<script type=\"application/json\" id=\"rig-boot\">{$json}</script>\n"
            . '<script src="' . $app->runtimeUrl() . "\" defer></script>\n";
        if ($app->speculation !== false && !$static) {
            // ссылки с r-reload rig не перехватывает, поэтому для них браузер сам загрузит страницу заранее (Speculation Rules API)
            $rules = ['prefetch' => [[
                'where' => ['and' => [
                    ['href_matches' => '/*'],
                    ['selector_matches' => 'a[r-reload]'],
                    ['not' => ['selector_matches' => '[r-no-prefetch]']],
                    ['not' => ['href_matches' => '/*\\?*']],
                ]],
                'eagerness' => $app->speculation,
            ]]];
            $out .= '<script type="speculationrules">' . json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . "</script>\n";
        }
        return $out;
    }

    private static function app(): App
    {
        return self::$app ?? throw new \LogicException('Приложение rig не запущено');
    }
}
