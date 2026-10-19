#!/bin/sh
set -e
cd /var/www/html

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Uniquement pour le conteneur principal (pas le scheduler)
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "Attente de MySQL et application des migrations..."
    i=0
    until php artisan migrate --force --no-interaction; do
        i=$((i + 1))
        [ "$i" -ge 30 ] && { echo "MySQL n'a pas accepté les migrations."; exit 1; }
        sleep 2
    done
fi

php artisan optimize

exec "$@"
