# Страницы и layout

Страницы лежат в `app/pages/` как обычные PHP-файлы. Адрес совпадает с путём: `/about` открывает `pages/about.php`, а `/docs` открывает `pages/docs/index.php`.

```php
<?php $title = 'Задачи'; ?>
<h1>Задачи</h1>

<TodoList />
```

Сначала выполняется PHP, затем теги компонентов (PascalCase) заменяются на отрендеренные компоненты. Внутри `<script>`, `<style>`, `<textarea>`, `<pre>` теги не обрабатываются.

## Динамические адреса

Файл с именем в квадратных скобках принимает любой последний сегмент адреса:

```text
pages/docs/[slug].php       /docs/state, /docs/actions …
```

Значение доступно в `$params`:

```php
<?php
$page = Docs::page($params['slug']);   // нет такой страницы — Docs бросает HttpError(404)
$title = $page['title'];
?>
```

## Layout

Общая обёртка страниц лежит в `app/layouts/default.php`. Другой layout выбирается в самой странице: `<?php $layout = 'demo'; ?>`.

```php
<!doctype html>
<html lang="ru">
<head>
  <title><?= htmlspecialchars($title) ?></title>
  <?= Rig\Page::styles() ?>
</head>
<body>
  <header>…</header>
  <?= $content ?>
  <?= Rig\Page::scripts() ?>
</body>
</html>
```

`Page::styles()` выводит стили компонентов, `Page::scripts()` выводит описания компонентов и рантайм. Компоненты в layout (меню, корзина, лента) сохраняются при переходах между страницами.

## Страницы ошибок

Страницы ошибок `app/errors/404.php`, `app/errors/500.php` (подходит любой код) и общая `app/errors/error.php` устроены как обычные страницы с layout и компонентами. Доступны `$status` и `$message` (текст есть только у ожидаемых ошибок вроде 403/404).

Из своего кода: `throw new Rig\HttpError(403, 'Нет доступа');`

В режиме разработки ошибки 5xx показывает [страница разработчика](/docs/dev), на продакшене показывается ваша страница, а подробности пишутся в `error_log`.
