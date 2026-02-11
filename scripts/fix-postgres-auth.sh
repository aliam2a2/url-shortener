#!/usr/bin/env bash
set -euo pipefail

# Repairs PostgreSQL auth mismatch for existing persisted PGDATA volumes.
# It aligns DB role credentials with .env (DB_DATABASE, DB_USERNAME, DB_PASSWORD)
# using PostgreSQL single-user mode, so no existing DB superuser credentials are needed.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

if [[ ! -f ".env" ]]; then
  echo ".env file not found in ${ROOT_DIR}" >&2
  exit 1
fi

read_env() {
  local key="$1"
  local value
  value="$(grep -E "^${key}=" .env | sed -E "s/^${key}=//" | tail -n 1 || true)"
  printf "%s" "${value}"
}

DB_DATABASE="$(read_env DB_DATABASE)"
DB_USERNAME="$(read_env DB_USERNAME)"
DB_PASSWORD="$(read_env DB_PASSWORD)"

if [[ -z "${DB_DATABASE}" || -z "${DB_USERNAME}" || -z "${DB_PASSWORD}" ]]; then
  echo "Missing DB_DATABASE/DB_USERNAME/DB_PASSWORD in .env" >&2
  exit 1
fi

DB_PASSWORD_ESCAPED="${DB_PASSWORD//\'/\'\'}"

echo "Target DB_DATABASE=${DB_DATABASE}"
echo "Target DB_USERNAME=${DB_USERNAME}"
echo "Stopping app services to avoid restart loops..."
docker compose stop edge scheduler php >/dev/null 2>&1 || true

echo "Stopping postgres for single-user recovery..."
docker compose stop postgres

echo "Applying role/database repair in single-user mode..."
docker compose run --rm --no-deps --user postgres --entrypoint sh postgres -lc "
cat > /tmp/fix_auth.sql <<'SQL'
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${DB_USERNAME}') THEN
    CREATE ROLE \"${DB_USERNAME}\" LOGIN PASSWORD '${DB_PASSWORD_ESCAPED}';
  ELSE
    ALTER ROLE \"${DB_USERNAME}\" WITH LOGIN PASSWORD '${DB_PASSWORD_ESCAPED}';
  END IF;
END
\$\$;

DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = '${DB_DATABASE}') THEN
    CREATE DATABASE \"${DB_DATABASE}\" OWNER \"${DB_USERNAME}\";
  END IF;
END
\$\$;

GRANT ALL PRIVILEGES ON DATABASE \"${DB_DATABASE}\" TO \"${DB_USERNAME}\";
SQL

postgres --single -D /var/lib/postgresql/data postgres < /tmp/fix_auth.sql
"

echo "Starting postgres..."
docker compose up -d postgres

echo "Starting php..."
docker compose up -d php

echo "Clearing Laravel caches..."
docker compose exec php php artisan optimize:clear || true

echo "Running migrations..."
docker compose exec php php artisan migrate --force

echo "Starting remaining services..."
docker compose up -d scheduler edge

echo "Done. Current service status:"
docker compose ps
