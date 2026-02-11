# Laravel Shortener Runbook (Docker + OpenResty + PHP + Postgres + Valkey)

This file is the operational guide for running and debugging this project on Windows.
All commands are Docker-based so your host environment does not break Laravel/PHP behavior.

---

## 1) Architecture and Request Flow

- `edge` (OpenResty/Nginx + Lua): handles incoming short code requests like `/NRMnGIfW`.
- `php` (Laravel PHP-FPM): resolves short code when cache miss happens.
- `valkey`: stores:
  - short link payload cache (`shortener-database-sl:code:{code}`)
  - click counters (`shortener-database-sl:clicks:{id}`)
  - dirty set (`shortener-database-sl:dirty`)
- `scheduler`: runs `php artisan schedule:work`.
- `postgres`: persistent source of truth.

Short-link flow:
1. OpenResty Lua checks local shared memory cache (`lua_shared_dict`) for 60 seconds.
2. On miss, Lua checks Valkey payload cache (30 days TTL).
3. On miss, Lua calls Laravel internal resolve endpoint `/_internal/resolve/{code}`.
4. Lua redirects to destination URL (301/302).
5. Lua increments click counter in Valkey and marks ID in dirty set.
6. Scheduler flushes counters from Valkey to Postgres (every minute).

---

## 2) First Run (Clean Start)

From project root (`laravel-temp`):

```powershell
docker compose up -d --build
docker compose ps
```

Then verify health:

```powershell
curl.exe -i "http://localhost/healthz"
```

Expected:
- HTTP `200`
- body: `ok`

### Automatic first bootstrap

`php` container entrypoint now supports first-time bootstrap tasks with marker file:

- env flag: `RUN_FIRST_BOOTSTRAP=1`
- runs once (creates `storage/framework/.first_bootstrap_done`):
  - `php artisan optimize:clear`
  - `php artisan filament:assets`
  - `php artisan optimize`

If you need to re-run bootstrap:

```powershell
docker compose exec php rm -f storage/framework/.first_bootstrap_done
docker compose restart php
```

---

## 3) Daily Run Commands

Start:

```powershell
docker compose up -d
```

Stop:

```powershell
docker compose down
```

Rebuild only edge after OpenResty/Lua changes:

```powershell
docker compose up -d --build edge
```

Rebuild php/scheduler after Laravel or PHP extension related changes:

```powershell
docker compose up -d --build php scheduler
```

---

## 4) Click Update Timing (Important)

Click updates are not written directly to Postgres on every request.

- Real-time counter is first aggregated in Valkey.
- Flush to Postgres happens by scheduler command:
  - `short-links:flush-clicks`
  - schedule: `everyMinute()`

Code location:
- `routes/console.php` uses `Schedule::command(FlushClicksCommand::class)->everyMinute()->withoutOverlapping(2);`
- `scheduler` service clears stale schedule mutex on boot before `schedule:work`.

### Force flush manually now

```powershell
docker compose exec php php artisan short-links:flush-clicks
```

### Verify scheduler heartbeat (on-time execution)

`short-links:flush-clicks` writes heartbeat metadata on each run. Check it:

```powershell
docker compose exec php php artisan short-links:flush-health
```

Use stricter timeout if needed:

```powershell
docker compose exec php php artisan short-links:flush-health --max-delay=90
```

---

## 5) Operational Checks (Is Everything Working?)

## 5.1 Services and Ports

```powershell
docker compose ps
```

Check:
- edge on `80/443`
- php/scheduler running
- valkey/postgres running

## 5.2 Edge and PHP logs

```powershell
docker compose logs -f edge
docker compose logs -f php
docker compose logs -f scheduler
```

## 5.3 OpenResty config and Lua files inside container

```powershell
docker compose exec edge sh -lc "ls -la /usr/local/openresty/nginx/conf"
docker compose exec edge sh -lc "ls -la /etc/nginx/lua"
docker compose exec edge sh -lc "sed -n '1,220p' /usr/local/openresty/nginx/conf/nginx.conf"
docker compose exec edge sh -lc "sed -n '1,260p' /etc/nginx/lua/redirect.lua"
```

Reload OpenResty after direct file copy/debug:

```powershell
docker compose exec edge openresty -s reload
```

## 5.4 Valkey connectivity and keys

```powershell
docker compose exec edge sh -lc "nc -zv valkey 6379"
docker compose exec valkey valkey-cli PING
docker compose exec valkey valkey-cli KEYS "shortener-database-sl:*"
```

## 5.5 Verify a short code end-to-end

