FROM php:8.3-cli-alpine

RUN apk add --no-cache git unzip libzip-dev oniguruma-dev \
    && docker-php-ext-install -j$(nproc) zip mbstring

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /app
