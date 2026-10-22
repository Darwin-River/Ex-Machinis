#!/bin/sh
set -e
# Settings come from the environment (see docker-compose.yml), so cache them
# once at start-up. Storage is a volume, so make sure it is writable.
mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views
chown -R www-data:www-data storage
rm -f storage/framework/views/*.php
php artisan config:cache >/dev/null
exec docker-php-entrypoint "$@"
