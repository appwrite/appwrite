FROM ubuntu:18.04 AS builder

LABEL maintainer="team@appwrite.io"

ARG TESTING=false

ENV TZ=Asia/Tel_Aviv \
    DEBIAN_FRONTEND=noninteractive \
    PHP_VERSION=7.3 \
    PHP_REDIS_VERSION=3.1.2

RUN \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests ca-certificates software-properties-common wget curl git openssl && \
  LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php && \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests make php$PHP_VERSION php$PHP_VERSION-dev zip unzip php$PHP_VERSION-zip && \
  # redis php extension
  wget -q https://github.com/phpredis/phpredis/archive/$PHP_REDIS_VERSION.tar.gz && \
  tar -xf $PHP_REDIS_VERSION.tar.gz && \
  cd phpredis-$PHP_REDIS_VERSION && \
  phpize$PHP_VERSION && \
  ./configure && \
  make && \
  # composer
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

WORKDIR /usr/local/src/
# Updating PHP dependencies and auto-loading...
ENV TESTING=$TESTING
COPY composer.* /usr/local/src/
RUN composer update --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist \
    `if [ "$TESTING" != "true" ]; then echo "--no-dev"; fi`

FROM ubuntu:18.04
LABEL maintainer="team@appwrite.io"

ENV TZ=Asia/Tel_Aviv \
    DEBIAN_FRONTEND=noninteractive \
    PHP_VERSION=7.3 \
    _APP_ENV=production \
    _APP_HOME=https://appwrite.io \
    _APP_EDITION=community \
    _APP_OPTIONS_ABUSE=enabled \
    _APP_OPENSSL_KEY_V1=your-secret-key \
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
    _APP_SMTP_PORT=25
#ENV _APP_SMTP_SECURE ''
#ENV _APP_SMTP_USERNAME ''
#ENV _APP_SMTP_PASSWORD ''

COPY --from=builder /phpredis-3.1.2/modules/redis.so /usr/lib/php/20180731/

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests wget curl ca-certificates software-properties-common openssl gnupg && \
  LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php && \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests htop supervisor php$PHP_VERSION php$PHP_VERSION-fpm \
  php$PHP_VERSION-mysqlnd php$PHP_VERSION-curl php$PHP_VERSION-imagick php$PHP_VERSION-mbstring php$PHP_VERSION-dom webp && \
  # nginx
  echo "deb http://nginx.org/packages/mainline/ubuntu/ bionic nginx" >> /etc/apt/sources.list.d/nginx.list && \
  wget -q http://nginx.org/keys/nginx_signing.key && \
  apt-key add nginx_signing.key && \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests nginx && \
  # redis php extension
  echo extension=redis.so >> /etc/php/$PHP_VERSION/fpm/conf.d/redis.ini && \
  echo extension=redis.so >> /etc/php/$PHP_VERSION/cli/conf.d/redis.ini && \
  # composer
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer && \
  # cleanup
  cd ../ && \
  apt-get purge -y --auto-remove software-properties-common gnupg curl && \
  apt-get clean && \
  rm -rf /var/lib/apt/lists/*

# Set upload limit
RUN echo "upload_max_filesize = 4M" > /etc/php/$PHP_VERSION/fpm/conf.d/appwrite.ini

# nginx conf (with ssl certificates)
COPY ./docker/nginx.conf /etc/nginx/nginx.conf
COPY ./docker/ssl/cert.pem /etc/nginx/ssl/cert.pem
COPY ./docker/ssl/key.pem /etc/nginx/ssl/key.pem

# php conf
RUN mkdir -p /var/run/php
COPY ./docker/www.conf /etc/php/7.3/fpm/pool.d/www.conf

# add PHP files
COPY ./app /usr/share/nginx/html/app
COPY ./docs /usr/share/nginx/html/docs
COPY ./public /usr/share/nginx/html/public
COPY ./src /usr/share/nginx/html/src
COPY --from=builder /usr/local/src/vendor /usr/share/nginx/html/vendor

RUN mkdir -p /storage/uploads && \
    mkdir -p /storage/cache && \
    chown -Rf www-data.www-data /storage/uploads && chmod -Rf 0755 /storage/uploads && \
    chown -Rf www-data.www-data /storage/cache && chmod -Rf 0755 /storage/cache

# supervisord conf
COPY ./docker/supervisord.conf /etc/supervisord.conf
COPY ./docker/entrypoint.sh /entrypoint.sh
RUN chmod 775 /entrypoint.sh

EXPOSE 80

WORKDIR /usr/share/nginx/html

CMD ["/bin/bash", "/entrypoint.sh"]
