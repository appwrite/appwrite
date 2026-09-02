FROM php:8.4-cli-alpine

WORKDIR /app

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pcntl \
    && apk del .build-deps

COPY composer.json ./
COPY vendor ./vendor
COPY src ./src
COPY tests ./tests

CMD ["php", "tests/Queue/servers/Keda/worker.php"]
