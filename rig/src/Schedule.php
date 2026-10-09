<?php

declare(strict_types=1);

namespace Rig;

use Rig\Schedule\Task;

/**
 * Планировщик: задачи по расписанию описываются в app/schedule.php.
 *
 *   use Rig\Schedule;
 *
 *   Schedule::call(fn () => Cache::forget('top'), 'обновить топ')->hourly();
 *   Schedule::job(SendDigest::class)->dailyAt('09:00')->weekdays();
 *   Schedule::call(fn () => …)->cron('*\/15 9-18 * * 1-5');
 *
 * Запуск: на сервере cron раз в минуту:  * * * * * cd /var/www/site && php rig/cli.php schedule:run
 * В разработке rig dev делает это сам.
 */
final class Schedule
{
    /** @var list<Task> */
    private static array $tasks = [];
    private static bool $loaded = false;

    public static function call(\Closure $fn, ?string $name = null): Task
    {
        return self::$tasks[] = new Task($fn, $name ?? 'задача ' . (count(self::$tasks) + 1));
    }

    /** Поставить задачу очереди по расписанию. */
    public static function job(string $class, array $data = [], ?string $name = null): Task
    {
        return self::$tasks[] = new Task(static fn () => Queue::push($class, $data), $name ?? basename(str_replace('\\', '/', $class)));
    }

    /** @return list<Task> */
    public static function tasks(): array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $file = Config::path('app/schedule.php');
            if (is_file($file)) {
                require $file;
            }
        }
        return self::$tasks;
    }

    /**
     * Выполнить задачи, которым пора в эту минуту.
     * @return list<array{name:string,ok:bool,error:?string,ms:int}>
     */
    public static function runDue(?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $out = [];
        foreach (self::tasks() as $t) {
            if (!$t->isDue($now)) {
                continue;
            }
            $out[] = $t->run();
        }
        return $out;
    }

    /** Для тестов. */
    public static function reset(): void
    {
        self::$tasks = [];
        self::$loaded = false;
    }
}
