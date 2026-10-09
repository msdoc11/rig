# r-model, общее состояние, анимации

Всё на этой странице работает на [демо «Интерфейс»](/demo/ui).

## r-model на своём компоненте

Своё поле ввода делается компонентом со свойством `#[Prop, Model]`. Присваивание этому свойству в шаблоне уходит родителю:

```php
final class Rating extends Component
{
    #[Prop, Model]
    public int $value = 0;
}
?>

<template>
  <button r-for="n in [1, 2, 3, 4, 5]" :key="n" :class="{on: n <= value}" @click="value = n">★</button>
</template>
```

```html
<Rating r-model="score" />
```

Родитель получает новое значение в `score` и перерисовывает `Rating`. Если `score` является серверным свойством, оно уйдёт на сервер со следующим действием.

## Общее состояние

Все компоненты с одним ключом `#[Shared]` видят одно значение. Когда его меняет один компонент, обновляются все, без событий.

```php
#[Shared('cart', persist: true)]
public array $cart = [];
```

Значение живёт, пока открыта вкладка, и переживает переходы между страницами. С `persist: true` оно переживает и перезагрузку (localStorage) и синхронизируется между вкладками. Как и `#[Client]`, значение не уходит на сервер, поэтому данные, которые нужны PHP, держите в сессии или базе.

## debounce и throttle

```html
<input r-model.live.debounce.400ms="q">          <!-- на сервер — после паузы 400 мс в наборе -->
<input r-model.debounce.300ms="filter">         <!-- состояние меняется после паузы -->
<input @input.debounce.500ms="search">          <!-- обработчик — после паузы -->
<button @click.throttle.1s="save">              <!-- не чаще раза в секунду -->
```

Время указывается в `ms` или `s`. Если его не указать, пауза 300 мс.

## Анимации появления и исчезновения

```html
<div r-if="open" r-transition="scale">…</div>
<p r-show="note" r-transition="fade">…</p>
<li r-for="t in todos" :key="t.id" r-transition="slide">…</li>
```

Готовые анимации: `fade`, `slide`, `scale`. Свои задаются классами в `<style>` компонента:

| Класс | Когда |
|---|---|
| `имя-enter-from` | в момент появления (начальное состояние) |
| `имя-enter-active` | всё время появления (сюда ставят `transition`) |
| `имя-enter-to` | конечное состояние появления |
| `имя-leave-from`, `-leave-active`, `-leave-to` | то же при исчезновении |

```css
.pop-enter-active, .pop-leave-active { transition: opacity .3s, transform .3s; }
.pop-enter-from, .pop-leave-to { opacity: 0; transform: translateY(12px); }
```

Элемент с `r-if` и элемент списка удаляются после анимации; `r-show` скрывает после анимации. С `prefers-reduced-motion` анимации выключаются.
