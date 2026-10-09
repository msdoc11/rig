# Вложенные компоненты

Компонент вставляется в шаблон другого компонента тегом. Данные идут вниз через `#[Prop]`, а действия пользователя вверх через `emit`.

```php
final class TodoItem extends Component
{
    public const TAG = 'li';

    #[Prop] public array $todo = [];   // задаёт родитель, внутри — только чтение
}
?>

<template>
  <input type="checkbox" :checked="todo.done" @change="emit('toggle', $event.target.checked)">
  {{ todo.title }}
  <button @click="emit('remove')">×</button>
</template>
```

В шаблоне родителя:

```html
<TodoItem r-for="t in todos" :key="t.id" :todo="t"
          @toggle="t.done = $event; setDone(t.id, $event)"
          @remove="remove(t.id)" />
```

## Свойства

`:prop="expr"` и `prop="текст"` задают свойства из `#[Prop]`; остальные атрибуты уходят на элемент-обёртку. `#[Prop]` приходит из браузера с каждым запросом, поэтому проверяйте его, как любой ввод.

## События родителю

`emit('имя', данные)` в шаблоне или `$this->emit('имя', $data)` в действии вызывает `@имя` у родителя; данные доступны в `$event`.

## Слоты

```php
final class Card extends Component
{
    #[Prop] public string $title = '';
}
?>

<template>
  <section class="card">
    <h2 r-if="title">{{ title }}</h2>
    <slot></slot>
    <footer r-if="$slots.footer"><slot name="footer"></slot></footer>
  </section>
</template>
```

```html
<Card title="Задачи">
  <TodoList />
  <template #footer>Осталось 3 задачи</template>
</Card>
```

Содержимое слота принадлежит родителю: его переменные и его действия. `$slots.footer` показывает, передан ли слот. У `<slot>` может быть запасное содержимое: `<slot name="footer">Пусто</slot>`.

## Компоненты, появившиеся в браузере

У каждого вложенного компонента своё состояние и свои действия. Компонент, которого не было в HTML с сервера (новый элемент списка), рисуется в браузере сразу; если у него есть `mount()` или серверные `#[Computed]`, он сам спросит сервер.
