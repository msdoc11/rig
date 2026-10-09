# Действия и валидация

Действием называется публичный метод с `#[Action]`. Из шаблона можно вызвать только такие методы и методы с `#[On]`.

```php
#[Action(optimistic: "todos = whereNot(todos, 'id', id)")]
public function remove(int $id): void
{
    TodoStore::remove($id);
    $this->todos = TodoStore::all();
}
```

```html
<button @click="remove(t.id)">×</button>
```

- Аргументы приводятся к типам параметров; неподходящее значение даёт ошибку 422.
- Действия одного компонента выполняются по очереди.
- Пока идут запросы, у корня компонента есть атрибут `r-busy`, а в шаблоне доступны `$busy` и `$pending.имя`.

## Optimistic: отклик до ответа сервера

`optimistic` задаёт выражение, которое браузер применяет сразу. В нём доступны параметры метода по именам. Когда приходит ответ, значения сервера заменяют optimistic-значения, поэтому при сбое интерфейс вернётся к состоянию сервера.

```php
#[Action(optimistic: 'count = count + step')]
public function increment(): void
{
    $this->count += $this->step;
}
```

Три быстрых клика сразу покажут +3, хотя запросы ещё идут по очереди.

## Состояние запросов в шаблоне

```html
<button @click="more" :disabled="$busy">
  {{ $pending.more ? 'Загружаем…' : 'Показать ещё' }}
</button>
<li r-for="i in ($pending.more ? [1, 2, 3] : [])" :key="'sk' + i">
  <div class="r-sk r-sk-block"></div>
</li>
```

## Валидация

```php
#[Action]
public function add(): void
{
    $this->validate(['title' => 'required|min:3|max:80']);
    TodoStore::add($this->title);
    $this->title = '';
}
```

`validate()` прерывает действие, ошибки уходят в `$errors`:

```html
<input r-model="title" :aria-invalid="$errors.title ? 'true' : null">
<p r-if="$errors.title" class="error">{{ $errors.title }}</p>
```

Правила: `required`, `min`, `max`, `email`, `int`, `numeric`. Своя ошибка: `$this->addError('field', 'текст')`.
