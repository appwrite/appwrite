FROM composer:2.0 as deps
COPY composer.* /app/
RUN composer install \
    --ignore-platform-reqs \
    --optimize-autoloader  \
    --no-plugins \
    --no-scripts \
    --prefer-dist

FROM php:8.0.14-alpine3.15
COPY --from=deps /app/vendor /app/vendor
WORKDIR /app
COPY . .
CMD ["vendor/bin/phpunit", "--configuration", "phpunit.xml"]
