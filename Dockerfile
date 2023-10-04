FROM composer:2.0 as composer

ARG TESTING=false
ENV TESTING=$TESTING

WORKDIR /usr/local/src/

COPY composer.lock composer.json /usr/local/src/

RUN composer install --ignore-platform-reqs --optimize-autoloader --no-plugins --no-scripts --prefer-dist \
    `if [ "$TESTING" != "true" ]; then echo "--no-dev"; fi`

FROM --platform=$BUILDPLATFORM node:16.14.2-alpine3.15 as node

ARG VITE_GA_PROJECT
ARG VITE_CONSOLE_MODE
ARG VITE_APPWRITE_GROWTH_ENDPOINT=https://growth.appwrite.io/v1

ENV VITE_GA_PROJECT=$VITE_GA_PROJECT \
    VITE_CONSOLE_MODE=$VITE_CONSOLE_MODE \
    VITE_APPWRITE_GROWTH_ENDPOINT=$VITE_APPWRITE_GROWTH_ENDPOINT

WORKDIR /usr/local/src/console
COPY app/console ./
RUN npm ci && npm run build

FROM appwrite/base:0.4.3 as final

LABEL maintainer="team@appwrite.io"

ARG VERSION=dev
ARG DEBUG=false

ENV DEBUG=$DEBUG \
    _APP_VERSION=$VERSION \
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

# Set Volumes
RUN mkdir -p /storage/uploads /storage/cache /storage/config /storage/certificates /storage/functions /storage/debug && \
    chown -Rf www-data:www-data /storage/uploads /storage/cache /storage/config /storage/certificates /storage/functions /storage/debug && \
    chmod -Rf 0755 /storage/uploads /storage/cache /storage/config /storage/certificates /storage/functions /storage/debug

# Executables
RUN chmod +x /usr/local/bin/doctor \
    /usr/local/bin/maintenance \
    /usr/local/bin/usage \
    /usr/local/bin/install \
    /usr/local/bin/upgrade \
    /usr/local/bin/migrate \
    /usr/local/bin/realtime \
    /usr/local/bin/schedule \
    /usr/local/bin/sdks \
    /usr/local/bin/specs \
    /usr/local/bin/ssl \
    /usr/local/bin/test \
    /usr/local/bin/vars \
    /usr/local/bin/worker-audits \
    /usr/local/bin/worker-certificates \
    /usr/local/bin/worker-databases \
    /usr/local/bin/worker-deletes \
    /usr/local/bin/worker-functions \
    /usr/local/bin/worker-builds \
    /usr/local/bin/worker-mails \
    /usr/local/bin/worker-messaging \
    /usr/local/bin/worker-webhooks \
    /usr/local/bin/worker-migrations

# Cloud Executabless
RUN chmod +x \
    /usr/local/bin/hamster \
    /usr/local/bin/volume-sync \
    /usr/local/bin/patch-delete-schedule-updated-at-attribute \
    /usr/local/bin/patch-delete-project-collections \
    /usr/local/bin/clear-card-cache \
    /usr/local/bin/calc-users-stats \
    /usr/local/bin/calc-tier-stats

# Letsencrypt Permissions
RUN mkdir -p /etc/letsencrypt/live/ && chmod -Rf 755 /etc/letsencrypt/live/

# Enable Extensions
RUN if [ "$DEBUG" == "true" ]; then printf "zend_extension=yasd \nyasd.debug_mode=remote \nyasd.init_file=/usr/src/code/dev/yasd_init.php \nyasd.remote_port=9005 \nyasd.log_level=-1" >> /usr/local/etc/php/conf.d/yasd.ini; fi

RUN if [ "$DEBUG" == "true" ]; then echo "opcache.enable=0" >> /usr/local/etc/php/conf.d/appwrite.ini; fi
RUN echo "opcache.preload_user=www-data" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.preload=/usr/src/code/app/preload.php" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "default_socket_timeout=-1" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.jit_buffer_size=100M" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.jit=1235" >> /usr/local/etc/php/conf.d/appwrite.ini

EXPOSE 80

CMD [ "php", "app/http.php", "-dopcache.preload=opcache.preload=/usr/src/code/app/preload.php" ]