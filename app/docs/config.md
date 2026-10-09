# Настройки, журнал, кеш

## .env

Значения, которые отличаются между машинами, хранятся в `.env` в корне проекта (образец лежит в `.env.example`, сам `.env` в git не попадает):

```bash
APP_URL=https://example.com
DB_PASSWORD="пароль с пробелами"   # комментарий
MAIL_FROM_NAME=${APP_NAME}
```

`config.php` читает их через `env('DB_PASSWORD', 'по умолчанию')`; `true`, `false`, `null` становятся значениями PHP. Настоящие переменные окружения сервера (nginx `fastcgi_param`, systemd `Environment=`) перекрывают значения из файла.

В коде настройки читаются через `config('mail.from.address')`.

## Журнал

```php
use Rig\Log;

Log::info('Заказ оформлен', ['id' => 42]);
Log::warning('Медленный ответ платёжного сервиса');
Log::error($exception);
```

Файл на каждый день: `storage/logs/rig-2026-10-09.log`. Ошибки 5xx, упавшие задачи очереди и расписания фреймворк записывает сам, вместе со стеком вызовов. Уровень задаёт `LOG_LEVEL` (`debug`, `info`, `warning`, `error`).

## Кеш данных

```php
use Rig\Cache;

$top = Cache::remember('top-products', 600, fn () => Product::top());   // 600 секунд
Cache::get('key', 'по умолчанию');
Cache::set('key', $value, 3600);
Cache::increment('views');
Cache::forget('top-products');
```

Кеш хранится в файлах в `storage/cache/data`, Redis не нужен. Очистить весь кеш: `rig cache:clear`.
