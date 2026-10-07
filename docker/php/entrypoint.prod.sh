#!/bin/sh
# Starts the app or the queue in production: storage is a mounted volume, so its directories may not exist yet,
# and the config/route/view caches are built here (they depend on the .env the container got).
set -e
mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
php artisan optimize --quiet
exec docker-php-entrypoint "$@"
