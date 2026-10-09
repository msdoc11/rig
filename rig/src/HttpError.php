<?php

declare(strict_types=1);

namespace Rig;

/** HTTP-ошибка с кодом ответа: throw new HttpError(403, 'Нет доступа') или abort(403). */
class HttpError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
