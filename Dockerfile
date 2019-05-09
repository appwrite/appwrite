FROM ubuntu:18.04
MAINTAINER Appwrite Team <team@appwrite.io>

ENV TZ=Asia/Tel_Aviv
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN \
  apt-get update --fix-missing && \
  apt-get install -y software-properties-common && \
  LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php && \
  apt-get update --fix-missing && \
  apt-get install -y htop supervisor openssl wget php7.2 php7.2-fpm php7.2-mysqlnd php7.2-curl php7.2-imagick php7.2-mbstring php7.2-dom php7.2-dev webp && \
  # nginx
  echo "deb http://nginx.org/packages/mainline/ubuntu/ wily nginx" >> /etc/apt/sources.list.d/nginx.list && \
  echo "deb-src http://nginx.org/packages/mainline/ubuntu/ wily nginx" >> /etc/apt/sources.list.d/nginx.list && \
  wget -q http://nginx.org/keys/nginx_signing.key && \
  apt-key add nginx_signing.key && \
  apt-get update --fix-missing && \
  apt-get install -y nginx && \
  # redis php extension
  wget -q https://github.com/phpredis/phpredis/archive/3.1.2.tar.gz && \
  tar -xf 3.1.2.tar.gz && \
  cd phpredis-3.1.2 && \
  phpize7.2 && \
  ./configure && \
  make && make install && \
  echo extension=redis.so >> /etc/php/7.2/fpm/conf.d/redis.ini && \
  echo extension=redis.so >> /etc/php/7.2/cli/conf.d/redis.ini && \
  # cleanup
  cd ../ && \
  rm -rf phpredis-3.1.2 && \
  rm -rf 3.1.2.tar.gz && \
  apt-get purge -y --auto-remove php7.2-dev && \
  apt-get purge -y --auto-remove software-properties-common && \
  apt-get clean && \
  rm -rf /var/lib/apt/lists/*

# Set upload limit
RUN echo "upload_max_filesize = 4M" > /etc/php/7.2/fpm/conf.d/appwrite.ini

# nginx conf (with ssl certificates)
ADD ./docker/nginx.conf /etc/nginx/nginx.conf
ADD ./docker/ssl/nginx.crt /etc/nginx/ssl/nginx.crt
ADD ./docker/ssl/nginx.key /etc/nginx/ssl/nginx.key

# php conf
ADD ./docker/www.conf /etc/php/7.2/fpm/pool.d/www.conf

# supervisord conf
ADD ./docker/supervisord.conf /etc/supervisord.conf
ADD ./docker/entrypoint.sh /entrypoint.sh
RUN chmod 775 /entrypoint.sh

# add PHP files
ADD ./app /usr/share/nginx/html/app
ADD ./public /usr/share/nginx/html/public
ADD ./src /usr/share/nginx/html/src
ADD ./vendor /usr/share/nginx/html/vendor

RUN mkdir /storage && chown -Rf www-data.www-data /storage && chmod -Rf 0755 /storage

EXPOSE 80

CMD ["/bin/bash", "/entrypoint.sh"]