#!/bin/sh
set -e

# CONTAINER_ROLE=app (default) prepares the database; the scheduler and
# queue containers (CONTAINER_ROLE=worker) only wait until it's ready, so the
# three never run migrations at the same time.

wait_for_database() {
    tries=0
    until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "Database not reachable after 60s" >&2
            exit 1
        fi
        sleep 2
    done
}

# Every container gets the same .env (the host's is excluded by
# .dockerignore); values set in compose.yaml take precedence over it. The
# workers need it too: APP_NAME sets the cache prefix and the emails' sender.
if [ ! -f .env ]; then
    cp .env.example .env
fi

if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    if [ -z "$APP_KEY" ] && ! grep -q '^APP_KEY=base64:' .env; then
        php artisan key:generate --force --no-interaction
    fi

    wait_for_database
    php artisan migrate --force --no-interaction
    php artisan db:seed --force --no-interaction
    php artisan config:clear --no-interaction

    chown -R www-data:www-data storage bootstrap/cache
else
    wait_for_database
    # Until the app container has created the tables.
    until php artisan migrate:status --no-interaction >/dev/null 2>&1; do
        sleep 2
    done
fi

exec "$@"
