#!/usr/bin/env sh
# rig: выкладка на сервер (запускать на сервере в каталоге проекта после git pull / rsync).
#   sh deploy/deploy.sh
set -e
cd "$(dirname "$0")/.."

export RIG_ENV=production

# storage: единственный каталог, куда пишет приложение (кеш, ключ, сессии, очередь, журналы, загрузки);
# public/uploads: сохранённые файлы пользователей (аватары и т.п.)
mkdir -p storage/cache storage/hmr storage/sessions storage/logs storage/queue public/uploads
chown -R www-data:www-data storage public/uploads 2>/dev/null || true

# проверка и чистый кеш компонентов: ошибка в шаблоне остановит выкладку
sudo -u www-data RIG_ENV=production php rig/cli.php build

# новые миграции базы (PostgreSQL из .env: DB_HOST, DB_DATABASE…; DB_MANAGED=false)
sudo -u www-data RIG_ENV=production php rig/cli.php migrate

# OPcache держит старый код в памяти, поэтому перезагружаем PHP-FPM, затем хаб и обработчик очереди
sudo systemctl reload php8.4-fpm
sudo systemctl restart rig-push 2>/dev/null || true
sudo systemctl restart rig-queue 2>/dev/null || true

echo "Выложено."
