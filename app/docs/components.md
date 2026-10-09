# Компоненты

Каждый компонент лежит в отдельном файле в `app/components/`. В файле три части: PHP-класс с логикой, шаблон `<template>` и стили `<style>`. Файл подключается автоматически: класс `App\Components\Counter` берётся из `app/components/Counter.php`.

```php
<?php

namespace App\Components;

use Rig\Attr\{Action, Client, Computed, Locked};
use Rig\Component;

final class Counter extends Component
{
    #[Locked] public string $label = 'Счётчик';   // из браузера не изменить
    public int $count = 0;                         // серверное состояние
    #[Client] public bool $help = false;           // только в браузере

    #[Computed('count * 2')] public int $double;   // считается и в PHP, и в браузере

    #[Computed]                                    // считает только сервер
    public function parity(): string
    {
        return $this->count % 2 ? 'нечётное' : 'чётное';
    }

    #[Action(optimistic: 'count = count + 1')]     // браузер применит сразу
    public function increment(): void
    {
        $this->count++;
    }
}
?>

<template>
  <h2>{{ label }}</h2>
  <p>{{ count }} · ×2 = {{ double }} · {{ parity }}</p>
  <button @click="increment">+</button>
  <button @click="help = !help">Подсказка</button>
  <p r-show="help">Текст подсказки</p>
</template>

<style>
  p { color: tomato; }
</style>
```

## Части файла

Первым идёт PHP-класс с `namespace App\Components`, унаследованный от `Rig\Component`. В нём вся логика: состояние, действия, `mount()`, вычисления. Класс закрывается `?>`.

`<template>` содержит HTML-шаблон компонента, его синтаксис описан в разделе [Шаблоны](/docs/templates). PHP в шаблоне нет: шаблон выполняется и на сервере, и в браузере, поэтому `<?php` или `<?=` после класса дают ошибку компиляции. Логику переносите в класс (в `#[Computed]`, `mount()` или действие) и выводите через `{{ }}`.

`<style>` можно не писать. Стили изолированы: селектор `p` из примера действует только внутри этого компонента, потому что rig добавляет к элементам шаблона свой атрибут и переписывает селекторы.

`<skeleton>` задаёт заглушку для компонента с `#[Lazy]`, см. [Ленивая загрузка](/docs/lazy).

Порядок блоков после класса любой, между ними можно оставлять HTML-комментарии. Общий отступ внутри блока убирается, так что отступ можно выбирать любой.

Редактор подсвечивает файл как обычный PHP: класс как PHP, `<template>` как HTML, `<style>` как CSS.

### Шаблон строкой из PHP

Шаблон и стили можно вернуть из методов `template()`, `style()`, `skeleton()`, например если шаблон собирается кодом. Если есть и метод `style()` или `skeleton()`, и одноимённый блок, используется метод. Шаблон так задать нельзя: `template()` вместе с блоком `<template>` даёт ошибку компиляции.

## Начальные данные: mount()

Метод `mount()`, если он есть в классе, вызывается один раз при первом рендере. В нём, например, загружают данные из БД:

```php
public function mount(): void
{
    $this->todos = TodoStore::all();
}
```

## Вставка на страницу

На страницах и в layout компоненты вставляются тегами:

```html
<Counter />
<Counter label="Клики" :count="5" />
<Counter :count="<?= $start ?>" />
```

`label="…"` передаёт строку, `:count="…"` передаёт выражение: числа, `true`, `false`, `null`, списки, объекты, JSON. Из PHP-кода компонент вставляется так: `<?= rig('Counter', ['count' => 5]) ?>`.

## Элемент-обёртка

Каждый компонент на странице обёрнут в элемент с атрибутом `r-c`. По умолчанию это `div`; для элементов списка или строк таблицы задайте тег константой:

```php
public const TAG = 'li';
```

Атрибуты `class`, `style`, `id`, `title`, `role`, `aria-*`, `data-*` со страницы попадают на обёртку.
