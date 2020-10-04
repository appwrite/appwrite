FROM ubuntu:18.04 AS builder

LABEL maintainer="team@appwrite.io"

ARG TESTING=false

ENV TZ=Asia/Tel_Aviv \
    DEBIAN_FRONTEND=noninteractive \
    PHP_VERSION=7.4 \
    PHP_REDIS_VERSION=5.3.1

RUN \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests ca-certificates software-properties-common wget git openssl && \
  LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php && \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests make php$PHP_VERSION php$PHP_VERSION-dev zip unzip php$PHP_VERSION-zip && \
  # Redis Extension
  wget -q https://github.com/phpredis/phpredis/archive/$PHP_REDIS_VERSION.tar.gz && \
  tar -xf $PHP_REDIS_VERSION.tar.gz && \
  cd phpredis-$PHP_REDIS_VERSION && \
  phpize$PHP_VERSION && \
  ./configure && \
  make && \
  # Composer
  wget https://getcomposer.org/composer.phar && \
  chmod +x ./composer.phar && \
  mv ./composer.phar /usr/bin/composer

WORKDIR /usr/local/src/

# Updating PHP Dependencies and Auto-loading...

ENV TESTING=$TESTING

COPY composer.* /usr/local/src/

RUN composer update --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist \
    `if [ "$TESTING" != "true" ]; then echo "--no-dev"; fi`

FROM ubuntu:18.04
LABEL maintainer="team@appwrite.io"

ARG VERSION=dev

ENV TZ=Asia/Tel_Aviv \
    DEBIAN_FRONTEND=noninteractive \
    PHP_VERSION=7.4 \
    PHP_API_VERSION=20190902 \
    PHP_REDIS_VERSION=5.3.1 \
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

COPY --from=builder /phpredis-$PHP_REDIS_VERSION/modules/redis.so /usr/lib/php/$PHP_API_VERSION/
COPY --from=builder /phpredis-$PHP_REDIS_VERSION/modules/redis.so /usr/lib/php/$PHP_API_VERSION/

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests wget ca-certificates software-properties-common build-essential libpcre3-dev zlib1g-dev libssl-dev openssl gnupg htop supervisor && \
  LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php && \
  add-apt-repository universe && \
  add-apt-repository ppa:certbot/certbot && \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests php$PHP_VERSION php$PHP_VERSION-fpm \
  php$PHP_VERSION-mysqlnd php$PHP_VERSION-curl php$PHP_VERSION-imagick php$PHP_VERSION-mbstring php$PHP_VERSION-dom webp certbot && \
  # Nginx
  wget -q https://raw.githubusercontent.com/VirtuBox/nginx-ee/master/nginx-build.sh && \
  chmod +x nginx-build.sh && \
  ./nginx-build.sh --openssl-system && \
  rm nginx-build.sh && \
  rm -rf /usr/local/src/* && \
  # Redis Extension
  echo extension=redis.so >> /etc/php/$PHP_VERSION/fpm/conf.d/redis.ini && \
  echo extension=redis.so >> /etc/php/$PHP_VERSION/cli/conf.d/redis.ini && \
  # Cleanup
  cd ../ && \
  apt-get purge -y --auto-remove wget software-properties-common build-essential libpcre3-dev zlib1g-dev libssl-dev gnupg && \
  apt-get clean && \
  rm -rf /var/lib/apt/lists/*

# Set Upload Limit (default to 100MB)
RUN echo "upload_max_filesize = ${_APP_STORAGE_LIMIT}" >> /etc/php/$PHP_VERSION/fpm/conf.d/appwrite.ini
RUN echo "post_max_size = ${_APP_STORAGE_LIMIT}" >> /etc/php/$PHP_VERSION/fpm/conf.d/appwrite.ini

# Add logs file
RUN echo "" >> /var/log/appwrite.log

# Nginx Configuration (with self-signed ssl certificates)
COPY ./docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY ./docker/ssl/cert.pem /etc/nginx/ssl/cert.pem
COPY ./docker/ssl/key.pem /etc/nginx/ssl/key.pem

# PHP Configuration
RUN mkdir -p /var/run/php
COPY ./docker/www.conf /etc/php/$PHP_VERSION/fpm/pool.d/www.conf

# Add PHP Source Code
COPY ./app /usr/share/nginx/html/app
COPY ./bin /usr/local/bin
COPY ./docs /usr/share/nginx/html/docs
COPY ./public /usr/share/nginx/html/public
COPY ./src /usr/share/nginx/html/src
COPY --from=builder /usr/local/src/vendor /usr/share/nginx/html/vendor

RUN mkdir -p /storage/uploads && \
    mkdir -p /storage/cache && \
    mkdir -p /storage/config && \
    mkdir -p /storage/certificates && \
    chown -Rf www-data.www-data /storage/uploads && chmod -Rf 0755 /storage/uploads && \
    chown -Rf www-data.www-data /storage/cache && chmod -Rf 0755 /storage/cache && \
    chown -Rf www-data.www-data /storage/config && chmod -Rf 0755 /storage/config && \
    chown -Rf www-data.www-data /storage/certificates && chmod -Rf 0755 /storage/certificates

# Supervisord Conf
COPY ./docker/supervisord.conf /etc/supervisord.conf

# Executables
RUN chmod +x /usr/local/bin/start
RUN chmod +x /usr/local/bin/doctor
RUN chmod +x /usr/local/bin/migrate
RUN chmod +x /usr/local/bin/test

# Letsencrypt Permissions
RUN mkdir -p /etc/letsencrypt/live/ && chmod -Rf 755 /etc/letsencrypt/live/

EXPOSE 80

WORKDIR /usr/share/nginx/html

CMD ["/bin/bash", "/usr/local/bin/start"]
