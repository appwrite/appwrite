FROM composer:2.8 AS composer

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install \
    --ignore-platform-reqs \
    --optimize-autoloader \
    --no-plugins \
    --no-scripts \
    --prefer-dist

FROM php:8.5.8-cli-alpine AS compile

ENV PHP_REDIS_VERSION="6.3.0" \
    PHP_SWOOLE_VERSION="v6.2.2" \
    PHP_XDEBUG_VERSION="3.5.3" \
    PHP_MONGODB_VERSION="2.3.3"
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN apk update && apk add --no-cache \
    libpq \
    libpq-dev \
    make \
    automake \
    autoconf \
    gcc \
    g++ \
    git \
    file \
    openssl-dev \
    brotli-dev \
    linux-headers \
    docker-cli \
    docker-cli-compose \
 && git clone --depth 1 --branch $PHP_MONGODB_VERSION --recurse-submodules https://github.com/mongodb/mongo-php-driver.git /tmp/mongodb \
 && cd /tmp/mongodb \
 && git submodule update --init --recursive \
 && phpize \
 && ./configure \
 && make -j$(nproc) \
 && cp "$(find . -name mongodb.so -print -quit)" "$(php-config --extension-dir)/" \
 && cd / \
 && rm -rf /tmp/mongodb \
 && docker-php-ext-enable mongodb \
 && docker-php-ext-install pgsql pdo_mysql pdo_pgsql \
 && apk del libpq-dev \
 && rm -rf /var/cache/apk/*

# Redis Extension
FROM compile AS redis
RUN \
  git clone --depth 1 --branch $PHP_REDIS_VERSION https://github.com/phpredis/phpredis.git \
  && cd phpredis \
  && phpize \
  && ./configure \
  && make && make install \
  && mkdir -p /ext \
  && cp $(php-config --extension-dir)/redis.so /ext/redis.so

## Swoole Extension
FROM compile AS swoole
RUN \
  git clone --depth 1 --branch $PHP_SWOOLE_VERSION https://github.com/swoole/swoole-src.git \
  && cd swoole-src \
  && phpize \
  && ./configure --enable-http2 \
  && make && make install \
  && mkdir -p /ext \
  && cp $(php-config --extension-dir)/swoole.so /ext/swoole.so

## PCOV Extension
FROM compile AS pcov
RUN \
   git clone --depth 1 https://github.com/krakjoe/pcov.git \
   && cd pcov \
   && phpize \
   && ./configure --enable-pcov \
   && make && make install \
   && mkdir -p /ext \
   && cp $(php-config --extension-dir)/pcov.so /ext/pcov.so

## XDebug Extension
FROM compile AS xdebug
RUN \
  git clone --depth 1 --branch $PHP_XDEBUG_VERSION https://github.com/xdebug/xdebug && \
  cd xdebug && \
  phpize && \
  ./configure && \
  make && make install \
  && mkdir -p /ext \
  && cp $(php-config --extension-dir)/xdebug.so /ext/xdebug.so

FROM compile AS final

LABEL maintainer="team@appwrite.io"

ARG DEBUG=false
ENV DEBUG=$DEBUG

WORKDIR /usr/src/code

COPY --from=composer /usr/local/src/vendor /usr/src/code/vendor
COPY --from=swoole /ext/swoole.so /ext/swoole.so
COPY --from=redis /ext/redis.so /ext/redis.so
COPY --from=pcov /ext/pcov.so /ext/pcov.so
COPY --from=xdebug /ext/xdebug.so /ext/xdebug.so

RUN EXT_DIR=$(php-config --extension-dir) \
  && mkdir -p $EXT_DIR \
  && cp /ext/*.so $EXT_DIR/

RUN echo extension=redis.so >> /usr/local/etc/php/conf.d/redis.ini
RUN echo extension=swoole.so >> /usr/local/etc/php/conf.d/swoole.ini
RUN echo extension=pcov.so >> /usr/local/etc/php/conf.d/pcov.ini
RUN echo extension=xdebug.so >> /usr/local/etc/php/conf.d/xdebug.ini

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN echo "opcache.enable_cli=1" >> $PHP_INI_DIR/php.ini

RUN echo "memory_limit=1024M" >> $PHP_INI_DIR/php.ini

COPY ./bin /usr/src/code/bin
COPY ./src /usr/src/code/src
COPY ./dev /usr/src/code/dev

# Add Debug Configs
RUN if [ "$DEBUG" = "true" ]; then cp /usr/src/code/dev/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini; fi
RUN if [ "$DEBUG" = "true" ]; then mkdir -p /tmp/xdebug; fi
RUN if [ "$DEBUG" = "false" ]; then rm -rf /usr/src/code/dev; fi
RUN if [ "$DEBUG" = "false" ]; then rm -f $(php-config --extension-dir)/xdebug.so; fi

CMD [ "tail", "-f", "/dev/null" ]
