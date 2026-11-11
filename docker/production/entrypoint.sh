#!/bin/sh
set -eu

cd /app

: "${APP_KEY:?APP_KEY must be configured in the server environment}"
: "${DB_PASSWORD:?DB_PASSWORD must be configured in the server environment}"
: "${SERVER_NAME:?SERVER_NAME must contain the public hostname or IP}"

mkdir -p storage/framework/cache/data storage/framework/cache/locks \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# Cache only after the server has supplied its environment and persistent key.
php artisan config:cache --no-interaction
php artisan app:initialize --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

exec "$@"
