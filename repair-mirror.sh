#!/bin/bash
# One-click mirror repair helper
# Usage: chmod +x repair-mirror.sh && ./repair-mirror.sh [--all] [--json]

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

if command -v php >/dev/null 2>&1; then
    php data_backup/repair_mirror_tables.php "$@"
else
    docker exec diagnostic-center-web php /var/www/html/data_backup/repair_mirror_tables.php "$@"
fi
