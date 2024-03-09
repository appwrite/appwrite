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

FROM appwrite/base:0.9.0 as final

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
    mkdir -p /storage/backups && \
    chown -Rf www-data.www-data /storage/uploads && chmod -Rf 0755 /storage/uploads && \
    chown -Rf www-data.www-data /storage/cache && chmod -Rf 0755 /storage/cache && \
    chown -Rf www-data.www-data /storage/config && chmod -Rf 0755 /storage/config && \
    chown -Rf www-data.www-data /storage/certificates && chmod -Rf 0755 /storage/certificates && \
    chown -Rf www-data.www-data /storage/functions && chmod -Rf 0755 /storage/functions && \
    chown -Rf www-data.www-data /storage/debug && chmod -Rf 0755 /storage/debug

# Development Executables
RUN chmod +x /usr/local/bin/dev-generate-translations

# Executables
RUN chmod +x /usr/local/bin/doctor && \
    chmod +x /usr/local/bin/install && \
    chmod +x /usr/local/bin/maintenance &&  \
    chmod +x /usr/local/bin/migrate && \
    chmod +x /usr/local/bin/realtime && \
    chmod +x /usr/local/bin/schedule-functions && \
    chmod +x /usr/local/bin/schedule-messages && \
    chmod +x /usr/local/bin/schedule-backups && \
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
    chmod +x /usr/local/bin/worker-hamster && \
    chmod +x /usr/local/bin/worker-mails && \
    chmod +x /usr/local/bin/worker-messaging && \
    chmod +x /usr/local/bin/worker-migrations && \
    chmod +x /usr/local/bin/worker-webhooks && \
    chmod +x /usr/local/bin/worker-hamster && \
    chmod +x /usr/local/bin/worker-usage && \
    chmod +x /usr/local/bin/worker-usage-dump


# Cloud Executabless
RUN chmod +x /usr/local/bin/calc-tier-stats && \
    chmod +x /usr/local/bin/calc-users-stats && \
    chmod +x /usr/local/bin/clear-card-cache && \
    chmod +x /usr/local/bin/delete-orphaned-projects && \
    chmod +x /usr/local/bin/get-migration-stats && \
    chmod +x /usr/local/bin/hamster && \
    chmod +x /usr/local/bin/patch-delete-project-collections && \
    chmod +x /usr/local/bin/patch-delete-schedule-updated-at-attribute && \
    chmod +x /usr/local/bin/patch-recreate-repositories-documents && \
    chmod +x /usr/local/bin/volume-sync && \
    chmod +x /usr/local/bin/patch-delete-project-collections && \
    chmod +x /usr/local/bin/delete-orphaned-projects && \
    chmod +x /usr/local/bin/clear-card-cache && \
    chmod +x /usr/local/bin/calc-users-stats && \
    chmod +x /usr/local/bin/calc-tier-stats && \
    chmod +x /usr/local/bin/get-migration-stats && \
    chmod +x /usr/local/bin/create-inf-metric

# Letsencrypt Permissions
RUN mkdir -p /etc/letsencrypt/live/ && chmod -Rf 755 /etc/letsencrypt/live/

# Enable Extensions
RUN if [ "$DEBUG" == "true" ]; then cp /usr/src/code/dev/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini; fi
RUN if [ "$DEBUG" = "false" ]; then rm -rf /usr/src/code/dev; fi
RUN if [ "$DEBUG" = "false" ]; then rm -f /usr/local/lib/php/extensions/no-debug-non-zts-20220829/xdebug.so; fi

EXPOSE 80

CMD [ "php", "app/http.php" ]