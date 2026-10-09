<?php

declare(strict_types=1);

namespace App;

/** Журнал событий для ленты: общий для всех вкладок и пользователей, переживает перезагрузку. */
final class ActivityLog
{
    private const KEEP = 30;

    private static function file(): string
    {
        return dirname(__DIR__, 2) . '/storage/activity.json';
    }

    /** @return list<array{id:int, at:string, text:string}> новые сверху */
    public static function recent(int $limit = 8): array
    {
        $items = is_file(self::file()) ? json_decode((string)file_get_contents(self::file()), true) : [];
        return array_slice(is_array($items) ? $items : [], 0, $limit);
    }

    public static function add(string $text): void
    {
        $items = self::recent(self::KEEP);
        $id = ($items[0]['id'] ?? 0) + 1;
        array_unshift($items, ['id' => $id, 'at' => date('H:i:s'), 'text' => $text]);
        file_put_contents(self::file(), json_encode(array_slice($items, 0, self::KEEP), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }
}
