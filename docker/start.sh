#!/bin/sh
set -e

cd /var/www/html

echo "==> Checking APP_KEY..."
if [ -z "$APP_KEY" ]; then
    echo "WARNING: APP_KEY is not set! Generating one for this boot (set it permanently in EasyPanel env vars)."
    php artisan key:generate --force
fi

echo "==> Waiting for database ($DB_HOST:${DB_PORT:-3306})..."
DB_TRIES=0
until nc -z "$DB_HOST" "${DB_PORT:-3306}" 2>/dev/null; do
    DB_TRIES=$((DB_TRIES + 1))
    if [ "$DB_TRIES" -ge 30 ]; then
        echo "ERROR: Database not reachable after 90s. Check DB_HOST=$DB_HOST"
        exit 1
    fi
    echo "    Database not ready, retrying in 3s... ($DB_TRIES/30)"
    sleep 3
done
echo "    Database ready."

echo "==> Waiting for Redis ($REDIS_HOST:${REDIS_PORT:-6379})..."
REDIS_TRIES=0
until nc -z "$REDIS_HOST" "${REDIS_PORT:-6379}" 2>/dev/null; do
    REDIS_TRIES=$((REDIS_TRIES + 1))
    if [ "$REDIS_TRIES" -ge 20 ]; then
        echo "ERROR: Redis not reachable after 60s. Check REDIS_HOST=$REDIS_HOST"
        exit 1
    fi
    echo "    Redis not ready, retrying in 3s... ($REDIS_TRIES/20)"
    sleep 3
done
echo "    Redis ready."

echo "==> Running migrations..."
php artisan migrate --force --no-interaction

echo "==> Caching config, routes and views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache || echo "    (view:cache skipped — no Blade views)"

echo "==> Diagnosing horizon (informational only, will not block startup)..."
timeout 5 php artisan horizon:status 2>&1 || \
    timeout 5 php artisan about 2>&1 | grep -i "horizon\|queue\|redis" || true

echo "==> Starting supervisord (php-fpm + nginx only)..."
echo "    NOTE: Run horizon as a separate EasyPanel service."
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
