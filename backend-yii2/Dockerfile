# Official multi-platform indexes; update deliberately with a runtime test + full scan.
FROM composer:2.11.0@sha256:278dfc7bd35169965d03f87194816f43bf4e6b6ea61707c2114e52ad12fe24ce AS composer-bin
FROM php:8.3.36-fpm-alpine3.24@sha256:25e15cf35ed0f5b75d8cf14fc2c4ed7e0e6bee00d359e681b7fa3e91a7bee49f
# The default image remains both the FPM runtime and the Composer/CLI test image.
# mbstring, sodium, openssl and curl are already supplied by the official base.
ARG PHP_EXTENSION_BUILD_JOBS=2
RUN set -eux; \
    # CVE-2026-92392: the pinned official base contains curl/libcurl 8.22.0-r0.
    # Use the supported Alpine 3.24 security fix (8.23.0-r0 or newer).
    apk upgrade --no-cache curl libcurl; \
    apk add --no-cache unzip; \
    apk add --no-cache --virtual .shopkmp-build-deps \
        $PHPIZE_DEPS pax-utils postgresql-dev icu-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"${PHP_EXTENSION_BUILD_JOBS}" pdo_pgsql intl gd pcntl; \
    docker-php-source delete; \
    # Official docker-library/php discovery: preserve dependencies of PHP, FPM
    # and every extension before removing the entire temporary toolchain.
    runDeps="$(scanelf --needed --nobanner --format '%n#p' --recursive /usr/local \
        | tr ',' '\n' | sort -u \
        | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }')"; \
    apk add --no-cache $runDeps; \
    apk del --no-network .shopkmp-build-deps; \
    rm -rf /usr/src/php* /usr/local/include/php /usr/local/lib/php/build; \
    rm -f /usr/local/bin/phpize /usr/local/bin/php-config /usr/local/bin/docker-php-ext-* \
        /usr/local/bin/docker-php-source; \
    php --version
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-interaction --prefer-dist --no-dev --no-scripts
COPY . .
RUN composer dump-autoload --optimize && mkdir -p runtime && chown -R www-data:www-data runtime
COPY config/php.ini /usr/local/etc/php/conf.d/shopkmp.ini
EXPOSE 9000
CMD ["php-fpm"]
