FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    git \
    unzip \
    curl \
    libpq-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo pdo_pgsql gd zip bcmath opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Clé factice uniquement pour le build (package:discover) — écrasée par Render au runtime
ENV APP_ENV=production \
    APP_KEY=base64:dGVtcG9yYXJ5LWtleS1mb3ItZG9ja2VyLWJ1aWxkLW9ubHk= \
    LOG_CHANNEL=stderr

COPY . .

RUN composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

COPY docker/php-uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh \
    && ln -sfn /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default \
    && sed -i 's/\r$//' /entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/entrypoint.sh"]
