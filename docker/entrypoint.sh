#!/bin/bash
set -e

# Railway provides dynamic $PORT; default to 80 if unset
PORT="${PORT:-80}"

echo "[MedConnect] Starting Apache web service on port ${PORT}..."

# Update Apache configuration to bind to the dynamic port
sed -ri -e "s!^(\s*Listen)\s+[0-9]+!\1 ${PORT}!g" /etc/apache2/ports.conf
sed -ri -e "s!<VirtualHost \*:[0-9]+>!<VirtualHost \*:${PORT}>!g" /etc/apache2/sites-available/000-default.conf

# Ensure runtime directories exist with proper permissions for Apache (www-data)
mkdir -p /var/www/html/storage/logs \
         /var/www/html/storage/cache \
         /var/www/html/storage/temp \
         /var/www/html/storage/uploads/ids \
         /var/www/html/storage/uploads/profile_pictures \
         /var/www/html/storage/uploads/announcements \
         /var/www/html/storage/uploads/media_library \
         /var/www/html/storage/recordings \
         /var/www/html/storage/ratelimit \
         /var/www/html/storage/residency

chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

# Run Apache in foreground
exec "$@"
