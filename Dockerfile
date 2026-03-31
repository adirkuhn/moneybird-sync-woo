FROM php:8.1-cli-alpine

RUN apk add --no-cache icu-dev $PHPIZE_DEPS \
    && docker-php-ext-install intl \
    && docker-php-ext-enable intl \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
