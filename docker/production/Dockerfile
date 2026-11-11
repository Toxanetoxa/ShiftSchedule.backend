FROM dunglas/frankenphp:1-php8.5-bookworm AS base

RUN install-php-extensions pdo_pgsql zip pcntl bcmath mbstring \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /app

# Copy only application sources: local settings and runtime caches never enter the image.
COPY composer.json composer.lock artisan openapi.json ./
COPY app ./app
COPY bootstrap/app.php bootstrap/providers.php ./bootstrap/
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY docker/production/php.ini /usr/local/etc/php/conf.d/zz-production.ini

RUN mkdir -p bootstrap/cache storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/cache/locks \
        storage/framework/sessions storage/framework/views storage/logs \
        /data/caddy /config/caddy \
    && chown -R www-data:www-data bootstrap/cache storage /data /config \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp

FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    && composer check-platform-reqs --no-dev

FROM base AS test

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY tests ./tests
COPY phpunit.xml .env.example ./
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=testing \
    APP_DEBUG=true
RUN composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    && cp .env.example .env \
    && sed -i 's|^APP_KEY=.*|APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=|' .env
ENTRYPOINT []
CMD ["php", "artisan", "test", "--compact"]

FROM base AS production

COPY --from=vendor /app/vendor ./vendor
COPY --from=vendor /app/bootstrap/cache ./bootstrap/cache
COPY docker/production/Caddyfile /etc/caddy/Caddyfile
COPY --chmod=755 docker/production/entrypoint.sh /usr/local/bin/app-production-entrypoint

RUN chown -R www-data:www-data bootstrap/cache storage /data /config

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning

USER www-data
EXPOSE 80 443 443/udp 8080
ENTRYPOINT ["app-production-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
