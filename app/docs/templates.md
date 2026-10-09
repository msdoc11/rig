# Шаблоны

Шаблон пишется на HTML с выражениями и директивами. Ошибки в нём (неизвестная переменная, незакрытый тег) находятся при компиляции, в сообщении есть номер строки и подсказка «может быть, «count»?».

| Синтаксис | Что делает |
|---|---|
| `{{ expr }}` | вывод, всегда экранируется |
| `:attr="expr"` | атрибут; `false`/`null` убирают его, `true` ставит без значения |
| `:class="{active: on}"` | классы, объединяются со статическим `class` |
| `:style="{color: c}"` | стили |
| `@click="save"` | вызов действия на сервере |
| `@click="remove(t.id)"` | действие с аргументами |
| `@click="open = !open"` | изменение состояния в браузере |
| `@click="t.done = true; save(t.id)"` | несколько выражений через `;` |
| `@click="emit('close')"` | событие родителю |
| `@click="copy(text)"` | скопировать текст в буфер обмена |
| `r-model="title"` | двусторонняя привязка input, textarea, select, checkbox, radio |
| `r-if` / `r-else-if` / `r-else` | условный рендер |
| `r-show="expr"` | скрыть через `display:none` |
| `r-for="t in todos"` + `:key="t.id"` | цикл; с индексом: `(t, i) in todos` |
| `$errors.title` | ошибка валидации поля |
| `$busy`, `$pending.имя` | идут запросы; выполняется действие «имя» |
| `$event` | событие браузера или данные `emit` |
| `autofocus` | фокус на элементе, когда он появляется |

## Модификаторы

События: `.prevent`, `.stop`, `.self`, клавиши `.enter`, `.esc`, `.space`, `.tab`, `.up`, `.down`.

```html
<form @submit.prevent="add">
<input @keydown.enter="save" @keydown.esc="editing = false">
```

Модификаторы `r-model`: `.live` отправляет значение на сервер через 0,3 с после ввода, `.lazy` обновляет его по событию change, `.number` приводит к числу, `.trim` убирает пробелы по краям.

## Выражения

Выражения пишутся на небольшом отдельном языке (это не PHP и не JS). Одни и те же выражения исполняются на сервере при рендере и в браузере.

- литералы: `1`, `2.5`, `'текст'`, `true`, `null`, `[1, 2]`, `{a: 1}`;
- поля: `a.b`, `a[0]`, `a['key']`; отсутствующее поле даёт `null`, а не ошибку;
- операторы: `+ - * / %`, `== != < > <= >=`, `&& || ! ??`, `a ? b : c`;
- `+` склеивает строки и списки: `'Привет, ' + name`, `todos + [item]`;
- ложные значения: `null`, `false`, `0`, `''`, пустой список.

## Функции

`len upper lower trim round fixed abs floor ceil min max json join keys values includes push without where whereNot pluck sum slice plural string number`

Функции вызываются как обычно или через конвейер (`price | fixed(2)` то же, что `fixed(price, 2)`):

```html
<span>{{ left }} {{ left | plural('задача', 'задачи', 'задач') }}</span>
<li r-for="t in where(todos, 'done', false)" :key="t.id">{{ t.title }}</li>
<b>{{ sum(cart, 'price') | fixed(2) }} ₽</b>
```
