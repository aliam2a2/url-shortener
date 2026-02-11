#!/usr/bin/env sh
set -e

cd /var/www/html

# اگر پروژه mount نشده/فایل artisan نیست، فقط سرویس را بالا بیاور
if [ ! -f artisan ]; then
  exec "$@"
fi

# اگر .env نداری، از نمونه بساز
if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

# صبر برای DB (اگر .env درست ست شده باشد)
DB_HOST="${DB_HOST:-postgres}"
DB_PORT="${DB_PORT:-5432}"

echo "Waiting for Postgres at ${DB_HOST}:${DB_PORT} ..."
for i in $(seq 1 60); do
  nc -z "$DB_HOST" "$DB_PORT" && break
  sleep 1
done

if [ ! -f vendor/autoload.php ]; then
  echo "Installing PHP dependencies (composer)..."
  composer install --no-interaction --prefer-dist --optimize-autoloader
fi

if [ -f .env ] && ! grep -q '^APP_KEY=base64:' .env; then
  echo "Generating APP_KEY..."
  php artisan key:generate --force
fi

if [ ! -e public/storage ]; then
  php artisan storage:link >/dev/null 2>&1 || true
fi

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  echo "Running migrations..."
  php artisan migrate --force
fi

if [ "${RUN_FIRST_BOOTSTRAP:-0}" = "1" ]; then
  BOOTSTRAP_MARKER="storage/framework/.first_bootstrap_done"
  if [ ! -f "${BOOTSTRAP_MARKER}" ]; then
    echo "Running first bootstrap tasks..."
    php artisan optimize:clear || true
    php artisan filament:assets || true
    php artisan optimize || true
    mkdir -p "$(dirname "${BOOTSTRAP_MARKER}")"
    touch "${BOOTSTRAP_MARKER}"
  fi
fi

exec "$@"
