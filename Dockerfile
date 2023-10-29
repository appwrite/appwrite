# Use the official Composer image as a build stage
FROM composer:2.0 as composer

# Define an argument and environment variable for testing
ARG TESTING=false
ENV TESTING=$TESTING

# Set the working directory in the build stage
WORKDIR /usr/local/src/

# Copy the Composer configuration files
COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

# Install dependencies with Composer, considering the testing argument
RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist \
    `if [ "$TESTING" != "true" ]; then echo "--no-dev"; fi`

# Use a Node.js image as a separate build stage
FROM --platform=$BUILDPLATFORM node:16.14.2-alpine3.15 as node

# Copy the application console to the image
COPY app/console /usr/local/src/console

# Set the working directory for the Node.js build
WORKDIR /usr/local/src/console

# Define arguments and environment variables for Vite settings
ARG VITE_GA_PROJECT
ARG VITE_CONSOLE_MODE
ARG VITE_APPWRITE_GROWTH_ENDPOINT=https://growth.appwrite.io/v1
ENV VITE_GA_PROJECT=$VITE_GA_PROJECT
ENV VITE_CONSOLE_MODE=$VITE_CONSOLE_MODE
ENV VITE_APPWRITE_GROWTH_ENDPOINT=$VITE_APPWRITE_GROWTH_ENDPOINT

# Install Node.js dependencies and build the application
RUN npm ci
RUN npm run build

# Use the Appwrite base image as the final stage
FROM appwrite/base:0.4.3 as final

# Set maintainer information in the image metadata
LABEL maintainer="team@appwrite.io"

# Define arguments and environment variables for application version and debug mode
ARG VERSION=dev
ARG DEBUG=false
ENV DEBUG=$DEBUG

ENV _APP_VERSION=$VERSION \
    _APP_HOME=https://appwrite.io

# Install Boost libraries if in debug mode
RUN \
  if [ "$DEBUG" == "true" ]; then \
    apk add boost boost-dev; \
  fi

# Set the working directory in the final stage
WORKDIR /usr/src/code

# Copy dependencies and built application from previous build stages
COPY --from=composer /usr/local/src/vendor /usr/src/code/vendor
COPY --from=node /usr/local/src/console/build /usr/src/code/console

# Add source code and set up volumes for storage
COPY ./app /usr/src/code/app
COPY ./public /usr/src/code/public
COPY ./bin /usr/local/bin
COPY ./docs /usr/src/code/docs
COPY ./src /usr/src/code/src

# Create directories and set permissions for storage volumes
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

# Set permissions for executables and Cloud Executables
RUN chmod +x /usr/local/bin/doctor && \
    chmod +x /usr/local/bin/maintenance &&  \
    chmod +x /usr/local/bin/usage && \
    chmod +x /usr/local/bin/install && \
    chmod +x /usr/local/bin/upgrade && \
    chmod +x /usr/local/bin/migrate && \
    chmod +x /usr/local/bin/realtime && \
    chmod +x /usr/local/bin/schedule && \
    chmod +x /usr/local/bin/sdks && \
    chmod +x /usr/local/bin/specs && \
    chmod +x /usr/local/bin/ssl && \
    chmod +x /usr/local/bin/test && \
    chmod +x /usr/local/bin/vars && \
    chmod +x /usr/local/bin/worker-audits && \
    chmod +x /usr/local/bin/worker-certificates && \
    chmod +x /usr/local/bin/worker-databases && \
    chmod +x /usr/local/bin/worker-deletes && \
    chmod +x /usr/local/bin/worker-functions && \
    chmod +x /usr/local/bin/worker-builds && \
    chmod +x /usr/local/bin/worker-mails && \
    chmod +x /usr/local/bin/worker-messaging && \
    chmod +x /usr/local/bin/worker-webhooks && \
    chmod +x /usr/local/bin/worker-migrations

# Set permissions for Cloud Executables
RUN chmod +x /usr/local/bin/hamster && \
    chmod +x /usr/local/bin/volume-sync && \
    chmod +x /usr/local/bin/patch-delete-schedule-updated-at-attribute && \
    chmod +x /usr/local/bin/patch-delete-project-collections && \
    chmod +x /usr/local/bin/clear-card-cache && \
    chmod +x /usr/local/bin/calc-users-stats && \
    chmod +x /usr/local/bin/calc-tier-stats

# Set permissions for Letsencrypt directories
RUN mkdir -p /etc/letsencrypt/live/ && chmod -Rf 755 /etc/letsencrypt/live/

# Enable or disable extensions and configure opcache
RUN if [ "$DEBUG" == "true" ]; then printf "zend_extension=yasd \nyasd.debug_mode=remote \nyasd.init_file=/usr/src/code/dev/yasd_init.php \nyasd.remote_port=9005 \nyasd.log_level=-1" >> /usr/local/etc/php/conf.d/yasd.ini; fi
RUN if [ "$DEBUG" == "true" ]; then echo "opcache.enable=0" >> /usr/local/etc/php/conf.d/appwrite.ini; fi
RUN echo "opcache.preload_user=www-data" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.preload=/usr/src/code/app/preload.php" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "default_socket_timeout=-1" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.jit_buffer_size=100M" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.jit=1235" >> /usr/local/etc/php/conf.d/appwrite.ini

# Expose port 80 for HTTP traffic
EXPOSE 80

# Set the command to run the application with opcache preloading
CMD [ "php", "app/http.php", "-dopcache.preload=opcache.preload=/usr/src/code/app/preload.php" ]
