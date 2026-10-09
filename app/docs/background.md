# Очереди, почта, расписание

## Очередь задач

Долгую работу (письма, отчёты, обработку картинок) делайте в фоне, чтобы ответ пользователю её не ждал.

```php
// app/jobs/SendWelcome.php
namespace App\Jobs;

final class SendWelcome implements \Rig\Job
{
    public function handle(array $data): void
    {
        \Rig\Mail::to($data['email'])->subject('Добро пожаловать')->view('welcome', $data)->send();
    }
}
```

```php
use Rig\Queue;

Queue::push(SendWelcome::class, ['email' => $email]);
Queue::push(Cleanup::class, [], delay: 3600);   // через час
```

Задачи лежат файлами в `storage/queue`, Redis не нужен. В разработке их выполняет сам `rig dev`. Каждая пачка запускается новым процессом, правки в `app/jobs` подхватываются без перезапуска, в консоли видно `[queue] ✔ SendWelcome 12 ms`. На сервере задачи обрабатывает `rig queue:work` под systemd (`deploy/rig-queue.service`).

Упавшая задача повторяется (`'queue' => ['tries' => 3]`) с паузой 10, 20, 30 секунд, затем попадает в проваленные:

| Команда | Что делает |
|---|---|
| `rig queue:work` | обработчик: `--once`, `--queue=имя` |
| `rig queue:failed` | проваленные задачи с ошибками |
| `rig queue:retry` | вернуть в очередь все проваленные; `rig queue:retry id` возвращает одну |
| `rig queue:flush` | удалить проваленные |

С `'queue' => ['driver' => 'sync']` задачи выполняются сразу, без очереди (для тестов и отладки).

## Почта

```php
use Rig\Mail;

Mail::to('anna@example.com', 'Анна')
    ->subject('Заказ №42 оформлен')
    ->view('order', ['order' => $order])   // шаблон app/emails/order.php, текстовая версия — из него
    ->attach('/path/invoice.pdf')
    ->send();                               // или ->queue() — в фоне
```

Драйвер выбирается переменной `MAIL_DRIVER` в `.env`:

| Драйвер | Что делает |
|---|---|
| `log` | письма пишутся в `storage/logs/mail.log`; для разработки |
| `smtp` | отправка: `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION` (`tls` для STARTTLS или `ssl`), `MAIL_USERNAME`, `MAIL_PASSWORD` |
| `array` | письма попадают только в `Mail::$sent`; для тестов |

Отправителя задают `MAIL_FROM_ADDRESS` и `MAIL_FROM_NAME`. Темы и имена в UTF-8 кодируются сами.

## Расписание

```php
// app/schedule.php
use Rig\Schedule;

Schedule::call(fn () => Cache::forget('top'), 'обновить топ')->hourly();
Schedule::job(SendDigest::class)->dailyAt('09:00')->weekdays();
Schedule::call(fn () => …, 'отчёт')->cron('*/15 9-18 * * 1-5')->withoutOverlapping();
```

Частота: `everyMinute`, `everyFiveMinutes`, `everyTenMinutes`, `everyFifteenMinutes`, `everyThirtyMinutes`, `hourly`, `hourlyAt(15)`, `daily`, `dailyAt('03:00')`, `weeklyOn(1, '09:00')`, `monthly`, `cron('…')`, `weekdays()`. `withoutOverlapping()` не даёт запустить задачу, пока идёт прошлый запуск.

В разработке расписание выполняет `rig dev` (`[cron] ✔ …` в консоли), на сервере cron раз в минуту (`deploy/rig-schedule.cron`). `rig schedule:list` показывает, что и когда запускается. Ошибка задачи пишется в журнал и не мешает остальным.
