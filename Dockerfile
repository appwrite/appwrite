FROM composer:2.0 as step0

ARG TESTING=false
ENV TESTING=$TESTING

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer update --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist \
    `if [ "$TESTING" != "true" ]; then echo "--no-dev"; fi`

FROM php:7.4-cli as step1

ENV TZ=Asia/Tel_Aviv \
    DEBIAN_FRONTEND=noninteractive \
    PHP_REDIS_VERSION=5.2.1 \
    PHP_SWOOLE_VERSION=4.5.2

RUN \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests ca-certificates software-properties-common wget git openssl make zip unzip
  
RUN docker-php-ext-install sockets

RUN \
  # Redis Extension
  wget -q https://github.com/phpredis/phpredis/archive/$PHP_REDIS_VERSION.tar.gz && \
  tar -xf $PHP_REDIS_VERSION.tar.gz && \
  cd phpredis-$PHP_REDIS_VERSION && \
  phpize && \
  ./configure && \
  make && make install && \
  cd .. && \
  ## Swoole Extension
  git clone https://github.com/swoole/swoole-src.git && \
  cd swoole-src && \
  git checkout v$PHP_SWOOLE_VERSION && \
  phpize && \
  ./configure --enable-sockets --enable-http2 && \
  make && make install
  ## Brotli Extension

FROM php:7.4-cli as final

LABEL maintainer="team@appwrite.io"

ARG VERSION=dev

ENV TZ=Asia/Tel_Aviv \
    DEBIAN_FRONTEND=noninteractive \
    _APP_ENV=production \
    _APP_DOMAIN=localhost \
    _APP_DOMAIN_TARGET=localhost \
    _APP_HOME=https://appwrite.io \
    _APP_EDITION=community \
    _APP_OPTIONS_ABUSE=enabled \
    _APP_OPTIONS_FORCE_HTTPS=disabled \
    _APP_OPENSSL_KEY_V1=your-secret-key \
    _APP_STORAGE_LIMIT=104857600 \
    _APP_STORAGE_ANTIVIRUS=enabled \
    _APP_REDIS_HOST=redis \
    _APP_REDIS_PORT=6379 \
    _APP_DB_HOST=mariadb \
    _APP_DB_PORT=3306 \
    _APP_DB_USER=root \
    _APP_DB_PASS=password \
    _APP_DB_SCHEMA=appwrite \
    _APP_INFLUXDB_HOST=influxdb \
    _APP_INFLUXDB_PORT=8086 \
    _APP_STATSD_HOST=telegraf \
    _APP_STATSD_PORT=8125 \
    _APP_SMTP_HOST=smtp \
    _APP_SMTP_PORT=25 \
    _APP_SETUP=self-hosted \
    _APP_VERSION=$VERSION
#ENV _APP_SMTP_SECURE ''
#ENV _APP_SMTP_USERNAME ''
#ENV _APP_SMTP_PASSWORD ''

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests webp certbot \
  libonig-dev libcurl4-gnutls-dev libmagickwand-dev libyaml-dev && \
  pecl install imagick yaml && \ 
  docker-php-ext-enable imagick yaml

RUN docker-php-ext-install sockets curl pdo opcache

WORKDIR /usr/src/code

COPY --from=step0 /usr/local/src/vendor /usr/src/code/vendor
COPY --from=step1 /usr/local/lib/php/extensions/no-debug-non-zts-20190902/swoole.so /usr/local/lib/php/extensions/no-debug-non-zts-20190902/
COPY --from=step1 /usr/local/lib/php/extensions/no-debug-non-zts-20190902/redis.so /usr/local/lib/php/extensions/no-debug-non-zts-20190902/
COPY --from=step1 /usr/local/lib/php/extensions/no-debug-non-zts-20190902/redis.so /usr/local/lib/php/extensions/no-debug-non-zts-20190902/

# Add Source Code
COPY ./app /usr/src/code/app
COPY ./bin /usr/local/bin
COPY ./docs /usr/src/code/docs
COPY ./public /usr/src/code/public
COPY ./src /usr/src/code/src

# Set Volumes
RUN mkdir -p /storage/uploads && \
    mkdir -p /storage/cache && \
    mkdir -p /storage/config && \
    mkdir -p /storage/certificates && \
    chown -Rf www-data.www-data /storage/uploads && chmod -Rf 0755 /storage/uploads && \
    chown -Rf www-data.www-data /storage/cache && chmod -Rf 0755 /storage/cache && \
    chown -Rf www-data.www-data /storage/config && chmod -Rf 0755 /storage/config && \
    chown -Rf www-data.www-data /storage/certificates && chmod -Rf 0755 /storage/certificates

# Executables
RUN chmod +x /usr/local/bin/start
RUN chmod +x /usr/local/bin/doctor
RUN chmod +x /usr/local/bin/migrate
RUN chmod +x /usr/local/bin/test

# Letsencrypt Permissions
RUN mkdir -p /etc/letsencrypt/live/ && chmod -Rf 755 /etc/letsencrypt/live/

# Enable Extensions
RUN echo extension=swoole.so >> /usr/local/etc/php/conf.d/swoole.ini
RUN echo extension=redis.so >> /usr/local/etc/php/conf.d/redis.ini

EXPOSE 9501

# CMD [ "php" , "app/server.php" ]
CMD [ "php" , "-i" ]

# static files: https://gist.github.com/ezimuel/a2e0ff7308952f2aa946f828a1302a63

# docker build -t saw .
# docker run -it --rm --name saw-run saw