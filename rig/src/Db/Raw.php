<?php

declare(strict_types=1);

namespace Rig\Db;

/** SQL-выражение без экранирования: DB::raw('COUNT(*) AS total'). Только для своего кода, не для ввода пользователя. */
final class Raw
{
    public function __construct(public readonly string $sql)
    {
    }
}
