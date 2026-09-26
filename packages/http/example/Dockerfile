FROM composer:2.0 AS step0
WORKDIR /usr/local/src/
COPY composer.* /usr/local/src/
RUN composer install --ignore-platform-reqs --optimize-autoloader --no-plugins --no-scripts --prefer-dist

FROM appwrite/base:0.5.0 AS final
WORKDIR /usr/src/code
COPY ./src /usr/src/code/src
COPY --from=step0 /usr/local/src/vendor /usr/src/code/vendor

EXPOSE 80
CMD ["php", "src/server.php"]
