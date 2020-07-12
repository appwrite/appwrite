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
    PHP_REDIS_VERSION=5.3.0 \
    PHP_SWOOLE_VERSION=4.5.2 \
    PHP_XDEBUG_VERSION=sdebug_2_9-beta

RUN \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests ca-certificates software-properties-common wget git openssl make zip unzip libbrotli-dev libz-dev
  
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
  make && make install && \
  cd ..
  ## XDebug Extension
  # git clone https://github.com/swoole/sdebug.git && \
  # cd sdebug && \
  # git checkout $PHP_XDEBUG_VERSION && \
  # phpize && \
  # ./configure --enable-xdebug && \
  # make clean && make && make install
  # cd .. && \
  # Meminfo Extension
  # git clone https://github.com/BitOne/php-meminfo.git && \
  # cd php-meminfo && \
  # git checkout v1.0.5 && \
  # cd extension/php7 && \
  # phpize && \
  # ./configure --enable-meminfo && \
  # make && make install

FROM php:7.4-cli as final

LABEL maintainer="team@appwrite.io"

ARG VERSION=dev

ENV TZ=Asia/Tel_Aviv \
    DEBIAN_FRONTEND=noninteractive \
    _APP_SERVER=swoole \
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
  apt-get install -y --no-install-recommends --no-install-suggests webp certbot htop procps docker.io \
  libonig-dev libcurl4-gnutls-dev libmagickwand-dev libyaml-dev libbrotli-dev libz-dev && \
  pecl install imagick yaml && \ 
  docker-php-ext-enable imagick yaml

RUN docker-php-ext-install sockets curl opcache pdo pdo_mysql

WORKDIR /usr/src/code

COPY --from=step0 /usr/local/src/vendor /usr/src/code/vendor
COPY --from=step1 /usr/local/lib/php/extensions/no-debug-non-zts-20190902/swoole.so /usr/local/lib/php/extensions/no-debug-non-zts-20190902/
COPY --from=step1 /usr/local/lib/php/extensions/no-debug-non-zts-20190902/redis.so /usr/local/lib/php/extensions/no-debug-non-zts-20190902/
# COPY --from=step1 /usr/local/lib/php/extensions/no-debug-non-zts-20190902/xdebug.so /usr/local/lib/php/extensions/no-debug-non-zts-20190902/
# COPY --from=step1 /usr/local/lib/php/extensions/no-debug-non-zts-20190902/meminfo.so /usr/local/lib/php/extensions/no-debug-non-zts-20190902/

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
    mkdir -p /storage/debug && \
    chown -Rf www-data.www-data /storage/uploads && chmod -Rf 0755 /storage/uploads && \
    chown -Rf www-data.www-data /storage/cache && chmod -Rf 0755 /storage/cache && \
    chown -Rf www-data.www-data /storage/config && chmod -Rf 0755 /storage/config && \
    chown -Rf www-data.www-data /storage/certificates && chmod -Rf 0755 /storage/certificates && \
    chown -Rf www-data.www-data /storage/debug && chmod -Rf 0755 /storage/debug

# Executables
RUN chmod +x /usr/local/bin/doctor
RUN chmod +x /usr/local/bin/migrate
RUN chmod +x /usr/local/bin/schedule
RUN chmod +x /usr/local/bin/test
RUN chmod +x /usr/local/bin/worker-audits
RUN chmod +x /usr/local/bin/worker-certificates
RUN chmod +x /usr/local/bin/worker-deletes
RUN chmod +x /usr/local/bin/worker-mails
RUN chmod +x /usr/local/bin/worker-tasks
RUN chmod +x /usr/local/bin/worker-usage
RUN chmod +x /usr/local/bin/worker-webhooks

# Letsencrypt Permissions
RUN mkdir -p /etc/letsencrypt/live/ && chmod -Rf 755 /etc/letsencrypt/live/

# Enable Extensions
RUN echo extension=swoole.so >> /usr/local/etc/php/conf.d/swoole.ini
RUN echo extension=redis.so >> /usr/local/etc/php/conf.d/redis.ini
# RUN echo zend_extension=xdebug.so >> /usr/local/etc/php/conf.d/xdebug.ini
# RUN echo extension=meminfo.so >> /usr/local/etc/php/conf.d/meminfo.ini

RUN echo "opcache.preload_user=www-data" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.preload=/usr/src/code/app/preload.php" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.enable_cli = 1" >> /usr/local/etc/php/conf.d/appwrite.ini
# RUN echo "xdebug.profiler_enable = 1" >> /usr/local/etc/php/conf.d/appwrite.ini
# RUN echo "xdebug.profiler_output_dir = /tmp/" >> /usr/local/etc/php/conf.d/appwrite.ini
# RUN echo "xdebug.profiler_enable_trigger = 1" >> /usr/local/etc/php/conf.d/appwrite.ini
# RUN echo "xdebug.trace_format = 1" >> /usr/local/etc/php/conf.d/appwrite.ini

EXPOSE 80

#, "-dxdebug.auto_trace=1"
#, "-dxdebug.profiler_enable=1"
#, "-dopcache.preload=opcache.preload=/usr/src/code/app/preload.php"

CMD [ "php", "app/server.php", "-dopcache.preload=opcache.preload=/usr/src/code/app/preload.php" ]
