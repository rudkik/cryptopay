# CryptoPay backend — Laravel 13 on PHP 8.4 (php-fpm).
#
# The same image runs three compose services; the command decides which:
#   app        php-fpm
#   queue      php artisan queue:work redis --queue=default,webhooks --tries=3
#   scheduler  php artisan schedule:work
#
# nginx proxies /api to app:9000 with SCRIPT_FILENAME /var/www/html/public/...,
# so WORKDIR must stay /var/www/html.

# ---------------------------------------------------------------------------
# Stage 1 — vendor
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS vendor

# Расширения здесь не собираем (это самая дорогая часть сборки, а на слабом
# сервере — десятки минут): composer'у они нужны только для проверки
# platform-требований, которую отключаем для ext-*.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

# Artisan is not present yet, so skip the package-discovery scripts here; they
# run again in the final stage once the whole application has been copied.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist \
        --ignore-platform-req=ext-bcmath \
        --ignore-platform-req=ext-gmp \
        --ignore-platform-req=ext-pdo_pgsql \
        --ignore-platform-req=ext-pcntl \
    && rm -rf /root/.composer/cache

# ---------------------------------------------------------------------------
# Stage 2 — runtime
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine

LABEL org.opencontainers.image.title="cryptopay-backend"

# postgresql-client gives the entrypoint pg_isready; libpq and gmp are the
# runtime libraries behind the extensions built below.
RUN apk add --no-cache postgresql-client libpq gmp \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev gmp-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath gmp pcntl opcache \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-cryptopay.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-cryptopay.conf

WORKDIR /var/www/html

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .

# The storage tree must exist and be writable before Laravel boots; compose may
# not mount anything over it.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

ENTRYPOINT ["docker/entrypoint.sh"]

CMD ["php-fpm"]

EXPOSE 9000
