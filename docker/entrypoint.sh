#!/bin/bash
set -e

cd /var/www/html

chown -R www-data:www-data storage/app/public

php artisan config:cache
php artisan route:cache || echo "WARNING: route:cache failed (likely a closure route) - continuing without it"
php artisan view:cache
php artisan storage:link || true
php artisan l5-swagger:generate || true

exec "$@"