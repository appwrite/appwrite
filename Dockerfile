FROM supabase/postgres:15.1.0.96 AS supabase-db

COPY tests/Migration/resources/supabase/1_globals.sql /docker-entrypoint-initdb.d/1_globals.sql
COPY tests/Migration/resources/supabase/2_main.sql /docker-entrypoint-initdb.d/2_main.sql

RUN rm -rf /docker-entrypoint-initdb.d/migrate.sh

FROM postgres:alpine3.18 AS nhost-db

COPY tests/Migration/resources/nhost/1_globals.sql /docker-entrypoint-initdb.d/1_globals.sql
COPY tests/Migration/resources/nhost/2_main.sql /docker-entrypoint-initdb.d/2_main.sql

FROM composer:2.0 AS composer

COPY composer.json /app
COPY composer.lock /app

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist;

FROM php:8.5-cli-alpine AS tests

# Postgres
RUN set -ex \
    && apk --no-cache add postgresql-libs postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apk del postgresql-dev

COPY ./src /app/src
COPY ./tests /app/src/tests
COPY ./bin /app/bin

COPY --from=composer /app/vendor /app/vendor

WORKDIR /app

CMD tail -f /dev/null
