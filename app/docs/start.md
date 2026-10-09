# Быстрый старт

Нужен только PHP 8.1 или новее. Composer, Node и отдельный веб-сервер для разработки не нужны.

## Установить команду rig

Команду ставят один раз, после этого `rig` работает в любом терминале и в любом проекте rig: она ищет `rig/cli.php` в текущей папке и выше.

```bash
.\rig install        # PowerShell
rig install          # cmd
./rig.sh install     # Linux, macOS, Git Bash
```

На Windows обёртка ставится в `%USERPROFILE%\.rig\bin` и добавляется в PATH пользователя, поэтому откройте новое окно терминала. Убрать: `rig uninstall`.

## Запустить

```bash
rig dev
```

Команда проверит PHP, скомпилирует все компоненты, поднимет сервер приложения и хаб для HMR и событий:

```text
  rig v0.5.0  ·  PHP 8.4.19  ·  dev

  ✔ Окружение (PHP 8.4.19)                          0 ms
  ✔ Компоненты (6)                                  5 ms
  ✔ Хаб: HMR и события с сервера                    8 ms
  ✔ Сервер приложения                               189 ms

  ╭──────────────────────────────────────────╮
  │   rig dev  готово за 205 ms              │
  │   ➜  Local:    http://127.0.0.1:8000/    │
  │   ➜  Network:  добавьте --host           │
  ╰──────────────────────────────────────────╯
```

Остановить сервер можно через `Ctrl+C` в этом окне или командой `rig stop` из любого другого.

## Структура проекта

```text
app/
  components/   компоненты: App\Components\*
  pages/        страницы: /about открывает pages/about.php
  layouts/      обёртки страниц
  errors/       страницы ошибок: 404.php, error.php
  lib/          прочие классы приложения: App\*
rig/            фреймворк
public/         корень сайта, index.php
config.php      настройки; режим — переменная RIG_ENV
storage/        кеш, ключ приложения, очередь событий
```

## Первый компонент

Создайте `app/components/Hello.php`:

```php
<?php

namespace App\Components;

use Rig\Component;

final class Hello extends Component
{
    public string $name = '';
}
?>

<template>
  <input r-model="name" placeholder="Как вас зовут?">
  <p r-if="name">Привет, {{ name }}!</p>
</template>

<style>
  p { font-size: 20px; }
</style>
```

Сверху идёт PHP-класс с состоянием и логикой, ниже шаблон и стили. Подробнее в разделе [Компоненты](/docs/components).

Вставьте его на страницу `app/pages/index.php`:

```html
<Hello />
```

Откройте страницу и начните печатать: приветствие появится сразу. Поменяйте шаблон и сохраните: компонент обновится без перезагрузки, введённое имя останется.
