#!/bin/bash
# ============================================================
# Database Backup Script - Diagnostic Center
# ============================================================
# Creates a timestamped SQL backup of the database
# Usage: chmod +x backup-db.sh && ./backup-db.sh
# ============================================================

echo ""
echo "=== Database Backup ==="
echo ""

mkdir -p backups

TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
BACKUP_FILE="backups/diagnostic_center_db_${TIMESTAMP}.sql"

# Load env vars
if [ -f .env ]; then
    export $(grep -v '^#' .env | xargs)
fi

DB_PASS=${DB_PASS:-root_password}
DB_NAME=${DB_NAME:-diagnostic_center_db}

echo "Backing up database to: $BACKUP_FILE"

docker exec diagnostic-center-db mysqldump --skip-ssl -u root -p"$DB_PASS" "$DB_NAME" > "$BACKUP_FILE"

if [ $? -eq 0 ]; then
    echo ""
    echo "Backup completed: $BACKUP_FILE"
    echo "Size: $(du -h "$BACKUP_FILE" | cut -f1)"
else
    echo ""
    echo "ERROR: Backup failed. Is the database container running?"
    echo "Run: docker-compose ps"
fi
