#!/bin/sh
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Sur un volume Windows, chown peut échouer : le serveur démarre quand même.
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

php artisan storage:link --force >/dev/null 2>&1 || true

echo "Attente de MySQL et application des migrations..."
i=0
until php artisan migrate --force --no-interaction; do
    i=$((i + 1))
    if [ "$i" -ge 30 ]; then
        echo "MySQL n'a pas accepté les migrations."
        exit 1
    fi
    sleep 2
done

exec "$@"
