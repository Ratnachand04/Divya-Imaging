#!/bin/bash
# ============================================================
# Monthly Database Backup - Diagnostic Center
# ============================================================
# Saves SQL backup to: dump/backup/YEAR/MONTH/
# Usage: chmod +x monthly-backup.sh && ./monthly-backup.sh
# Supports: Direct MySQL, Docker exec, or PHP-based backup
# ============================================================

echo ""
echo "=== Monthly Database Backup ==="
echo ""

# Load env vars
if [ -f .env ]; then
    export $(grep -v '^#' .env | xargs)
fi

DB_HOST="${DB_HOST:-db}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root_password}"
DB_NAME="${DB_NAME:-diagnostic_center_db}"

YEAR=$(date +"%Y")
MONTH=$(date +"%m")
TIMESTAMP=$(date +"%Y-%m-%d_%H%M%S")

BACKUP_DIR="dump/backup/${YEAR}/${MONTH}"
BACKUP_FILE="${BACKUP_DIR}/backup_${TIMESTAMP}.sql"
MIRROR_DIR="${BACKUP_DIR}/sql_bundle_${TIMESTAMP}"

echo "Backup folder: ${BACKUP_DIR}"
echo "Backup file:   ${BACKUP_FILE}"

# Create directory structure
mkdir -p "${BACKUP_DIR}"

# Check if running inside Docker or from host
if command -v docker &>/dev/null && docker ps --filter "name=diagnostic-center-db" --format "{{.Names}}" | grep -q "diagnostic-center-db"; then
    echo "Using Docker container for mysqldump..."
    docker exec diagnostic-center-db mysqldump \
        --skip-ssl \
        --single-transaction \
        --routines \
        --triggers \
        -u root -p"${DB_PASS}" "${DB_NAME}" > "${BACKUP_FILE}"
elif command -v mysqldump &>/dev/null; then
    echo "Using local mysqldump..."
    mysqldump \
        --skip-ssl \
        --single-transaction \
        --routines \
        --triggers \
        -h "${DB_HOST}" \
        -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" > "${BACKUP_FILE}"
else
    echo "ERROR: No backup tool available (docker or mysqldump)"
    exit 1
fi

if [ $? -eq 0 ] && [ -s "${BACKUP_FILE}" ]; then
    FILE_SIZE=$(du -h "${BACKUP_FILE}" | cut -f1)
    echo ""
    echo "Backup completed successfully!"
    echo "File: ${BACKUP_FILE}"
    echo "Size: ${FILE_SIZE}"

    mkdir -p "${MIRROR_DIR}"
    if cp -R dump/init "${MIRROR_DIR}/init"; then
        echo "SQL bundle mirrored: ${MIRROR_DIR}"
    else
        echo "WARNING: SQL bundle mirror failed."
    fi
    
    echo "Stored in dump/backup automatically."
else
    echo ""
    echo "ERROR: Backup failed or produced empty file."
    [ -f "${BACKUP_FILE}" ] && rm -f "${BACKUP_FILE}"
    exit 1
fi
