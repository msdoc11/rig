<?php

declare(strict_types=1);

namespace Rig;

/** Ошибки проверки полей запроса: 422 и список полей с сообщениями. */
final class ValidationError extends HttpError
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(422, 'Проверьте поля: ' . implode(', ', array_keys($errors)));
    }
}
