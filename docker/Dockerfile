FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    bash \
    libxml2-dev \
    $PHPIZE_DEPS \
    mariadb-connector-c-dev

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY usr/local/etc/php/php.ini /usr/local/etc/php/php.ini
RUN docker-php-ext-install pdo pdo_mysql
RUN apk add --no-cache linux-headers
RUN pecl install xdebug && docker-php-ext-enable xdebug

WORKDIR /app
VOLUME /app

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
