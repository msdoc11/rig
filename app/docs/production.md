# Продакшен

На сервере работают обычные nginx, PHP-FPM и PostgreSQL, плюс процессы rig: хаб событий (если нужен `broadcast()`) и обработчик очереди (если есть `Queue::push` или `->queue()`); расписание запускает cron.

| Что | Файл |
|---|---|
| сайт: nginx + PHP-FPM, HTTP/2, хаб за `/_rig/hub/`, размер загрузок | `deploy/nginx.conf` |
| хаб событий под systemd (`rig push`) | `deploy/rig-push.service` |
| обработчик очереди под systemd (`rig queue:work`) | `deploy/rig-queue.service` |
| расписание: cron раз в минуту (`rig schedule:run`) | `deploy/rig-schedule.cron` |
| выкладка: права, `rig build`, `rig migrate`, перезагрузка PHP-FPM, хаба и очереди | `deploy/deploy.sh` |

## .env на сервере

```bash
APP_URL=https://example.com
DB_CONNECTION=pgsql
DB_MANAGED=false              # база — ваш сервер PostgreSQL, rig его не запускает
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=example
DB_USERNAME=example
DB_PASSWORD=…
MAIL_DRIVER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=…
MAIL_PASSWORD=…
MAIL_FROM_ADDRESS=noreply@example.com
LOG_LEVEL=info
```

Базу и пользователя создайте один раз: `sudo -u postgres createuser -P example && sudo -u postgres createdb -O example example`. Таблицы создаёт `rig migrate`, его запускает `deploy.sh`.

## Режим

Режим задаёт переменная окружения `RIG_ENV=production`: в nginx это `fastcgi_param RIG_ENV production;`, в systemd `Environment=RIG_ENV=production`. Настройки обоих режимов лежат в `config.php`:

```php
$prod = ($_SERVER['RIG_ENV'] ?? getenv('RIG_ENV')) === 'production';

return [
    'debug' => !$prod,                       // HMR и подробные ошибки только в разработке
    'hub' => $prod ? '/_rig/hub' : null,     // false — события с сервера выключены
    'speculation' => 'moderate',
    'page_cache' => 600,
    'editor' => 'code',
];
```

## Порядок

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/example.com    # домен, пути, сокет PHP-FPM
sudo cp deploy/rig-push.service deploy/rig-queue.service /etc/systemd/system/   # пути
sudo systemctl enable --now rig-push rig-queue
sudo cp deploy/rig-schedule.cron /etc/cron.d/rig-schedule          # путь к проекту
sh deploy/deploy.sh                                                 # и после каждой выкладки
```

`rig build` собирает кеш компонентов заново, чтобы первый посетитель не ждал компиляции, и останавливает выкладку, если в шаблоне ошибка или на продакшене включён `debug`.

## Что происходит после сборки

Сборка не создаёт отдельной папки с результатом: она кладёт скомпилированные компоненты в `storage/cache/`, а работает тот же проект. Запускать его отдельно не нужно. Сайт отвечает, как только nginx направляет запросы в `public/index.php` через PHP-FPM. Постоянно работает только хаб событий `rig push`, его держит systemd.

После каждой выкладки запускайте `sh deploy/deploy.sh`. Он вызывает `rig build` и перезагружает PHP-FPM и хаб.

## Что учесть

- наружу смотрит только `public/`; `storage/` должен быть доступен на запись пользователю PHP-FPM;
- включите OPcache (`opcache.enable=1`, `opcache.validate_timestamps=0`) и перезагружайте PHP-FPM после выкладки;
- HTTP/2 включается в nginx: `listen 443 ssl; http2 on;`.

## Безопасность

- Снимок состояния подписан HMAC-SHA256 ключом из `storage/app.key` (или `APP_KEY` в `.env`).
- Действия, загрузки и запросы к API из браузера проверяются CSRF-токеном сессии; cookie сессии получает флаги HttpOnly, SameSite=Lax и Secure на https.
- Middleware страницы подписан в снимки её компонентов; `#[Guard]` проверяется перед каждым действием.
- Подбор пароля ограничен; для своих адресов есть `throttle:N,M`.
- Загруженные файлы проверяются по содержимому, `.php` сохранить нельзя, а nginx не выполняет `.php` из `public/`.
- Из браузера нельзя изменить свойства с `#[Locked]` и `#[Prop]`, а также вызвать метод без `#[Action]` или `#[On]`.
- Компоненты загружаются только из `App\Components` и только наследники `Rig\Component`.
- Запросы к `/_rig/action` требуют заголовок `X-Rig: 1`: форма с чужого сайта его не отправит.
- Страницы отдаются с `Cache-Control: private`, поэтому персональные данные не попадут в общие кеши.
