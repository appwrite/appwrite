# The PHP compat driver's image with the Memcached extension, which the
# Memcached and Hazelcast adapters need and the dev image does not ship.
# Built by tests/compat/cache/services.sh; 3.4 is the first release that
# builds against PHP 8.5. BASE is the driver image to extend (CI passes its
# own through COMPAT_BASE_IMAGE).
ARG BASE=appwrite-dev
FROM ${BASE}
USER root
RUN apk add --no-cache --virtual .build $PHPIZE_DEPS libmemcached-dev zlib-dev cyrus-sasl-dev \
    && apk add --no-cache libmemcached-libs \
    && printf "\n\n\n\n\n\n\n\n\n\n" | pecl install memcached-3.4.0 \
    && docker-php-ext-enable memcached \
    && apk del .build
