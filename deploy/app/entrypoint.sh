#!/bin/sh
set -e
# Settings come from the environment (see docker-compose.yml), so cache them
# once at start-up. Storage is a volume, so make sure it is writable.
mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views
chown -R www-data:www-data storage
rm -f storage/framework/views/*.php
php artisan config:cache >/dev/null
# House station drones (#380), when FORGE_STATION_DRONES is on: made where
# missing, in the live world and the test world
if [ "${FORGE_STATION_DRONES:-0}" = 1 ] || [ "${FORGE_STATION_DRONES:-}" = true ]; then
    php artisan forge:stations || echo "forge:stations failed" >&2
    [ "${TEST_WORLD:-0}" = 1 ] && { php artisan forge:stations --database=exmachinis_test || echo "forge:stations (test world) failed" >&2; }
fi
exec docker-php-entrypoint "$@"
