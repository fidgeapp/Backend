#!/bin/bash
set -e

# Fix MPM conflict
a2dismod mpm_event 2>/dev/null || true
a2dismod mpm_worker 2>/dev/null || true
a2enmod mpm_prefork

# Redirect logs
ln -sf /dev/stderr /var/log/apache2/error.log
ln -sf /dev/stdout /var/log/apache2/access.log

echo "Step 1: Clearing config (safe)..."
php artisan config:clear --no-interaction

echo "Step 2: Waiting for database (10s)..."
sleep 10

echo "Step 3: Running migrations..."
php artisan migrate --force --no-interaction

echo "Step 4: Seeding database..."
php artisan db:seed --force --no-interaction

# ✅ NOW cache is safe (DB exists)
echo "Step 5: Clearing cache..."
php artisan cache:clear --no-interaction

echo "Step 6: Optimizing..."
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction

echo "Step 7: Starting Apache..."
exec apache2-foreground