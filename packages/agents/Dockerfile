FROM composer:2 AS composer

WORKDIR /usr/local/src/
COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install \
    --ignore-platform-reqs \
    --optimize-autoloader \
    --no-plugins \
    --no-scripts \
    --prefer-dist

FROM php:8.3-cli-alpine AS final

LABEL maintainer="team@appwrite.io"

WORKDIR /usr/src/code

# Configure PHP
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "memory_limit=256M" >> $PHP_INI_DIR/php.ini

# Install required tooling
RUN apk add --no-cache git

# Copy composer dependencies
COPY --from=composer /usr/local/src/vendor /usr/src/code/vendor

# Add Source Code
COPY . /usr/src/code

CMD [ "tail", "-f", "/dev/null" ]
