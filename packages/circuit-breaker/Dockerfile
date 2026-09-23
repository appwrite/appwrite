FROM php:8.3-cli-alpine

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache git unzip \
    && install-php-extensions opentelemetry protobuf redis swoole

WORKDIR /app
