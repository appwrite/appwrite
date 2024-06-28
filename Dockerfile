FROM composer:2.0 as composer

ARG TESTING=false
ENV TESTING=$TESTING

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist \
    `if [ "$TESTING" != "true" ]; then echo "--no-dev"; fi`

FROM --platform=$BUILDPLATFORM node:20.11.0-alpine3.19 as node

COPY app/console /usr/local/src/console

WORKDIR /usr/local/src/console

ARG VITE_GA_PROJECT
ARG VITE_CONSOLE_MODE
ARG VITE_APPWRITE_GROWTH_ENDPOINT=https://growth.appwrite.io/v1

ENV VITE_GA_PROJECT=$VITE_GA_PROJECT
ENV VITE_CONSOLE_MODE=$VITE_CONSOLE_MODE
ENV VITE_APPWRITE_GROWTH_ENDPOINT=$VITE_APPWRITE_GROWTH_ENDPOINT

RUN npm ci
RUN npm run build

FROM php:8.2.20-cli-alpine3.20 as compile

ENV PHP_REDIS_VERSION="6.0.2" \
    PHP_MONGODB_VERSION="1.16.1" \
    PHP_SWOOLE_VERSION="v5.1.3" \
    PHP_IMAGICK_VERSION="3.7.0" \
    PHP_YAML_VERSION="2.2.3" \
    PHP_MAXMINDDB_VERSION="v1.11.1" \
    PHP_SCRYPT_VERSION="2.0.1" \
    PHP_ZSTD_VERSION="0.13.3" \
    PHP_BROTLI_VERSION="0.15.0" \
    PHP_SNAPPY_VERSION="c27f830dcfe6c41eb2619a374de10fd0597f4939" \
    PHP_LZ4_VERSION="2f006c3e4f1fb3a60d2656fc164f9ba26b71e995" \
    PHP_XDEBUG_VERSION="3.3.1"

RUN \
  apk add --no-cache --virtual .deps \
  linux-headers \
  make \
  automake \
  autoconf \
  gcc \
  g++ \
  git \
  zlib-dev \
  openssl-dev \
  yaml-dev \
  imagemagick \
  imagemagick-dev \
  libjpeg-turbo-dev \
  jpeg-dev \
  libjxl-dev \
  libmaxminddb-dev \
  zstd-dev \
  brotli-dev \
  lz4-dev \
  curl-dev

RUN docker-php-ext-install sockets

FROM compile AS redis
RUN \
  # Redis Extension
  git clone --depth 1 --branch $PHP_REDIS_VERSION https://github.com/phpredis/phpredis.git && \
  cd phpredis && \
  phpize && \
  ./configure && \
  make && make install

## Swoole Extension
FROM compile AS swoole
RUN \
  git clone --depth 1 --branch $PHP_SWOOLE_VERSION https://github.com/swoole/swoole-src.git && \
  cd swoole-src && \
  phpize && \
  ./configure --enable-sockets --enable-http2 --enable-openssl --enable-swoole-curl && \
  make && make install && \
  cd ..

## Imagick Extension
FROM compile AS imagick
RUN \
  git clone --depth 1 --branch $PHP_IMAGICK_VERSION https://github.com/imagick/imagick && \
  cd imagick && \
  phpize && \
  ./configure && \
  make && make install

## YAML Extension
FROM compile AS yaml
RUN \
  git clone --depth 1 --branch $PHP_YAML_VERSION https://github.com/php/pecl-file_formats-yaml && \
  cd pecl-file_formats-yaml && \
  phpize && \
  ./configure && \
  make && make install

## Maxminddb extension
FROM compile AS maxmind
RUN \
  git clone --depth 1 --branch $PHP_MAXMINDDB_VERSION https://github.com/maxmind/MaxMind-DB-Reader-php.git && \
  cd MaxMind-DB-Reader-php && \
  cd ext && \
  phpize && \
  ./configure && \
  make && make install

# Mongodb Extension
FROM compile as mongodb
RUN \
  git clone --depth 1 --branch $PHP_MONGODB_VERSION https://github.com/mongodb/mongo-php-driver.git && \
  cd mongo-php-driver && \
  git submodule update --init && \
  phpize && \
  ./configure && \
  make && make install

# Zstd Compression
FROM compile as zstd
RUN git clone --recursive -n https://github.com/kjdev/php-ext-zstd.git \
  && cd php-ext-zstd \
  && git checkout $PHP_ZSTD_VERSION \
  && phpize \
  && ./configure --with-libzstd \
  && make && make install

## Brotli Extension
FROM compile as brotli
RUN git clone https://github.com/kjdev/php-ext-brotli.git \
  && cd php-ext-brotli \
  && git reset --hard $PHP_BROTLI_VERSION \
  && phpize \
  && ./configure --with-libbrotli \
  && make && make install

