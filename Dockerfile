FROM composer:2.0 as step0

ARG TESTING=false
ENV TESTING=$TESTING

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer update --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist \
    `if [ "$TESTING" != "true" ]; then echo "--no-dev"; fi`

FROM php:8.0-cli-alpine as step1

ARG DEBUG=false
ENV DEBUG=$DEBUG

ENV PHP_REDIS_VERSION=5.3.4 \
    PHP_MONGODB_VERSION=1.9.1 \
    PHP_SWOOLE_VERSION=v4.6.7 \
    PHP_IMAGICK_VERSION=3.5.0 \
    PHP_YAML_VERSION=2.2.1 \
    PHP_MAXMINDDB_VERSION=v1.10.1

RUN \
  apk add --no-cache --virtual .deps \
  make \
  automake \
  autoconf \
  gcc \
  g++ \
  git \
  zlib-dev \
  brotli-dev \
  yaml-dev \
  imagemagick \
  imagemagick-dev \
  libmaxminddb-dev

RUN docker-php-ext-install sockets

RUN \
  # Redis Extension
  git clone --depth 1 --branch $PHP_REDIS_VERSION https://github.com/phpredis/phpredis.git && \
  cd phpredis && \
  phpize && \
  ./configure && \
  make && make install && \
  cd .. && \
  # Mongodb Extension
  git clone --depth 1 --branch $PHP_MONGODB_VERSION https://github.com/mongodb/mongo-php-driver.git && \
  cd mongo-php-driver && \
  git submodule update --init && \
  phpize && \
  ./configure && \
  make && make install && \
  cd .. && \
  ## Swoole Extension
  git clone --depth 1 --branch $PHP_SWOOLE_VERSION https://github.com/swoole/swoole-src.git && \
  cd swoole-src && \
  phpize && \
  ./configure --enable-http2 && \
  make && make install && \
  cd .. && \
  ## Imagick Extension
  git clone --depth 1 --branch $PHP_IMAGICK_VERSION https://github.com/imagick/imagick && \
  cd imagick && \
  phpize && \
  ./configure && \
  make && make install && \
  cd .. && \
  ## YAML Extension
  git clone --depth 1 --branch $PHP_YAML_VERSION https://github.com/php/pecl-file_formats-yaml && \
  cd pecl-file_formats-yaml && \
  phpize && \
  ./configure && \
  make && make install && \
  cd .. && \
  ## Maxminddb extension
  git clone --depth 1 --branch $PHP_MAXMINDDB_VERSION https://github.com/maxmind/MaxMind-DB-Reader-php.git && \
  cd MaxMind-DB-Reader-php && \
  cd ext && \
  phpize && \
  ./configure && \
  make && make install && \
  cd ../..

## Swoole Debugger setup
RUN if [ "$DEBUG" == "true" ]; then \
    cd /tmp && \
    apk add boost-dev && \
    git clone --depth 1 https://github.com/swoole/yasd && \
    cd yasd && \
    phpize && \
    ./configure && \
    make && make install && \
    cd ..;\
  fi

FROM php:8.0-cli-alpine as final

LABEL maintainer="team@appwrite.io"

ARG VERSION=dev
ARG DEBUG=false
ENV DEBUG=$DEBUG

