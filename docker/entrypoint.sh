#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Render fournit DATABASE_URL ; Laravel attend DB_URL
if [ -n "${DATABASE_URL:-}" ] && [ -z "${DB_URL:-}" ]; then
  export DB_URL="$DATABASE_URL"
fi

# PHP-FPM doit hériter des variables d'environnement (APP_KEY, DB, …)
sed -i 's/^;*clear_env\s*=.*/clear_env = no/' /usr/local/etc/php-fpm.d/www.conf || true
grep -q '^clear_env' /usr/local/etc/php-fpm.d/www.conf || echo 'clear_env = no' >> /usr/local/etc/php-fpm.d/www.conf

# Port injecté par Render (défaut 8000 en local)
PORT="${PORT:-8000}"
sed -i "s/listen 8000;/listen ${PORT};/" /etc/nginx/sites-available/default

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

php artisan config:cache
php artisan route:cache
php artisan view:cache || true
php artisan storage:link || true
php artisan migrate --force

php-fpm -D
exec nginx -g 'daemon off;'