## LZ4 Extension
FROM compile AS lz4
RUN git clone --recursive https://github.com/kjdev/php-ext-lz4.git \
  && cd php-ext-lz4 \
  && git reset --hard $PHP_LZ4_VERSION \
  && phpize \
  && ./configure --with-lz4-includedir=/usr \
  && make && make install

## Snappy Extension
FROM compile AS snappy
RUN git clone --recursive https://github.com/kjdev/php-ext-snappy.git \
  && cd php-ext-snappy \
  && git reset --hard $PHP_SNAPPY_VERSION \
  && phpize \
  && ./configure \
  && make && make install

## Scrypt Extension
FROM compile AS scrypt
RUN git clone --depth 1 https://github.com/DomBlack/php-scrypt.git  \
  && cd php-scrypt  \
  && git reset --hard $PHP_SCRYPT_VERSION  \
  && phpize  \
  && ./configure --enable-scrypt  \
  && make && make install

## XDebug Extension
FROM compile AS xdebug
RUN \
  git clone --depth 1 --branch $PHP_XDEBUG_VERSION https://github.com/xdebug/xdebug && \
  cd xdebug && \
  phpize && \
  ./configure && \
  make && make install

FROM php:8.2.20-cli-alpine3.20 as finald

LABEL maintainer="team@appwrite.io"

ENV DOCKER_CONFIG=${DOCKER_CONFIG:-$HOME/.docker}
ENV DOCKER_COMPOSE_VERSION="v2.24.6"

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN set -ex \
  && apk --no-cache add \
    postgresql-dev

