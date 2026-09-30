#!/bin/sh
set -e

composer install --no-interaction

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "Running pending migrations..."
    php artisan migrate --force --no-interaction
else
    echo "Skipping migrations (RUN_MIGRATIONS is disabled)."
fi

if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    echo "Running database seeders..."
    php artisan db:seed --force --no-interaction
else
    echo "Skipping seeders (RUN_SEEDERS is disabled)."
fi

exec "$@"
