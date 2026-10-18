# Local test image: PHP 8.3 + Apache serving public/, with dev dependencies
# so the PHPUnit suite can run inside the container too.
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libonig-dev unzip git \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring bcmath zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && printf '<Directory /var/www/html/public>\n\tAllowOverride All\n\tRequire all granted\n\tFallbackResource /index.php\n</Directory>\n' >> /etc/apache2/apache2.conf

# Dependencies first so code changes don't re-download them.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
