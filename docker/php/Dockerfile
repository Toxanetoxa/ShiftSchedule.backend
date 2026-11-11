FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql zip pcntl bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod +x /usr/local/bin/app-entrypoint

WORKDIR /var/www/html

EXPOSE 8000

ENTRYPOINT ["app-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
