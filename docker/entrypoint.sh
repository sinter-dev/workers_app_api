#!/bin/bash
set -e

cd /var/www/html

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link || true
php artisan l5-swagger:generate || true

exec "$@"