```powershell
curl.exe -i "http://localhost/NRMnGIfW"
```

Expected:
- `301` or `302`
- `Location` header set to destination URL
- `X-Shortener-Cache-Source` header shows source:
  - `openresty` (Lua shared memory)
  - `valkey` (Valkey cache)
  - `laravel` (resolved via Laravel fallback)

Then check cached payload/counters:

```powershell
docker compose exec valkey valkey-cli GET "shortener-database-sl:code:NRMnGIfW"
docker compose exec valkey valkey-cli GET "shortener-database-sl:clicks:1"
docker compose exec valkey valkey-cli SMEMBERS "shortener-database-sl:dirty"
```

Check cache source transitions with repeated calls:

```powershell
curl.exe -i "http://localhost/NRMnGIfW"
curl.exe -i "http://localhost/NRMnGIfW"
```

## 5.6 Verify DB row and persisted clicks

```powershell
docker compose exec postgres psql -U shortener -d shortener -c "select id, code, is_active, is_permanent, long_url, utm_source, utm_medium, utm_campaign, utm_term, utm_content, clicks_total from short_links order by id;"
```

Run flush and re-check:

```powershell
docker compose exec php php artisan short-links:flush-clicks
docker compose exec postgres psql -U shortener -d shortener -c "select id, code, clicks_total from short_links order by id;"
```

---

## 6) UTM and URL Behavior

Destination URL is built from DB fields and normalized before redirect.

Current behavior:
- Existing UTM params in original URL are replaced by DB UTM fields.
- UTM values are trimmed.
- Query is encoded with RFC3986 (`%20` for spaces, not `+`).

If your redirect looked wrong before (for example trailing `+`), it was caused by trailing spaces and legacy encoding behavior. That has been corrected in `app/Models/ShortLink.php`.

To inspect your only short-link row:

```powershell
docker compose exec postgres psql -U shortener -d shortener -c "select id, code, long_url, utm_source, utm_medium, utm_campaign, utm_term, utm_content from short_links where code='NRMnGIfW';"
```

To test generated redirect URL:

```powershell
curl.exe -i "http://localhost/NRMnGIfW"
```

Read the `Location` header.

---

## 7) Troubleshooting Guide

### Problem: `404 Not Found` with `openresty` page for valid code

Check:

```powershell
curl.exe -i "http://localhost/healthz"
docker compose logs edge --tail 200
docker compose logs php --tail 200
```

Then validate:
- edge route pattern for short code
- internal resolve location in `nginx.conf`
- Lua script loaded from `/etc/nginx/lua/redirect.lua`

### Problem: Redirect works but Valkey keys are empty

Check:

```powershell
docker compose exec edge sh -lc "nc -zv valkey 6379"
docker compose exec valkey valkey-cli KEYS "shortener-database-sl:*"
```

Confirm OpenResty has Docker DNS resolver:
- `resolver 127.0.0.11 ipv6=off valid=30s;`

### Problem: Clicks in DB not increasing

Check scheduler:

```powershell
docker compose logs scheduler --tail 200
docker compose exec php php artisan schedule:list
docker compose exec php php artisan short-links:flush-health
docker compose exec php php artisan short-links:flush-clicks
```

Then verify counters and dirty set:

```powershell
docker compose exec valkey valkey-cli GET "shortener-database-sl:clicks:1"
docker compose exec valkey valkey-cli SMEMBERS "shortener-database-sl:dirty"
```

If you see `Has Mutex` in `schedule:list` and job is stale, clear lock:

```powershell
docker compose exec php php artisan schedule:clear-cache
docker compose restart scheduler
```

---

## 8) Useful One-Liners

Reset short-link cache/counters for ID 1 and code NRMnGIfW:

```powershell
docker compose exec valkey valkey-cli DEL "shortener-database-sl:code:NRMnGIfW" "shortener-database-sl:clicks:1" "shortener-database-sl:dirty"
```

Reset DB clicks:

```powershell
docker compose exec postgres psql -U shortener -d shortener -c "update short_links set clicks_total=0 where id=1;"
```

Show only short-links table:

```powershell
docker compose exec postgres psql -U shortener -d shortener -c "\dt"
docker compose exec postgres psql -U shortener -d shortener -c "select * from short_links;"
```

---

## 9) Security Notes

- Redirect is restricted to `http://` and `https://` only.
- URLs with CR/LF are blocked.
- Internal resolve endpoint is served through internal Nginx location.
- Admin path is separate (`/admin`) and not handled by Lua redirect logic.

