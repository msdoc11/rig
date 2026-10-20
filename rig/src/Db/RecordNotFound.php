<?php

declare(strict_types=1);

namespace Rig\Db;

/**
 * Запись не найдена: findOrFail(), firstOrFail(). Слой базы про HTTP не знает,
 * в ответ 404 это исключение превращает обработчик запроса (App).
 */
final class RecordNotFound extends \RuntimeException
{
    public function __construct(public readonly string $table, string $message = 'Запись не найдена')
    {
        parent::__construct($message);
    }
}
