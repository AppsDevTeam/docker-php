FROM php:7.1-fpm-stretch

RUN docker-php-source extract \
&& apt-get update \
&& apt-get install -y git \
&& apt-get install -y libssh2-1-dev libssh2-1 && pecl install ssh2-alpha && docker-php-ext-enable ssh2 \
&& apt-get install -y zip && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
&& docker-php-source delete
