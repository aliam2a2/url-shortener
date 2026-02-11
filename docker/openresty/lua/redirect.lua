local redis = require("resty.redis")
local cjson = require("cjson.safe")
local lru = ngx.shared.shortener_cache
local redis_prefix = "shortener-database-"

local function redis_key(suffix)
    return redis_prefix .. suffix
end

local function is_safe_url(url)
    if type(url) ~= "string" then
        return false
    end

    local lower = string.lower(url)
    if not (string.sub(lower, 1, 7) == "http://" or string.sub(lower, 1, 8) == "https://") then
        return false
    end

    if string.find(url, "\r", 1, true) or string.find(url, "\n", 1, true) then
        return false
    end

    return true
end

local function fetch_from_laravel(code)
    local res = ngx.location.capture("/_internal/resolve/" .. code)
    if not res then
        return nil, "internal resolve subrequest failed", ngx.HTTP_INTERNAL_SERVER_ERROR
    end

    if res.status == ngx.HTTP_NOT_FOUND then
        return nil, "not found", ngx.HTTP_NOT_FOUND
    end

    if res.status ~= ngx.HTTP_OK then
        return nil, "internal resolve error status: " .. tostring(res.status), ngx.HTTP_INTERNAL_SERVER_ERROR
    end

    local payload = cjson.decode(res.body)
    if not payload then
        return nil, "invalid resolve response payload", ngx.HTTP_INTERNAL_SERVER_ERROR
    end

    return payload, nil, nil
end

local function new_redis()
    local red = redis:new()
    red:set_timeout(1000)

    local ok, err = red:connect("valkey", 6379)
    if not ok then
        return nil, "failed to connect valkey: " .. tostring(err)
    end

    return red, nil
end

local uri = ngx.var.uri or ""
local code = string.match(uri, "^/([0-9A-Za-z]+)$")

if not code or #code ~= 8 then
    return ngx.exit(ngx.HTTP_NOT_FOUND)
end

local payload = nil
local cache_source = "none"
local lru_value = lru:get(code)
if lru_value then
    payload = cjson.decode(lru_value)
    if payload then
        cache_source = "openresty"
    end
end
local red = nil

if not payload then
    local red_client, red_err = new_redis()
    if red_client then
        red = red_client

        local redis_value, get_err = red:get(redis_key("sl:code:" .. code))
        if get_err then
            ngx.log(ngx.ERR, "failed redis GET: ", get_err)
        elseif redis_value and redis_value ~= ngx.null then
            payload = cjson.decode(redis_value)
            if payload then
                cache_source = "valkey"
            end
        end
    else
        ngx.log(ngx.WARN, red_err)
    end

    if not payload then
        local fetched_payload, fetch_err, http_status = fetch_from_laravel(code)
        if not fetched_payload then
            ngx.log(ngx.NOTICE, "resolve failed for code ", code, ": ", fetch_err)
            return ngx.exit(http_status or ngx.HTTP_INTERNAL_SERVER_ERROR)
        end

        payload = fetched_payload
        cache_source = "laravel"

        if red then
            local encoded = cjson.encode(payload)
            if encoded then
                local _, set_err = red:setex(redis_key("sl:code:" .. code), 60 * 60 * 24 * 30, encoded)
                if set_err then
                    ngx.log(ngx.ERR, "failed redis SETEX: ", set_err)
                end
            end
        end
    end

    local encoded_payload = cjson.encode(payload)
    if encoded_payload then
        lru:set(code, encoded_payload, 60)
    end
end

if not payload or not payload.url then
    return ngx.exit(ngx.HTTP_NOT_FOUND)
end

if not is_safe_url(payload.url) then
    ngx.log(ngx.WARN, "unsafe redirect URL blocked for code ", code)
    return ngx.exit(ngx.HTTP_NOT_FOUND)
end

local status_code = ngx.HTTP_MOVED_TEMPORARILY
if payload.is_permanent == true then
    status_code = ngx.HTTP_MOVED_PERMANENTLY
end

if payload.id then
    if not red then
        local red_client, red_err = new_redis()
        red = red_client
        if not red and red_err then
            ngx.log(ngx.WARN, red_err)
        end
    end

    if red then
        red:init_pipeline()
        red:incr(redis_key("sl:clicks:" .. tostring(payload.id)))
        red:sadd(redis_key("sl:dirty"), tostring(payload.id))

        local _, commit_err = red:commit_pipeline()
        if commit_err then
            ngx.log(ngx.ERR, "failed click pipeline: ", commit_err)
        end

        local _, keepalive_err = red:set_keepalive(10000, 100)
        if keepalive_err then
            ngx.log(ngx.WARN, "failed redis keepalive: ", keepalive_err)
        end
    end
end

ngx.header["Cache-Control"] = "no-store"
ngx.header["X-Shortener-Cache-Source"] = cache_source
return ngx.redirect(payload.url, status_code)
