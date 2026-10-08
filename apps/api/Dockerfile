# syntax=docker/dockerfile:1
FROM php:8.3-fpm-alpine
RUN apk add --no-cache git unzip libzip-dev libpng-dev libpq-dev $PHPIZE_DEPS \
 && docker-php-ext-install -j$(nproc) pdo pdo_sqlite pdo_pgsql gd zip pcntl \
 && rm -rf /tmp/* /var/cache/apk/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /srv/app
# Install first (better layer caching), then the app.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts
COPY . .
RUN composer dump-autoload --optimize \
 && php artisan storage:link || true
# NOTE: no config:cache/route:cache — domain reads env at runtime (12-factor).
USER www-data
EXPOSE 9000
CMD ["php-fpm"]
