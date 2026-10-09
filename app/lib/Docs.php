<?php

declare(strict_types=1);

namespace App;

use Rig\HttpError;

/** Документация: порядок разделов и загрузка страниц из app/docs/*.md. */
final class Docs
{
    /** Группы и страницы в порядке чтения. Ключ: адрес, значение: заголовок в меню. */
    public const NAV = [
        'Начало' => ['' => 'Введение', 'start' => 'Быстрый старт'],
        'Основы' => [
            'components' => 'Компоненты',
            'state' => 'Состояние',
            'templates' => 'Шаблоны',
            'actions' => 'Действия и валидация',
            'nesting' => 'Вложенные компоненты',
        ],
        'Интерфейс' => [
            'ui' => 'r-model, общее состояние, анимации',
            'js' => 'Свой JavaScript',
            'i18n' => 'Переводы и теги head',
        ],
        'Страницы' => [
            'pages' => 'Страницы и layout',
            'navigation' => 'Переходы и кеш',
            'lazy' => 'Ленивая загрузка',
            'static' => 'Статический сайт',
        ],
        'Данные и доступ' => [
            'database' => 'База данных',
            'auth' => 'Вход, роли, middleware',
            'api' => 'API',
            'files' => 'Загрузка файлов',
        ],
        'Живые данные' => [
            'events' => 'События и хаб',
            'background' => 'Очереди, почта, расписание',
        ],
        'Инструменты' => [
            'config' => 'Настройки, журнал, кеш',
            'dev' => 'Разработка и ошибки',
            'production' => 'Продакшен',
            'internals' => 'Как это устроено',
        ],
    ];

    /** @return array{html:string, title:string, toc:list<array{id:string,text:string}>, prev:?array, next:?array, slug:string} */
    public static function page(string $slug): array
    {
        $flat = [];
        foreach (self::NAV as $pages) {
            foreach ($pages as $s => $t) {
                $flat[(string)$s] = $t;
            }
        }
        if (!array_key_exists($slug, $flat)) {
            throw new HttpError(404, 'Такого раздела документации нет');
        }
        $file = dirname(__DIR__) . '/docs/' . ($slug === '' ? 'index' : $slug) . '.md';
        $md = new Markdown();
        $html = $md->render((string)@file_get_contents($file));
        $keys = array_keys($flat);
        $at = array_search($slug, $keys, true);
        $link = static fn (?string $s) => $s === null ? null : ['href' => '/docs' . ($s === '' ? '' : "/{$s}"), 'title' => $flat[$s]];
        return [
            'html' => $html,
            'title' => $md->title ?: $flat[$slug],
            'toc' => $md->toc,
            'prev' => $link($at > 0 ? (string)$keys[$at - 1] : null),
            'next' => $link($at < count($keys) - 1 ? (string)$keys[$at + 1] : null),
            'slug' => $slug,
        ];
    }
}
