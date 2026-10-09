<?php

declare(strict_types=1);

namespace Rig;

/**
 * События с сервера в браузер.
 *
 * publish() дописывает строку в storage/push.log, хаб (rig dev или rig push)
 * читает файл и рассылает событие открытым страницам, у которых есть компонент с #[On('имя')].
 * Страница, которая сама вызвала broadcast, событие не получает.
 */
final class Push
{
    private static ?string $file = null;
    private static ?string $from = null;

    /** @internal */
    public static function init(string $file, ?string $from): void
    {
        self::$file = $file;
        self::$from = $from;
    }

    public static function publish(string $event, mixed $data = null): void
    {
        if (self::$file === null) {
            return;
        }
        $line = json_encode(['e' => $event, 'd' => $data, 'from' => self::$from], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents(self::$file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
