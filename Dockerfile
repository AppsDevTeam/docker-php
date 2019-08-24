FROM php:7.1-fpm-stretch

RUN docker-php-source extract \
&& apt-get update \
&& apt-get install -y libssh2-1-dev libssh2-1 && pecl install ssh2-alpha \
&& docker-php-source delete
