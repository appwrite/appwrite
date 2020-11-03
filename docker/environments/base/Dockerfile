FROM composer:2.0 as step0

WORKDIR /usr/local/src/

COPY composer.json /usr/local/src/

RUN composer update --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist --no-dev

FROM php:7.4-cli-alpine as step1

ENV PHP_SWOOLE_VERSION=v4.5.6

RUN \
  apk add --no-cache --virtual .deps \
  make \
  automake \
  autoconf \
  gcc \
  g++ \
  git \
  zlib-dev

RUN docker-php-ext-install sockets

RUN \
  ## Swoole Extension
  git clone https://github.com/swoole/swoole-src.git && \
  cd swoole-src && \
  git checkout $PHP_SWOOLE_VERSION && \
  phpize && \
  ./configure --enable-sockets --enable-http2 && \
  make && make install && \
  cd ..

FROM php:7.4-cli-alpine as final

LABEL maintainer="team@appwrite.io"

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN \
  apk update \
  && apk add --no-cache --virtual .deps \
  && apk add --no-cache \
  tar \
  libstdc++ \
  && docker-php-ext-install sockets opcache \
  && apk del .deps \
  && rm -rf /var/cache/apk/*

WORKDIR /usr/src/code

COPY --from=step0 /usr/local/src/vendor /usr/src/code/vendor
COPY --from=step1 /usr/local/lib/php/extensions/no-debug-non-zts-20190902/swoole.so /usr/local/lib/php/extensions/no-debug-non-zts-20190902/

RUN ls -ll /usr/local/lib/php/extensions/no-debug-non-zts-20190902/

# Add Source Code
COPY ./app /usr/src/code/app
COPY ./start /usr/local/bin/start

RUN chmod +x /usr/local/bin/start

# Enable Extensions
RUN echo extension=swoole.so >> /usr/local/etc/php/conf.d/swoole.ini

# PHP Settings
RUN echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/appwrite.ini

EXPOSE 80

CMD [ "start" ]
