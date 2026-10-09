<?php

/**
 * Точка входа командной строки rig. Обычно вызывается через ./rig (bash) или rig.bat (Windows).
 *
 *   php rig/cli.php dev | check | push | help
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

exit((new Rig\Cli(dirname(__DIR__)))->run($argv));
