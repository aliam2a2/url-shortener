#!/usr/bin/env sh
set -e

PHP_HOST="${PHP_HOST:-php}"
PHP_PORT="${PHP_PORT:-9000}"

echo "Waiting for PHP-FPM at ${PHP_HOST}:${PHP_PORT} ..."
for i in $(seq 1 120); do
  if nc -z "${PHP_HOST}" "${PHP_PORT}"; then
    echo "PHP-FPM is reachable."
    exec "$@"
  fi

  sleep 1
done

echo "PHP-FPM did not become reachable in time." >&2
exit 1
