FROM openresty/openresty:1.27.1.2-0-alpine

WORKDIR /var/www/html

RUN apk add --no-cache ca-certificates netcat-openbsd

COPY docker/openresty/nginx.conf /usr/local/openresty/nginx/conf/nginx.conf
COPY docker/openresty/lua/ /etc/nginx/lua/
COPY docker/openresty/entrypoint.sh /usr/local/bin/edge-entrypoint

RUN chmod +x /usr/local/bin/edge-entrypoint

ENTRYPOINT ["edge-entrypoint"]

CMD ["/usr/local/openresty/bin/openresty", "-g", "daemon off;"]
