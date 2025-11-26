#!/bin/bash
set -e

echo "Starting PrestaBoost PHP Container..."

# Start cron in the background
echo "Starting cron daemon..."
cron

# Log cron status
echo "Cron daemon started"

# Create log file if it doesn't exist
touch /var/log/cron.log
chmod 666 /var/log/cron.log

# Create messenger log directory
mkdir -p /var/www/html/var/log
chmod 777 /var/www/html/var/log

# Start Messenger workers in background with auto-restart
echo "Starting Messenger workers..."
(
  while true; do
    php /var/www/html/bin/console messenger:consume async --time-limit=14400 --memory-limit=512M >> /var/www/html/var/log/messenger_async.log 2>&1
    echo "[$(date)] Messenger async worker stopped, restarting..." >> /var/www/html/var/log/messenger_async.log
    sleep 2
  done
) &

(
  while true; do
    php /var/www/html/bin/console messenger:consume scheduler --time-limit=3600 --memory-limit=256M >> /var/www/html/var/log/messenger_scheduler.log 2>&1
    echo "[$(date)] Messenger scheduler worker stopped, restarting..." >> /var/www/html/var/log/messenger_scheduler.log
    sleep 2
  done
) &

echo "Messenger workers started"

# Execute the main command (php-fpm)
echo "Starting PHP-FPM..."
exec "$@"
