# Imágenes de producción (el desarrollo usa docker/php/Dockerfile). El contexto de construcción es la raíz del proyecto.
# Objetivos: «prod» (aplicación: PHP-FPM, trabajadores, planificador y Reverb) y «web» (nginx con los archivos estáticos).
# Ver compose.production.yaml.

FROM php:8.4-fpm-alpine AS base

# GD decodifica y re-codifica las imágenes que suben las empresas (logos): ver app/Files/FileStore.php.
RUN apk add --no-cache icu-libs libzip libsodium libpng libjpeg-turbo libwebp \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libsodium-dev libpng-dev libjpeg-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip bcmath pcntl opcache sodium gd \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

WORKDIR /var/www/html


# Estilos y scripts del panel y del widget (el widget se publica como public/widget.js).
FROM node:24-alpine AS assets
WORKDIR /app
# La llave PÚBLICA de Reverb viaja dentro del script del panel (es lo único que el navegador necesita para conectarse); por
# eso la imagen se construye con ella. Cambiarla exige reconstruir la imagen.
ARG VITE_REVERB_APP_KEY
COPY package.json pnpm-lock.yaml ./
RUN corepack enable && pnpm install --frozen-lockfile
COPY tsconfig.json vite.config.ts vite.widget.config.ts tailwind.config.js ./
COPY resources ./resources
COPY public ./public
RUN pnpm build


# Dependencias de PHP sin las de desarrollo y con el cargador de clases optimizado.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts


FROM base AS prod
COPY docker/production/php.ini /usr/local/etc/php/conf.d/zz-geckochat.ini
COPY docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/zz-geckochat.conf
COPY docker/production/entrypoint.sh /usr/local/bin/gecko-entrypoint
RUN chmod 0555 /usr/local/bin/gecko-entrypoint
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build
COPY --from=assets --chown=www-data:www-data /app/public/widget.js /var/www/html/public/widget.js
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && rm -rf tests docker compose.yaml compose.production.yaml node_modules .git
USER www-data
ENTRYPOINT ["gecko-entrypoint"]
CMD ["php-fpm"]


# nginx lleva los archivos estáticos dentro de la imagen: en producción no comparte carpetas con el código.
FROM nginx:stable-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=prod /var/www/html/public /var/www/html/public
