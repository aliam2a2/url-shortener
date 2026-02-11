#!/usr/bin/env sh
set -e

cd /var/www/html

if [ ! -f artisan ]; then
  exec "$@"
fi

if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

run_as_web_user() {
  su-exec www-data:www-data "$@"
}

ensure_runtime_permissions() {
  mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

  # On Linux hosts this guarantees proper ownership.
  chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

  # On bind mounts (especially Windows/macOS) chown may fail,
  # so ensure write permission for Laravel runtime paths.
  chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
  chmod -R a+rwX storage bootstrap/cache 2>/dev/null || true
}

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

ensure_runtime_permissions

if [ -f .env ] && ! grep -q '^APP_KEY=base64:' .env; then
  echo "Generating APP_KEY..."
  run_as_web_user php artisan key:generate --force
fi

if [ ! -e public/storage ]; then
  run_as_web_user php artisan storage:link >/dev/null 2>&1 || true
fi

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  echo "Running migrations..."
  run_as_web_user php artisan migrate --force
fi

if [ "${RUN_FIRST_BOOTSTRAP:-0}" = "1" ]; then
  BOOTSTRAP_MARKER="storage/framework/.first_bootstrap_done"
  if [ ! -f "${BOOTSTRAP_MARKER}" ]; then
    echo "Running first bootstrap tasks..."
    run_as_web_user php artisan optimize:clear || true
    run_as_web_user php artisan filament:assets || true
    run_as_web_user php artisan optimize || true
    mkdir -p "$(dirname "${BOOTSTRAP_MARKER}")"
    run_as_web_user touch "${BOOTSTRAP_MARKER}"
  fi
fi

exec "$@"
