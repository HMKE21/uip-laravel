#!/usr/bin/env bash
# Run on the server AFTER uploading / pulling the code:   bash deploy.sh
# Rebuilds every cache from what is actually installed, as the web user (so file permissions stay right).
set -euo pipefail
cd "$(dirname "$0")"
WEB="${WEB_USER:-apache}"
run() { sudo -u "$WEB" env COMPOSER_HOME=/tmp/composer "$@"; }

# 1) caches that came with the upload (or were built on another machine) are never trusted
sudo rm -f bootstrap/cache/*.php
sudo chown -R "$WEB:$WEB" storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache

# 2) production dependencies only (no Pail / dev tools); this also runs package:discover
run composer install --no-dev --optimize-autoloader --no-interaction

# 3) migrations + caches
run php artisan migrate --force
run php artisan config:clear
run php artisan route:cache

# 4) pick up the new PHP files
sudo systemctl reload php-fpm
echo "Deploy done."
