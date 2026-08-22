# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: build frontend assets (Vite / Tailwind)
# ---------------------------------------------------------------------------
FROM node:20-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install

COPY resources ./resources
COPY vite.config.js ./
COPY public ./public

RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2: install PHP dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY . .

RUN composer dump-autoload --no-dev --optimize

# ---------------------------------------------------------------------------
# Stage 3: final runtime image (php-fpm + nginx + supervisord)
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

RUN apk add --no-cache \
        nginx \
        supervisor \
        bash \
        curl \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        icu-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        zip \
        gd \
        intl \
        bcmath \
        opcache \
    && apk del --no-cache libpng-dev libjpeg-turbo-dev freetype-dev icu-dev oniguruma-dev libzip-dev

WORKDIR /var/www/html

# App source + installed vendor from the composer stage
COPY --from=vendor /app ./
# Compiled frontend assets
COPY --from=frontend /app/public/build ./public/build

# Container-internal config
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]