FROM php:8.3-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache \
    bash curl git unzip \
    libpq-dev oniguruma-dev \
    icu-dev libzip-dev \
    linux-headers \
    netcat-openbsd \
    su-exec \
    $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql pcntl opcache intl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && printf "expose_php=Off\n" > /usr/local/etc/php/conf.d/zz-security.ini \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
