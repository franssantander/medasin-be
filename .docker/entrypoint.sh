#!/bin/sh
set -e

composer install

if [ "$RUN_MIGRATIONS" = "true" ]; then
    if [ "$AUTO_FRESH_SEED" = "true" ]; then
        echo "Running migrate:fresh --seed..."
        php artisan migrate:fresh --seed
    else
        echo "Running migrate..."
        php artisan migrate
    fi
fi

exec "$@"
