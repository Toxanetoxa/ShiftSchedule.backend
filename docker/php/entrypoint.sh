#!/bin/sh
set -eu

cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
fi

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# Reconcile the persistent volume with composer.lock after every checkout.
composer install --no-interaction --prefer-dist --no-progress

php artisan config:clear --no-interaction

# Keep an existing key, including a custom key supplied through the environment.
if php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit(empty(config("app.key")) ? 0 : 1);'; then
    php artisan key:generate --force --no-interaction
fi

php artisan app:initialize --no-interaction

exec "$@"
