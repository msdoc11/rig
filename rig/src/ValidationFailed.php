<?php

declare(strict_types=1);

namespace Rig;

/** Прерывает действие после неудачной validate(). Ошибки уже лежат в компоненте. */
final class ValidationFailed extends \RuntimeException
{
}
