# PHP 8.5-FPM application image for the Laravel 13 backend.
# Used by both the `app` (php-fpm) and `queue` (php artisan queue:work) services.
FROM php:8.5-fpm

# System packages needed to build the PHP extensions below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
    && rm -rf /var/lib/apt/lists/*

# Core extensions Laravel 13 requires, plus intl (used by several Laravel
# validation/formatting helpers) and zip (Composer package installs).
RUN docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_mysql \
        mbstring \
        bcmath \
        intl \
        xml \
        zip \
        pcntl

# PhpRedis (preferred Redis client per architecture decision, over Predis).
RUN pecl install redis && docker-php-ext-enable redis

# Composer 2.x, copied from the official Composer image rather than installed
# via a shell script — smaller, pinned, and avoids an extra curl+php bootstrap.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
# Strip any CRLF line endings (script is authored on a Windows host) and
# make it executable — a plain `chmod +x` alone doesn't fix a bad shebang.
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
