#!/bin/sh
set -e

cd /var/www/html

sed "s/__PORT__/${PORT:-10000}/" /etc/nginx/app.conf.template > /etc/nginx/sites-enabled/app.conf

php artisan migrate --force
php artisan db:seed
php artisan optimize

chown -R www-data:www-data storage bootstrap/cache

exec supervisord -c /etc/supervisor/app.conf
