# syntax=docker/dockerfile:1

# PHP 8.5 with the extensions the app needs, served by FrankenPHP (Caddy).
FROM dunglas/frankenphp:1.13-php8.5-alpine AS base
RUN install-php-extensions pdo_pgsql redis pcntl intl bcmath zip opcache
WORKDIR /app

# Development and CI tooling: Composer and PCOV for coverage. The source is
# mounted, so nothing is copied here.
FROM base AS dev
RUN install-php-extensions pcov
COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer

FROM base AS vendor
COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

FROM base AS prod
ENV APP_ENV=production \
    APP_DEBUG=false \
    SERVER_NAME=:8000
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && addgroup -g 10001 app \
    && adduser -D -u 10001 -G app app
COPY --from=vendor /app/vendor ./vendor
COPY --from=vendor /usr/bin/composer /usr/bin/composer
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && rm /usr/bin/composer \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache /config/caddy /data/caddy
USER 10001
EXPOSE 8000
CMD ["frankenphp", "php-server", "--listen", ":8000", "--root", "public/"]
