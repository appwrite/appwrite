FROM appwrite/base:0.11.3 AS final

LABEL maintainer="team@appwrite.io"

WORKDIR /usr/src/code

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN echo "opcache.enable_cli=1" >> $PHP_INI_DIR/php.ini

RUN echo "memory_limit=1024M" >> $PHP_INI_DIR/php.ini

# The test runner installs dependencies before building this fixture. Reuse
# that graph so linked sibling tests do not reinstall older published packages.
COPY ./vendor /usr/src/code/vendor

# Add Source Code
COPY ./tests /usr/src/code/tests
COPY ./src /usr/src/code/src
COPY ./phpunit.xml /usr/src/code/phpunit.xml
COPY ./phpstan.neon /usr/src/code/phpstan.neon

CMD [ "tail", "-f", "/dev/null" ]
