<?php

declare(strict_types=1);

namespace Rig;

/**
 * Базовый класс компонента.
 *
 * Публичные свойства хранят состояние. По умолчанию оно серверное: живёт в PHP,
 * между запросами хранится в подписанном снимке на странице.
 *   #[Client]:             состояние только в браузере, без запросов (вкладки, модалки).
 *   #[Locked]:             клиент не может прислать новое значение.
 *   #[Computed('expr')]:   вычисляется из выражения и на сервере, и в браузере мгновенно.
 *   #[Computed] на методе: вычисляется только на сервере.
 * Публичные методы с #[Action] можно вызывать из шаблона: @click="save".
 */
abstract class Component
{
    /** Тег элемента-обёртки компонента. Для элементов списка подходит 'li', для строк таблицы 'tr'. */
    public const TAG = 'div';

    private array $rigErrors = [];
    private array $rigEvents = [];
    private array $rigEmits = [];

    /**
     * Шаблон, стиль и скелетон обычно пишутся блоками после класса: <template>, <style>, <skeleton>.
     * Методы ниже задают их вторым способом, строкой из PHP. Если метод есть, он важнее блока.
     */
    public function template(): string
    {
        return '';
    }

    /**
     * Заглушка, пока грузятся данные компонента с #[Lazy]. Тот же синтаксис, что у шаблона,
     * доступны #[Prop] и значения по умолчанию. Готовые блоки: class="r-sk" (+ r-sk-line, r-sk-block).
     */
    public function skeleton(): string
    {
        return '<div class="r-sk r-sk-block"></div>';
    }

    /** CSS компонента. Стили изолированы: действуют только внутри этого компонента. */
    public function style(): string
    {
        return '';
    }

    /** Добавить ошибку к полю. В шаблоне: {{ $errors.title }} */
    public function addError(string $field, string $message): void
    {
        $this->rigErrors[$field] ??= $message;
    }

    public function hasErrors(): bool
    {
        return $this->rigErrors !== [];
    }

    /**
     * Проверить поля по правилам: ['title' => 'required|min:3|max:80'].
     * Если есть ошибки, действие прерывается, ошибки уходят в $errors.
     */
    protected function validate(array $rules): void
    {
        foreach ($rules as $field => $spec) {
            $message = Validator::check($this->$field ?? null, is_array($spec) ? $spec : explode('|', $spec));
            if ($message !== null) {
                $this->addError($field, $message);
            }
        }
        if ($this->rigErrors) {
            throw new ValidationFailed();
        }
    }

    /** Событие компонентам на этой странице, у которых есть метод с #[On('имя')]. */
    protected function dispatch(string $event, mixed $data = null): void
    {
        $this->rigEvents[] = [$event, $data];
    }

    /**
     * Событие всем остальным открытым страницам: другим вкладкам и другим пользователям.
     * Доставляет хаб (rig dev или rig push). Эта страница его не получает,
     * для неё есть dispatch().
     */
    protected function broadcast(string $event, mixed $data = null): void
    {
        Push::publish($event, $data);
    }

    /** Событие родительскому компоненту: <TodoItem @rename="…"> получит $event = $data. */
    protected function emit(string $event, mixed $data = null): void
    {
        $this->rigEmits[] = [$event, $data];
    }

    /** @internal */
    final public function rigPullEmits(): array
    {
        $emits = $this->rigEmits;
        $this->rigEmits = [];
        return $emits;
    }

    /** @internal */
    final public function rigErrors(): array
    {
        return $this->rigErrors;
    }

    /** @internal */
    final public function rigPullEvents(): array
    {
        $events = $this->rigEvents;
        $this->rigEvents = [];
        return $events;
    }
}
