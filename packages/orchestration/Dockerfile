FROM composer:2.0 as step0

ARG TESTING=false
ENV TESTING=$TESTING

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install --ignore-platform-reqs --optimize-autoloader --no-plugins --no-scripts --prefer-dist

RUN docker-php-ext-install sockets

FROM php:8.0-cli-alpine as final

ENV DOCKER_CONFIG=${DOCKER_CONFIG:-$HOME/.docker}
ENV DOCKER_API_VERSION=1.43

LABEL maintainer="team@appwrite.io"
    
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN \
  apk update \
  && apk add --no-cache make automake autoconf gcc g++ git brotli-dev docker-cli \
  && docker-php-ext-install sockets \
  && docker-php-ext-install opcache

WORKDIR /usr/src/code

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN echo "opcache.enable_cli=1" >> $PHP_INI_DIR/php.ini

RUN echo "memory_limit=1024M" >> $PHP_INI_DIR/php.ini

COPY --from=step0 /usr/local/src/vendor /usr/src/code/vendor

# Add Source Code
COPY . /usr/src/code

CMD [ "tail", "-f", "/dev/null" ]