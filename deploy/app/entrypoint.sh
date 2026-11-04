#!/bin/sh
set -e
# Settings come from the environment (see docker-compose.yml), so cache them
# once at start-up. Storage is a volume, so make sure it is writable.
mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views
chown -R www-data:www-data storage
rm -f storage/framework/views/*.php
php artisan config:cache >/dev/null
# House station drones (#380): the House trades through them (sell-to-station
# and buy-from-station need one where the drone is). Made where missing
php artisan forge:stations || echo "forge:stations failed" >&2
exec docker-php-entrypoint "$@"
