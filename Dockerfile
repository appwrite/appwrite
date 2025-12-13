FROM composer:2.0 AS composer

ARG TESTING=false
ENV TESTING=$TESTING

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist \
    `if [ "$TESTING" != "true" ]; then echo "--no-dev"; fi`

FROM appwrite/base:0.10.6 AS base

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

# Add Source Code
COPY ./app /usr/src/code/app
COPY ./public /usr/src/code/public
COPY ./bin /usr/local/bin
COPY ./src /usr/src/code/src

# Set Volumes
RUN mkdir -p /storage/uploads && \
    mkdir -p /storage/imports && \
    mkdir -p /storage/cache && \
    mkdir -p /storage/config && \
    mkdir -p /storage/certificates && \
    mkdir -p /storage/functions && \
    mkdir -p /storage/debug && \
    chown -Rf www-data:www-data /storage/uploads && chmod -Rf 0755 /storage/uploads && \
    chown -Rf www-data:www-data /storage/imports && chmod -Rf 0755 /storage/imports && \
    chown -Rf www-data:www-data /storage/cache && chmod -Rf 0755 /storage/cache && \
    chown -Rf www-data:www-data /storage/config && chmod -Rf 0755 /storage/config && \
    chown -Rf www-data:www-data /storage/certificates && chmod -Rf 0755 /storage/certificates && \
    chown -Rf www-data:www-data /storage/functions && chmod -Rf 0755 /storage/functions && \
    chown -Rf www-data:www-data /storage/debug && chmod -Rf 0755 /storage/debug

# Executables
RUN chmod +x /usr/local/bin/doctor && \
    chmod +x /usr/local/bin/install && \
    chmod +x /usr/local/bin/maintenance &&  \
    chmod +x /usr/local/bin/migrate && \
    chmod +x /usr/local/bin/realtime && \
    chmod +x /usr/local/bin/schedule-functions && \
    chmod +x /usr/local/bin/schedule-executions && \
    chmod +x /usr/local/bin/schedule-messages && \
    chmod +x /usr/local/bin/sdks && \
    chmod +x /usr/local/bin/specs && \
    chmod +x /usr/local/bin/ssl && \
    chmod +x /usr/local/bin/screenshot && \
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
    chmod +x /usr/local/bin/worker-stats-usage && \
    chmod +x /usr/local/bin/stats-resources && \
    chmod +x /usr/local/bin/worker-stats-resources

RUN mkdir -p /etc/letsencrypt/live/ && chmod -Rf 755 /etc/letsencrypt/live/

FROM base AS production

RUN rm -rf /usr/src/code/app/config/specs && \
    rm -f /usr/local/lib/php/extensions/no-debug-non-zts-20240924/xdebug.so && \
    find /usr -name '*.a' -delete 2>/dev/null || true && \
    find /usr -type d -name '__pycache__' -exec rm -rf {} + 2>/dev/null || true && \
    find /usr -name '*.pyc' -delete 2>/dev/null || true

EXPOSE 80

CMD [ "php", "app/http.php" ]

FROM base AS development

COPY ./docs /usr/src/code/docs
COPY ./dev /usr/src/code/dev

RUN if [ "$DEBUG" = "true" ]; then \
    cp /usr/src/code/dev/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini && \
    mkdir -p /tmp/xdebug && \
    apk add --update --no-cache openssh-client github-cli; \
    fi

EXPOSE 80

CMD [ "php", "app/http.php" ]
