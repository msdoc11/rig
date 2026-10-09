<?php

/**
 * Расписание. В разработке задачи запускает rig dev, на сервере их раз в минуту запускает cron:
 *   * * * * * cd /var/www/site && php rig/cli.php schedule:run
 */

use App\ActivityLog;
use App\Models\Todo;
use Rig\Push;
use Rig\Schedule;

// раз в 5 минут: отметка в ленте событий демо (видно в браузере через хаб)
Schedule::call(function () {
    ActivityLog::add('Планировщик. Задач в работе: ' . Todo::where('done', false)->count());
    Push::publish('activity');
}, 'отметка в ленте')->everyFiveMinutes();

// ночью: убрать выполненные задачи старше недели
Schedule::call(function () {
    Todo::where('done', true)->where('updated_at', '<', date('Y-m-d H:i:s', strtotime('-7 days')))->delete();
}, 'очистка старых задач')->dailyAt('03:00');