ENV _APP_SERVER=swoole \
    _APP_ENV=production \
    _APP_LOCALE=en \
    _APP_DOMAIN=localhost \
    _APP_DOMAIN_TARGET=localhost \
    _APP_HOME=https://appwrite.io \
    _APP_EDITION=community \
    _APP_CONSOLE_WHITELIST_ROOT=enabled \
    _APP_CONSOLE_WHITELIST_EMAILS= \
    _APP_CONSOLE_WHITELIST_IPS= \
    _APP_SYSTEM_EMAIL_NAME= \
    _APP_SYSTEM_EMAIL_ADDRESS= \
    _APP_SYSTEM_RESPONSE_FORMAT= \
    _APP_SYSTEM_SECURITY_EMAIL_ADDRESS= \
    _APP_OPTIONS_ABUSE=enabled \
    _APP_OPTIONS_FORCE_HTTPS=disabled \
    _APP_OPENSSL_KEY_V1=your-secret-key \
    _APP_STORAGE_LIMIT=10000000 \
    _APP_STORAGE_ANTIVIRUS=enabled \
    _APP_STORAGE_ANTIVIRUS_HOST=clamav \
    _APP_STORAGE_ANTIVIRUS_PORT=3310 \
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
    _APP_SMTP_HOST= \
    _APP_SMTP_PORT= \
    _APP_SMTP_SECURE= \
    _APP_SMTP_USERNAME= \
    _APP_SMTP_PASSWORD= \
    _APP_FUNCTIONS_TIMEOUT=900 \
    _APP_FUNCTIONS_CONTAINERS=10 \
    _APP_FUNCTIONS_CPUS=1 \
    _APP_FUNCTIONS_MEMORY=128 \
    _APP_FUNCTIONS_MEMORY_SWAP=128 \
    _APP_SETUP=self-hosted \
    _APP_VERSION=$VERSION \
    _APP_USAGE_STATS=enabled \
    # 14 Days = 1209600 s
    _APP_MAINTENANCE_RETENTION_EXECUTION=1209600 \
    _APP_MAINTENANCE_RETENTION_AUDIT=1209600 \
    # 1 Day = 86400 s
    _APP_MAINTENANCE_RETENTION_ABUSE=86400 \
    _APP_MAINTENANCE_INTERVAL=86400

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN \
  apk update \
  && apk add --no-cache --virtual .deps \
  make \
  automake \
  autoconf \
  gcc \
  g++ \
  curl-dev \
  && apk add --no-cache \
  libstdc++ \
  certbot \
  brotli-dev \
  yaml-dev \
  imagemagick \
  imagemagick-dev \
  libmaxminddb-dev \
  certbot \
  docker-cli \
  docker-compose \
  libgomp \
  && docker-php-ext-install sockets opcache pdo_mysql \
  && apk del .deps \
  && rm -rf /var/cache/apk/*

RUN \
  if [ "$DEBUG" == "true" ]; then \
    apk add boost boost-dev; \
  fi

WORKDIR /usr/src/code

COPY --from=step0 /usr/local/src/vendor /usr/src/code/vendor
COPY --from=step1 /usr/local/lib/php/extensions/no-debug-non-zts-20200930/swoole.so /usr/local/lib/php/extensions/no-debug-non-zts-20200930/yasd.so* /usr/local/lib/php/extensions/no-debug-non-zts-20200930/
COPY --from=step1 /usr/local/lib/php/extensions/no-debug-non-zts-20200930/redis.so /usr/local/lib/php/extensions/no-debug-non-zts-20200930/
COPY --from=step1 /usr/local/lib/php/extensions/no-debug-non-zts-20200930/mongodb.so /usr/local/lib/php/extensions/no-debug-non-zts-20200930/
COPY --from=step1 /usr/local/lib/php/extensions/no-debug-non-zts-20200930/imagick.so /usr/local/lib/php/extensions/no-debug-non-zts-20200930/
COPY --from=step1 /usr/local/lib/php/extensions/no-debug-non-zts-20200930/yaml.so /usr/local/lib/php/extensions/no-debug-non-zts-20200930/
COPY --from=step1 /usr/local/lib/php/extensions/no-debug-non-zts-20200930/maxminddb.so /usr/local/lib/php/extensions/no-debug-non-zts-20200930/ 

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
    chmod +x /usr/local/bin/maintenance && \
    chmod +x /usr/local/bin/usage && \
    chmod +x /usr/local/bin/install && \
    chmod +x /usr/local/bin/migrate && \
    chmod +x /usr/local/bin/schedule && \
    chmod +x /usr/local/bin/sdks && \
    chmod +x /usr/local/bin/ssl && \
    chmod +x /usr/local/bin/test && \
    chmod +x /usr/local/bin/vars && \
    chmod +x /usr/local/bin/worker-audits && \
    chmod +x /usr/local/bin/worker-certificates && \
    chmod +x /usr/local/bin/worker-database && \
    chmod +x /usr/local/bin/worker-deletes && \
    chmod +x /usr/local/bin/worker-functions && \
    chmod +x /usr/local/bin/worker-mails && \
    chmod +x /usr/local/bin/worker-webhooks

# Letsencrypt Permissions
RUN mkdir -p /etc/letsencrypt/live/ && chmod -Rf 755 /etc/letsencrypt/live/

# Enable Extensions
RUN echo extension=swoole.so >> /usr/local/etc/php/conf.d/swoole.ini
RUN echo extension=redis.so >> /usr/local/etc/php/conf.d/redis.ini
RUN echo extension=imagick.so >> /usr/local/etc/php/conf.d/imagick.ini
RUN echo extension=yaml.so >> /usr/local/etc/php/conf.d/yaml.ini
RUN echo extension=maxminddb.so >> /usr/local/etc/php/conf.d/maxminddb.ini
RUN if [ "$DEBUG" == "true" ]; then printf "zend_extension=yasd \nyasd.debug_mode=remote \nyasd.init_file=/usr/local/dev/yasd_init.php \nyasd.remote_port=9005 \nyasd.log_level=-1" >> /usr/local/etc/php/conf.d/yasd.ini; fi

RUN if [ "$DEBUG" == "true" ]; then echo "opcache.enable=0" >> /usr/local/etc/php/conf.d/appwrite.ini; fi
RUN echo "opcache.preload_user=www-data" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.preload=/usr/src/code/app/preload.php" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.jit_buffer_size=100M" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.jit=1235" >> /usr/local/etc/php/conf.d/appwrite.ini

EXPOSE 80

CMD [ "php", "app/http.php", "-dopcache.preload=opcache.preload=/usr/src/code/app/preload.php" ]
