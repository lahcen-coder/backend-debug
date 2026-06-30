# ── Stage 1: Composer Dependencies ───────────────────────────────────────────
FROM composer:2.7 AS vendor

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install \
    --no-interaction \
    --no-scripts \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-reqs

# ── Stage 2: Production Runtime ───────────────────────────────────────────────
FROM php:8.3-fpm-alpine AS production

LABEL maintainer="Debug Together <team@debugtogether.app>"
LABEL org.opencontainers.image.description="Debug Together — Laravel 11 API"

# System dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    libzip-dev \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    icu-dev \
    shadow

# PHP extensions
RUN docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        zip \
        pcntl \
        bcmath \
        opcache \
        intl \
    && pecl install redis \
    && docker-php-ext-enable redis

# Copy runtime configuration
COPY docker/php.ini        /usr/local/etc/php/conf.d/app.ini
COPY docker/nginx.conf     /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Supervisor log dir
RUN mkdir -p /var/log/supervisor

WORKDIR /var/www/html

# Bring in vendor from Stage 1
COPY --from=vendor /app/vendor ./vendor

# Copy application source (chown to www-data)
COPY --chown=www-data:www-data . .

# Bootstrap application (runs safely even without APP_KEY at build time)
RUN mkdir -p storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && php artisan package:discover --ansi 2>/dev/null || true \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=5 \
    CMD curl -sf http://localhost/api/v1/ping || exit 1

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
