FROM php:8.3-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev libonig-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev libzip-dev libcurl4-openssl-dev unzip git curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl mbstring gd pcntl curl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-interaction --prefer-dist --no-dev --no-scripts
COPY . .
RUN composer dump-autoload --optimize && mkdir -p runtime && chown -R www-data:www-data runtime
COPY config/php.ini /usr/local/etc/php/conf.d/shopkmp.ini
EXPOSE 9000
CMD ["php-fpm"]
