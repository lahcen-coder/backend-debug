#!/bin/sh
set -e

cd /var/www/html

echo "==> Checking APP_KEY..."
if [ -z "$APP_KEY" ]; then
    echo "WARNING: APP_KEY is not set! Generating one for this boot (set it permanently in EasyPanel env vars)."
    php artisan key:generate --force
fi

echo "==> Waiting for database ($DB_HOST:${DB_PORT:-3306})..."
until nc -z "$DB_HOST" "${DB_PORT:-3306}" 2>/dev/null; do
    echo "    Database not ready, retrying in 3s..."
    sleep 3
done
echo "    Database ready."

echo "==> Waiting for Redis ($REDIS_HOST:${REDIS_PORT:-6379})..."
until nc -z "$REDIS_HOST" "${REDIS_PORT:-6379}" 2>/dev/null; do
    echo "    Redis not ready, retrying in 3s..."
    sleep 3
done
echo "    Redis ready."

echo "==> Running migrations..."
php artisan migrate --force --no-interaction

echo "==> Caching config, routes and views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Starting supervisord..."
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
