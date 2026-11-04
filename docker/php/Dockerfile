FROM php:8.4-fpm-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# GD decodifica y re-codifica las imágenes que suben las empresas (logos): ver app/Files/FileStore.php.
RUN apk add --no-cache icu-libs libzip libsodium libpng libjpeg-turbo libwebp \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libsodium-dev libpng-dev libjpeg-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip bcmath pcntl opcache sodium gd \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY php.ini /usr/local/etc/php/conf.d/zz-geckochat.ini

WORKDIR /var/www/html
