#!/bin/bash

# Script to collect PrestaShop stock data daily
# Add this to crontab to run automatically

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR/infra" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting daily stock collection..."

# Run the collection command
docker-compose exec -T php bin/console app:collect-prestashop-data --all

if [ $? -eq 0 ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Stock collection completed successfully"
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: Stock collection failed"
    exit 1
fi
