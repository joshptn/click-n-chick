#!/bin/bash
set -e

# ------------------------------
# Laravel container startup script
# ------------------------------

# Ensure SQLite database file exists
if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
    chmod 777 database/database.sqlite
fi


# Run Laravel commands
echo "Running storage link..."
php artisan storage:link || true   # skip if already exists

echo "Running migrations and seeding..."
php artisan migrate --seed





# Start Laravel development server
# Bind to 0.0.0.0 so it's accessible outside the container
echo "Starting Laravel server on port 8000..."
exec php artisan serve --host=0.0.0.0 --port=8000