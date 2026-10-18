#!/bin/sh
set -e

# The container gets its own .env (the host's is excluded by .dockerignore);
# values set in compose.yaml take precedence over it.
if [ ! -f .env ]; then
    cp .env.example .env
fi
if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
fi

tries=0
until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "Database not reachable after 60s" >&2
        exit 1
    fi
    sleep 2
done

php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction
php artisan config:clear --no-interaction

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
