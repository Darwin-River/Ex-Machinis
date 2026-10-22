# The website: Laravel on PHP 8.3 + Apache, serving html/ as the web root.
# Build from the repository root: docker build -f deploy/app/Dockerfile .

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist \
        --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

FROM php:8.3-apache
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql zip opcache \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/app/apache.conf /etc/apache2/sites-available/000-default.conf

# The image's default DocumentRoot is /var/www/html, which is exactly this
# repo's web root once the repo is copied to /var/www.
WORKDIR /var/www
COPY --from=vendor /app /var/www
RUN php artisan package:discover \
 && mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views \
 && chown -R www-data:www-data storage bootstrap/cache

COPY deploy/app/entrypoint.sh /usr/local/bin/exm-entrypoint
ENTRYPOINT ["exm-entrypoint"]
CMD ["apache2-foreground"]
