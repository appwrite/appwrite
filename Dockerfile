FROM ubuntu:18.04 AS builder

LABEL maintainer="team@appwrite.io"

ARG TESTING=false

ENV TZ=Asia/Tel_Aviv \
    DEBIAN_FRONTEND=noninteractive \
    PHP_VERSION=7.4 \
    PHP_REDIS_VERSION=5.2.1

RUN \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests ca-certificates software-properties-common wget curl git openssl nginx && \
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
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

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

COPY --from=builder /phpredis-5.2.1/modules/redis.so /usr/lib/php/20190902/

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests git wget curl ca-certificates software-properties-common openssl gnupg && \
  LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php && \
  add-apt-repository universe && \
  add-apt-repository ppa:certbot/certbot && \
  apt-get update && \
  apt-get install -y --no-install-recommends --no-install-suggests htop supervisor php$PHP_VERSION php$PHP_VERSION-fpm \
  php$PHP_VERSION-mysqlnd php$PHP_VERSION-curl php$PHP_VERSION-imagick php$PHP_VERSION-mbstring php$PHP_VERSION-dom webp certbot && \
  # Nginx
  cd /usr/local/src && \
  wget http://nginx.org/download/nginx-1.19.0.tar.gz && \
  tar -xzvf nginx-1.19.0.tar.gz && \
  cd - && \
  # Redis Extension
  echo "extension=redis.so" >> /etc/php/$PHP_VERSION/fpm/conf.d/redis.ini && \
  echo "extension=redis.so" >> /etc/php/$PHP_VERSION/cli/conf.d/redis.ini && \
  #Brotli
  cd /usr/local/src \
  && git clone https://github.com/google/ngx_brotli.git --recursive \
  && cd ngx_brotli \
  && git submodule update --recursive


RUN ls -la /usr/local/src/ngx_brotli

WORKDIR /usr/local/src/nginx-1.19.0
RUN apt-get update && \
    apt-get install -y --no-install-recommends --no-install-suggests gcc build-essential pcre-devel && \
    ./configure --with-cc-opt='-g -O2 -fPIE -fstack-protector-strong -Wformat -Werror=format-security -Wdate-time -D_FORTIFY_SOURCE=2' \
    --with-ld-opt='-Wl,-Bsymbolic-functions -fPIE -pie -Wl,-z,relro -Wl,-z,now' \
    --prefix=/usr/share/nginx \
    --conf-path=/etc/nginx/nginx.conf \
    --http-log-path=/var/log/nginx/access.log \
    --error-log-path=/var/log/nginx/error.log \
    --lock-path=/var/lock/nginx.lock \
    --pid-path=/run/nginx.pid \
    --http-client-body-temp-path=/var/lib/nginx/body \
    --http-fastcgi-temp-path=/var/lib/nginx/fastcgi \
    --http-proxy-temp-path=/var/lib/nginx/proxy \
    --http-scgi-temp-path=/var/lib/nginx/scgi \
    --http-uwsgi-temp-path=/var/lib/nginx/uwsgi \
    --with-debug \
    --with-pcre-jit \
    --with-ipv6 \
    --with-http_ssl_module \
    --with-http_stub_status_module \
    --with-http_realip_module \
    --with-http_auth_request_module \
    --with-http_addition_module \
    --with-http_dav_module \
    --with-http_geoip_module \
    --with-http_gunzip_module \
    --with-http_gzip_static_module \
    --with-http_image_filter_module \
    --with-http_v2_module \
    --with-http_sub_module \
    --with-http_xslt_module \
    --with-stream \
    --with-stream_ssl_module \
    --with-mail \
    --with-mail_ssl_module \
    --with-threads \
    --add-module=/usr/local/src/ngx_brotli \
    --sbin-path=/usr/sbin/nginx

# Cleanup
RUN make \
    && apt-get remove nginx -y \
    && apt-get remove nginx-common -y \
    && checkinstall -y \
    && mkdir -p /var/lib/nginx \
    && mkdir -p /var/lib/nginx/body \
    && mkdir -p /var/lib/nginx/fastcgi \
    && cd ../ && \
    apt-get purge -y --auto-remove software-properties-common gnupg curl && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /

# Set Upload Limit (default to 100MB)
RUN echo "upload_max_filesize = ${_APP_STORAGE_LIMIT}" >> /etc/php/$PHP_VERSION/fpm/conf.d/appwrite.ini
RUN echo "post_max_size = ${_APP_STORAGE_LIMIT}" >> /etc/php/$PHP_VERSION/fpm/conf.d/appwrite.ini
RUN echo "env[TESTME] = your-secret-key" >> /etc/php/$PHP_VERSION/fpm/conf.d/appwrite.ini

# Add logs file
RUN echo "" >> /var/log/appwrite.log

# Nginx Configuration (with self-signed ssl certificates)
COPY ./docker/nginx.conf /etc/nginx/nginx.conf
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
RUN chmod +x /usr/local/bin/migrate
RUN chmod +x /usr/local/bin/test

# Letsencrypt Permissions
RUN mkdir -p /etc/letsencrypt/live/ && chmod -Rf 755 /etc/letsencrypt/live/

EXPOSE 80

WORKDIR /usr/share/nginx/html

CMD ["/bin/bash", "/usr/local/bin/start"]
