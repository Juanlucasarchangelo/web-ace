# syntax=docker/dockerfile:1
# Somente Docker: nenhum PHP, Composer ou Node do host é utilizado.

FROM node:22-alpine AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js postcss.config.js tailwind.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM php:8.3-fpm-bookworm AS php-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       ca-certificates curl git unzip libicu-dev libonig-dev libzip-dev libsqlite3-dev libxml2-dev \
    && docker-php-ext-install -j"$(nproc)" intl mbstring zip pdo_mysql pdo_sqlite bcmath opcache dom xml xmlwriter \
    && rm -rf /var/lib/apt/lists/*
COPY docker/php/ace.ini /usr/local/etc/php/conf.d/ace.ini
WORKDIR /var/www/html

# Executado APENAS na primeira inicialização se não houver composer.lock.
# Roda Composer em ambiente equivalente ao runtime, sem instalar PHP no Windows.
FROM php-base AS composer-tools
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /workspace
ENV COMPOSER_ALLOW_SUPERUSER=1

FROM composer-tools AS backend-build
WORKDIR /var/www/html
COPY . .
COPY --from=frontend /build/public/build /var/www/html/public/build
# A build NÃO resolve versões: usa exclusivamente composer.lock gerado e auditado.
RUN test -f composer.lock \
    && composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    && composer audit --locked --no-interaction \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

FROM php-base AS app
COPY --from=backend-build --chown=www-data:www-data /var/www/html /var/www/html
USER www-data
EXPOSE 9000
CMD ["php-fpm"]

FROM nginx:stable-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=backend-build /var/www/html/public /var/www/html/public
EXPOSE 80