RUN \
  apk update \
  && apk add --no-cache --virtual .deps \
  linux-headers \
  make \
  automake \
  autoconf \
  gcc \
  g++ \
  curl-dev \
  && apk add --no-cache \
  libstdc++ \
  rsync \
  brotli-dev \
  lz4-dev \
  yaml-dev \
  imagemagick \
  imagemagick-dev \
  libjpeg-turbo-dev \
  jpeg-dev \
  libjxl-dev \
  libavif \
  libheif \
  imagemagick-heic \
  libmaxminddb-dev \
  certbot \
  docker-cli \
  libgomp \
  git \
  && docker-php-ext-install sockets pdo_mysql pdo_pgsql intl \
  && apk del .deps \
  && rm -rf /var/cache/apk/*

RUN \
  mkdir -p $DOCKER_CONFIG/cli-plugins \
  && ARCH=$(uname -m) && if [ $ARCH == "armv7l" ]; then ARCH="armv7"; fi \
  && curl -SL https://github.com/docker/compose/releases/download/$DOCKER_COMPOSE_VERSION/docker-compose-linux-$ARCH -o $DOCKER_CONFIG/cli-plugins/docker-compose \
  && chmod +x $DOCKER_CONFIG/cli-plugins/docker-compose

WORKDIR /usr/src/code

COPY --from=swoole /usr/local/lib/php/extensions/no-debug-non-zts-20220829/swoole.so /usr/local/lib/php/extensions/no-debug-non-zts-20220829/
COPY --from=redis /usr/local/lib/php/extensions/no-debug-non-zts-20220829/redis.so /usr/local/lib/php/extensions/no-debug-non-zts-20220829/
COPY --from=imagick /usr/local/lib/php/extensions/no-debug-non-zts-20220829/imagick.so /usr/local/lib/php/extensions/no-debug-non-zts-20220829/
COPY --from=yaml /usr/local/lib/php/extensions/no-debug-non-zts-20220829/yaml.so /usr/local/lib/php/extensions/no-debug-non-zts-20220829/
COPY --from=maxmind /usr/local/lib/php/extensions/no-debug-non-zts-20220829/maxminddb.so /usr/local/lib/php/extensions/no-debug-non-zts-20220829/
COPY --from=mongodb /usr/local/lib/php/extensions/no-debug-non-zts-20220829/mongodb.so /usr/local/lib/php/extensions/no-debug-non-zts-20220829/
COPY --from=scrypt /usr/local/lib/php/extensions/no-debug-non-zts-20220829/scrypt.so /usr/local/lib/php/extensions/no-debug-non-zts-20220829/
COPY --from=zstd /usr/local/lib/php/extensions/no-debug-non-zts-20220829/zstd.so /usr/local/lib/php/extensions/no-debug-non-zts-20220829/
COPY --from=brotli /usr/local/lib/php/extensions/no-debug-non-zts-20220829/brotli.so /usr/local/lib/php/extensions/no-debug-non-zts-20220829/
COPY --from=lz4 /usr/local/lib/php/extensions/no-debug-non-zts-20220829/lz4.so /usr/local/lib/php/extensions/no-debug-non-zts-20220829/
COPY --from=snappy /usr/local/lib/php/extensions/no-debug-non-zts-20220829/snappy.so /usr/local/lib/php/extensions/no-debug-non-zts-20220829/
COPY --from=xdebug /usr/local/lib/php/extensions/no-debug-non-zts-20220829/xdebug.so /usr/local/lib/php/extensions/no-debug-non-zts-20220829/

# Enable Extensions
RUN echo extension=swoole.so >> /usr/local/etc/php/conf.d/swoole.ini
RUN echo extension=redis.so >> /usr/local/etc/php/conf.d/redis.ini
RUN echo extension=imagick.so >> /usr/local/etc/php/conf.d/imagick.ini
RUN echo extension=yaml.so >> /usr/local/etc/php/conf.d/yaml.ini
RUN echo extension=maxminddb.so >> /usr/local/etc/php/conf.d/maxminddb.ini
RUN echo extension=scrypt.so >> /usr/local/etc/php/conf.d/scrypt.ini
RUN echo extension=zstd.so >> /usr/local/etc/php/conf.d/zstd.ini
RUN echo extension=brotli.so >> /usr/local/etc/php/conf.d/brotli.ini
RUN echo extension=lz4.so >> /usr/local/etc/php/conf.d/lz4.ini
RUN echo extension=snappy.so >> /usr/local/etc/php/conf.d/snappy.ini

LABEL maintainer="team@appwrite.io"

ARG VERSION=dev
ARG DEBUG=false
ENV DEBUG=$DEBUG

ENV _APP_VERSION=$VERSION \
    _APP_HOME=https://appwrite.io

RUN \
  if [ "$DEBUG" == "true" ]; then \
    apk add boost boost-dev; \
  fi

WORKDIR /usr/src/code

COPY --from=composer /usr/local/src/vendor /usr/src/code/vendor
COPY --from=node /usr/local/src/console/build /usr/src/code/console

# Add Source Code
COPY ./app /usr/src/code/app
COPY ./public /usr/src/code/public
COPY ./bin /usr/local/bin
COPY ./docs /usr/src/code/docs
COPY ./src /usr/src/code/src
COPY ./dev /usr/src/code/dev

# Set Volumes
RUN mkdir -p /storage/uploads && \
    mkdir -p /storage/cache && \
    mkdir -p /storage/config && \
    mkdir -p /storage/certificates && \
    mkdir -p /storage/functions && \
    mkdir -p /storage/debug && \
    chown -Rf www-data.www-data /storage/uploads && chmod -Rf 0755 /storage/uploads && \
    chown -Rf www-data.www-data /storage/cache && chmod -Rf 0755 /storage/cache && \
    chown -Rf www-data.www-data /storage/config && chmod -Rf 0755 /storage/config && \
    chown -Rf www-data.www-data /storage/certificates && chmod -Rf 0755 /storage/certificates && \
    chown -Rf www-data.www-data /storage/functions && chmod -Rf 0755 /storage/functions && \
    chown -Rf www-data.www-data /storage/debug && chmod -Rf 0755 /storage/debug

# Executables
RUN chmod +x /usr/local/bin/doctor && \
    chmod +x /usr/local/bin/install && \
    chmod +x /usr/local/bin/maintenance &&  \
    chmod +x /usr/local/bin/migrate && \
    chmod +x /usr/local/bin/realtime && \
    chmod +x /usr/local/bin/schedule-functions && \
    chmod +x /usr/local/bin/schedule-messages && \
    chmod +x /usr/local/bin/sdks && \
    chmod +x /usr/local/bin/specs && \
    chmod +x /usr/local/bin/ssl && \
    chmod +x /usr/local/bin/test && \
    chmod +x /usr/local/bin/upgrade && \
    chmod +x /usr/local/bin/vars && \
    chmod +x /usr/local/bin/queue-retry && \
    chmod +x /usr/local/bin/queue-count-failed && \
    chmod +x /usr/local/bin/queue-count-processing && \
    chmod +x /usr/local/bin/queue-count-success && \
    chmod +x /usr/local/bin/worker-audits && \
    chmod +x /usr/local/bin/worker-builds && \
    chmod +x /usr/local/bin/worker-certificates && \
    chmod +x /usr/local/bin/worker-databases && \
    chmod +x /usr/local/bin/worker-deletes && \
    chmod +x /usr/local/bin/worker-functions && \
    chmod +x /usr/local/bin/worker-mails && \
    chmod +x /usr/local/bin/worker-messaging && \
    chmod +x /usr/local/bin/worker-migrations && \
    chmod +x /usr/local/bin/worker-webhooks && \
    chmod +x /usr/local/bin/worker-usage && \
    chmod +x /usr/local/bin/worker-usage-dump

# Letsencrypt Permissions
RUN mkdir -p /etc/letsencrypt/live/ && chmod -Rf 755 /etc/letsencrypt/live/

# Enable Extensions
RUN if [ "$DEBUG" == "true" ]; then cp /usr/src/code/dev/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini; fi
RUN if [ "$DEBUG" = "false" ]; then rm -rf /usr/src/code/dev; fi
RUN if [ "$DEBUG" = "false" ]; then rm -f /usr/local/lib/php/extensions/no-debug-non-zts-20220829/xdebug.so; fi

EXPOSE 80

CMD [ "php", "app/http.php" ]
