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

mkdir -p dump/backup

TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
BACKUP_FILE="dump/backup/diagnostic_center_db_${TIMESTAMP}.sql"
MIRROR_DIR="dump/backup/sql_bundle_${TIMESTAMP}"

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

    mkdir -p "$MIRROR_DIR"
    if cp -R dump/init "$MIRROR_DIR/init"; then
        echo "SQL bundle mirrored: $MIRROR_DIR"
    else
        echo "WARNING: SQL bundle mirror failed."
    fi
else
    echo ""
    echo "ERROR: Backup failed. Is the database container running?"
    echo "Run: docker-compose ps"
fi
