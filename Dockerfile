# PHP 8.3 + Apache serving public/.
#   compose.yaml (local test stack): INSTALL_DEV=true, so PHPUnit runs in the container.
#   compose.production.yaml:         INSTALL_DEV=false, no test tools in the image.
FROM php:8.3-apache

ARG INSTALL_DEV=true

RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libonig-dev unzip git \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring bcmath zip opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# OPcache: compiled PHP stays in memory between requests. Code only changes
# with a new image, so there's no need to re-check files on every request.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
    && echo 'expose_php=Off' > /usr/local/etc/php/conf.d/security.ini \
    && sed -ri -e 's/^ServerTokens .*/ServerTokens Prod/' -e 's/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && printf '<Directory /var/www/html/public>\n\tAllowOverride All\n\tRequire all granted\n\tFallbackResource /index.php\n</Directory>\n' >> /etc/apache2/apache2.conf

# Dependencies first so code changes don't re-download them.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --no-scripts --no-autoloader $([ "$INSTALL_DEV" = "true" ] || echo --no-dev)

COPY . .
RUN composer dump-autoload --optimize $([ "$INSTALL_DEV" = "true" ] || echo --no-dev) \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
