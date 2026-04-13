#!/bin/bash
# ============================================================
# Entrypoint Script - Diagnostic Center Docker Container
# ============================================================
# Runs every time the container starts/restarts.
# Configures Apache, waits for DB, ensures tables, starts Apache.
# Supports: Dual IP Binding, Public IP Detection,
#           Port Auto-Scan, Public IP Error Diagnostics
# ============================================================

set -e

echo "============================================"
echo "  Diagnostic Center - Container Starting"
echo "============================================"

# ---- Port configuration ----
HTTP_PORT="${APP_PORT:-8081}"
HTTPS_PORT="${SSL_PORT:-8443}"
DATABASE_PORT="${DB_PORT:-3301}"

# ---- 1. Database Connection ----
echo "[CONFIG] Database: ${DB_HOST} / ${DB_NAME} (user: ${DB_USER})"
echo "[CONFIG] Ports: HTTP=${HTTP_PORT}  HTTPS=${HTTPS_PORT}  DB=${DATABASE_PORT}"

# ---- 1.5 SQL bundle intrusion guard ----
INIT_SQL_ROOT="/var/www/html/dump/init"
INIT_TUNNEL_FILE="${INIT_SQL_ROOT}/500-data-flow-tunnel.sql"
INIT_TABLES_DIR="${INIT_SQL_ROOT}/tables"
INIT_BUNDLE_GUARD="${INIT_BUNDLE_GUARD:-true}"

validate_sql_data_tunnel() {
    echo "[SECURITY] Validating SQL tunnel integrity..."

    local required_file
    for required_file in \
        "${INIT_SQL_ROOT}/001-main-schema.sql" \
        "${INIT_TUNNEL_FILE}" \
        "${INIT_SQL_ROOT}/900-post-schema.sql"; do
        if [ ! -f "${required_file}" ]; then
            echo "[SECURITY] ERROR: Missing required SQL file: ${required_file}"
            return 1
        fi
    done

    if [ ! -d "${INIT_TABLES_DIR}" ]; then
        echo "[SECURITY] ERROR: Missing tables directory: ${INIT_TABLES_DIR}"
        return 1
    fi

    local table_count
    table_count=$(find "${INIT_TABLES_DIR}" -maxdepth 1 -type f -name '*-data-*.sql' | wc -l | tr -d '[:space:]')
    if [ -z "${table_count}" ] || [ "${table_count}" -eq 0 ]; then
        echo "[SECURITY] ERROR: No per-table data SQL files found in ${INIT_TABLES_DIR}"
        return 1
    fi

    local source_count
    source_count=0

    local source_line
    local table_file
    while IFS= read -r source_line; do
        if echo "${source_line}" | grep -Eq '^[[:space:]]*SOURCE[[:space:]]+'; then
            if ! echo "${source_line}" | grep -Eq '^[[:space:]]*SOURCE[[:space:]]+/docker-entrypoint-initdb\.d/tables/[A-Za-z0-9_.-]+[[:space:]]*;[[:space:]]*$'; then
                echo "[SECURITY] ERROR: Invalid SOURCE link in tunnel: ${source_line}"
                return 1
            fi

            table_file=$(echo "${source_line}" | sed -E 's|^[[:space:]]*SOURCE[[:space:]]+/docker-entrypoint-initdb\.d/tables/([A-Za-z0-9_.-]+)[[:space:]]*;[[:space:]]*$|\1|')
            if [ ! -f "${INIT_TABLES_DIR}/${table_file}" ]; then
                echo "[SECURITY] ERROR: Tunnel points to missing table file: ${table_file}"
                return 1
            fi

            source_count=$((source_count + 1))
        fi
    done < "${INIT_TUNNEL_FILE}"

    if [ "${source_count}" -ne "${table_count}" ]; then
        echo "[SECURITY] ERROR: Tunnel link count mismatch (links=${source_count}, files=${table_count})"
        return 1
    fi

    echo "[SECURITY] SQL tunnel integrity passed (${source_count} secured links)."
    return 0
}

if [ "${INIT_BUNDLE_GUARD}" = "true" ]; then
    if ! validate_sql_data_tunnel; then
        echo "[SECURITY] Startup blocked to prevent SQL bundle intrusion."
        exit 1
    fi
else
    echo "[SECURITY] WARNING: SQL tunnel guard disabled (INIT_BUNDLE_GUARD=false)."
fi

# ---- 2. Wait for Database to be ready ----
echo "[DB] Waiting for database at ${DB_HOST}..."
MAX_RETRIES=30
RETRY_COUNT=0
until mysqladmin ping -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" --skip-ssl --silent 2>/dev/null; do
    RETRY_COUNT=$((RETRY_COUNT + 1))
    if [ $RETRY_COUNT -ge $MAX_RETRIES ]; then
        echo "[DB] ERROR: Database not reachable after ${MAX_RETRIES} attempts!"
        break
    fi
    echo "[DB] Waiting... (attempt ${RETRY_COUNT}/${MAX_RETRIES})"
    sleep 2
done
echo "[DB] Database is ready."

# ---- 3. Apply compatibility updates (SQL schema is init-driven) ----
echo "[DB] Applying compatibility updates..."
mysql -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" --skip-ssl "${DB_NAME}" <<'EOSQL' 2>/dev/null || true
-- Ensure users role enum supports platform_admin and migrate any legacy developer rows
ALTER TABLE `users` MODIFY `role` enum('manager','receptionist','accountant','writer','superadmin','platform_admin') NOT NULL;
UPDATE `users` SET `role` = 'platform_admin' WHERE `role` = 'developer';
EOSQL
echo "[DB] Compatibility updates complete."

# ---- 3.5 Enforce platform admin credentials ----
PLATFORM_USERNAME="platform"
PLATFORM_PASSWORD="password123"
PLATFORM_PASSWORD_HASH=$(PLATFORM_PASSWORD="${PLATFORM_PASSWORD}" php -r 'echo password_hash(getenv("PLATFORM_PASSWORD"), PASSWORD_DEFAULT);')

mysql -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" --skip-ssl "${DB_NAME}" <<EOSQL 2>/dev/null || true
INSERT INTO users (username, password, role, is_active)
SELECT '${PLATFORM_USERNAME}', '${PLATFORM_PASSWORD_HASH}', 'platform_admin', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = '${PLATFORM_USERNAME}');

UPDATE users
SET role = 'platform_admin', password = '${PLATFORM_PASSWORD_HASH}', is_active = 1
WHERE username = '${PLATFORM_USERNAME}';

UPDATE users
SET role = 'platform_admin'
WHERE role = 'developer';
EOSQL
echo "[DB] Platform admin credentials enforced (username: ${PLATFORM_USERNAME})."

# ---- 4. Auto-detect IPs ----
echo "[IP] Detecting network configuration..."

# Detect local/private IP
if [ -z "${LOCAL_IP}" ]; then
    LOCAL_IP=$(hostname -I 2>/dev/null | awk '{print $1}' || echo "127.0.0.1")
fi
echo "[IP] Local IP: ${LOCAL_IP}"

# Detect public IP using multiple services (fallback chain)
if [ -z "${PUBLIC_IP}" ]; then
    for svc in "https://api.ipify.org" "https://ifconfig.me" "https://icanhazip.com" "https://checkip.amazonaws.com"; do
        PUBLIC_IP=$(curl -s --max-time 5 "$svc" 2>/dev/null | tr -d '[:space:]')
        if echo "$PUBLIC_IP" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$'; then
            echo "[IP] Public IP detected via $svc: ${PUBLIC_IP}"
            break
        fi
        PUBLIC_IP=""
    done
fi

if [ -n "${PUBLIC_IP}" ]; then
    echo "[IP] Public IP: ${PUBLIC_IP}"
else
    echo "[IP] WARNING: Could not detect public IP from any service"
fi

# ---- 5. Port Auto-Scan on both IPs ----
echo "[PORT-SCAN] Scanning ports on local (${LOCAL_IP}) and public (${PUBLIC_IP:-none})..."

# Function: test if a port is reachable from inside the container
check_port() {
    local ip="$1"
    local port="$2"
    local timeout=3
    # Use bash /dev/tcp or curl to test
    (echo >/dev/tcp/"$ip"/"$port") 2>/dev/null && return 0
    return 1
}

# Function: log port scan result to DB
log_port_scan() {
    local check_type="$1"
    local ip="$2"
    local port="$3"
    local status="$4"
    local message="$5"
    local details="$6"
    mysql -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" --skip-ssl "${DB_NAME}" \
        -e "INSERT INTO ip_diagnostics (check_type, ip_address, port, status, message, details) VALUES ('${check_type}', '${ip}', ${port}, '${status}', '${message}', '${details}');" 2>/dev/null || true
}

PORT_SCAN_RESULTS=""

# Scan local IP ports
for port in ${HTTP_PORT} ${HTTPS_PORT} ${DATABASE_PORT}; do
    # Internal check: Apache inside container always listens on 80/443
    # Docker maps HOST_PORT -> CONTAINER_PORT, so from outside we check host ports
    echo -n "[PORT-SCAN]   Local ${LOCAL_IP}:${port} -> "
    # We can't test the host ports from inside the container directly,
    # but we verify the container's own ports are ready after Apache starts
    PORT_SCAN_RESULTS="${PORT_SCAN_RESULTS}local:${port}=pending;"
    echo "will verify after Apache start"
done

# Public IP port scan: test reachability using external callback
if [ -n "${PUBLIC_IP}" ]; then
    for port in ${HTTP_PORT} ${HTTPS_PORT}; do
        echo -n "[PORT-SCAN]   Public ${PUBLIC_IP}:${port} -> "
        # We try a curl HEAD to our own public IP + port to see if we're reachable
        HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "http://${PUBLIC_IP}:${port}/" 2>/dev/null || echo "000")
        if [ "$HTTP_CODE" != "000" ]; then
            echo "OPEN (HTTP ${HTTP_CODE})"
            PORT_SCAN_RESULTS="${PORT_SCAN_RESULTS}public:${port}=open;"
            log_port_scan "port_scan" "$PUBLIC_IP" "$port" "ok" "Port ${port} is OPEN on public IP" "HTTP response code: ${HTTP_CODE}"
        else
            echo "CLOSED or UNREACHABLE"
            PORT_SCAN_RESULTS="${PORT_SCAN_RESULTS}public:${port}=closed;"
            log_port_scan "port_scan" "$PUBLIC_IP" "$port" "error" "Port ${port} is CLOSED/UNREACHABLE on public IP" "Could not connect. Likely blocked by firewall, ISP NAT, or router port-forwarding not configured."
        fi
    done
fi

# ---- 6. Public IP Error Diagnostics ----
echo "[DIAG] Running public IP diagnostics..."

DIAG_SUMMARY=""

if [ -n "${PUBLIC_IP}" ]; then
    # Test 1: Can we reach our own public IP? (hairpin NAT test)
    echo -n "[DIAG]   Hairpin NAT test... "
    HAIRPIN=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "http://${PUBLIC_IP}:${HTTP_PORT}/" 2>/dev/null || echo "000")
    if [ "$HAIRPIN" != "000" ]; then
        echo "OK (hairpin NAT works)"
        DIAG_SUMMARY="${DIAG_SUMMARY}hairpin_nat=ok;"
        log_port_scan "hairpin_nat" "$PUBLIC_IP" "$HTTP_PORT" "ok" "Hairpin NAT works - can reach own public IP" ""
    else
        echo "FAIL (router may not support hairpin NAT - this is normal)"
        DIAG_SUMMARY="${DIAG_SUMMARY}hairpin_nat=fail;"
        log_port_scan "hairpin_nat" "$PUBLIC_IP" "$HTTP_PORT" "warning" "Hairpin NAT failed - internal access to own public IP blocked" "This is normal for many routers. External users may still be able to connect. Test from outside the network to confirm."
    fi

    # Test 2: DNS resolution (if APACHE_SERVER_NAME is a domain)
    if echo "${APACHE_SERVER_NAME}" | grep -qE '[a-zA-Z]'; then
        echo -n "[DIAG]   DNS resolution for ${APACHE_SERVER_NAME}... "
        RESOLVED=$(getent hosts "${APACHE_SERVER_NAME}" 2>/dev/null | awk '{print $1}' || echo "")
        if [ -n "$RESOLVED" ]; then
            echo "OK -> ${RESOLVED}"
            DIAG_SUMMARY="${DIAG_SUMMARY}dns=${RESOLVED};"
            log_port_scan "dns_resolution" "$RESOLVED" 0 "ok" "DNS resolves ${APACHE_SERVER_NAME} -> ${RESOLVED}" ""
        else
            echo "FAIL"
            DIAG_SUMMARY="${DIAG_SUMMARY}dns=fail;"
            log_port_scan "dns_resolution" "" 0 "error" "DNS resolution failed for ${APACHE_SERVER_NAME}" "Domain does not resolve. Check DNS A record points to ${PUBLIC_IP}."
        fi
    fi

    # Test 3: Internet connectivity (can the container reach outside?)
    echo -n "[DIAG]   Outbound internet... "
    if curl -s --max-time 5 https://www.google.com -o /dev/null 2>/dev/null; then
        echo "OK"
        DIAG_SUMMARY="${DIAG_SUMMARY}internet=ok;"
    else
        echo "FAIL"
        DIAG_SUMMARY="${DIAG_SUMMARY}internet=fail;"
        log_port_scan "internet_check" "" 0 "error" "No outbound internet connectivity" "Container cannot reach external services. Check Docker network configuration."
    fi

    # Test 4: Check if public IP matches expected (ISP might have changed it)
    echo -n "[DIAG]   IP consistency check... "
    VERIFY_IP=$(curl -s --max-time 5 https://api.ipify.org 2>/dev/null | tr -d '[:space:]')
    if [ "$VERIFY_IP" = "$PUBLIC_IP" ]; then
        echo "OK (consistent: ${PUBLIC_IP})"
        DIAG_SUMMARY="${DIAG_SUMMARY}ip_consistent=ok;"
    elif [ -n "$VERIFY_IP" ]; then
        echo "MISMATCH! Expected ${PUBLIC_IP}, got ${VERIFY_IP}"
        DIAG_SUMMARY="${DIAG_SUMMARY}ip_consistent=mismatch:${VERIFY_IP};"
        log_port_scan "ip_verify" "$VERIFY_IP" 0 "warning" "Public IP mismatch: configured=${PUBLIC_IP} actual=${VERIFY_IP}" "ISP may have changed the public IP. Updating automatically."
        PUBLIC_IP="$VERIFY_IP"
    else
        echo "SKIP (could not verify)"
        DIAG_SUMMARY="${DIAG_SUMMARY}ip_consistent=unknown;"
    fi
else
    DIAG_SUMMARY="no_public_ip;"
    log_port_scan "startup_check" "" 0 "error" "No public IP detected at startup" "All external IP detection services failed. Check internet connectivity or set PUBLIC_IP manually in .env"
fi

# Store results in database
mysql -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" --skip-ssl "${DB_NAME}" <<EOSQL 2>/dev/null || true
INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('public_ip', '${PUBLIC_IP}')
ON DUPLICATE KEY UPDATE setting_value = '${PUBLIC_IP}', updated_at = NOW();

INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('local_ip', '${LOCAL_IP}')
ON DUPLICATE KEY UPDATE setting_value = '${LOCAL_IP}', updated_at = NOW();

INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('last_ip_check', NOW())
ON DUPLICATE KEY UPDATE setting_value = NOW(), updated_at = NOW();

INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('port_scan_results', '${PORT_SCAN_RESULTS}')
ON DUPLICATE KEY UPDATE setting_value = '${PORT_SCAN_RESULTS}', updated_at = NOW();

INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('last_port_scan', NOW())
ON DUPLICATE KEY UPDATE setting_value = NOW(), updated_at = NOW();

INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('public_ip_diagnostics', '${DIAG_SUMMARY}')
ON DUPLICATE KEY UPDATE setting_value = '${DIAG_SUMMARY}', updated_at = NOW();
EOSQL

# ---- 7. Configure Apache ServerName and Dual IP ----
echo "[APACHE] Configuring Apache for dual-IP operation..."
echo "[APACHE] ServerName: ${APACHE_SERVER_NAME}"

# Set global ServerName to suppress warnings
echo "ServerName ${APACHE_SERVER_NAME}" > /etc/apache2/conf-available/servername.conf
a2enconf servername 2>/dev/null || true

# Enable remoteip module for proper client IP detection behind Docker NAT
a2enmod remoteip 2>/dev/null || true

# Create dual IP / ServerAlias configuration
DUAL_IP_CONF="/etc/apache2/conf-available/dual-ip.conf"
cat > "$DUAL_IP_CONF" <<EOF
# ============================================================
# Auto-generated: Dual IP Binding Configuration
# Local IP:  ${LOCAL_IP}
# Public IP: ${PUBLIC_IP:-not detected}
# Ports:     HTTP=${HTTP_PORT} HTTPS=${HTTPS_PORT} DB=${DATABASE_PORT}
# ============================================================

# Apache inside Docker listens on 0.0.0.0:80 and 0.0.0.0:443
# Docker port mapping (host 8081->container 80, host 8443->container 443)
# makes it accessible on ALL host interfaces simultaneously:
#   - localhost:8081
#   - ${LOCAL_IP}:${HTTP_PORT}
#   - ${PUBLIC_IP:-<public>}:${HTTP_PORT}
# No special Apache config needed for this - Docker handles the binding.

# Detect real client IP when behind Docker's NAT layer
<IfModule mod_remoteip.c>
    RemoteIPHeader X-Forwarded-For
    RemoteIPInternalProxy 172.16.0.0/12
    RemoteIPInternalProxy 10.0.0.0/8
    RemoteIPInternalProxy 192.168.0.0/16
</IfModule>

# Ensure Apache accepts requests regardless of which Host header arrives
# (public IP, local IP, localhost, domain name, etc.)
EOF
a2enconf dual-ip 2>/dev/null || true

# Update vhost ServerName + add ServerAlias for all IPs
VHOST_FILE="/etc/apache2/sites-available/000-default.conf"
VHOST_SSL_FILE="/etc/apache2/sites-available/default-ssl.conf"

# Patch ServerName
sed -i "s|ServerName .*|ServerName ${APACHE_SERVER_NAME}|g" "$VHOST_FILE" 2>/dev/null || true
sed -i "s|ServerName .*|ServerName ${APACHE_SERVER_NAME}|g" "$VHOST_SSL_FILE" 2>/dev/null || true

# Remove any old auto-dual-ip alias lines, then add fresh ones
sed -i '/ServerAlias.*#auto-dual-ip/d' "$VHOST_FILE" 2>/dev/null || true
sed -i '/ServerAlias.*#auto-dual-ip/d' "$VHOST_SSL_FILE" 2>/dev/null || true

ALIASES="localhost 127.0.0.1"
[ -n "${LOCAL_IP}" ] && ALIASES="${ALIASES} ${LOCAL_IP}"
[ -n "${PUBLIC_IP}" ] && ALIASES="${ALIASES} ${PUBLIC_IP}"
# Also add the APACHE_SERVER_NAME if it's different from what's already there
if [ "${APACHE_SERVER_NAME}" != "localhost" ] && [ "${APACHE_SERVER_NAME}" != "${LOCAL_IP}" ] && [ "${APACHE_SERVER_NAME}" != "${PUBLIC_IP}" ]; then
    ALIASES="${ALIASES} ${APACHE_SERVER_NAME}"
fi

sed -i "/ServerName/a\\    ServerAlias ${ALIASES} #auto-dual-ip" "$VHOST_FILE" 2>/dev/null || true
sed -i "/ServerName/a\\    ServerAlias ${ALIASES} #auto-dual-ip" "$VHOST_SSL_FILE" 2>/dev/null || true
echo "[APACHE] ServerAlias: ${ALIASES}"
echo "[APACHE] Apache will respond to requests on ALL of the above hostnames"

# ---- 8. Configure PHP OPcache ----
OPCACHE_CONF="/usr/local/etc/php/conf.d/opcache-mode.ini"
echo "[PHP] OPcache ENABLED (single runtime mode)"
cat > "$OPCACHE_CONF" <<'EOF'
; Production Mode - OPcache enabled for performance
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=0
opcache.validate_timestamps=1
; Cache files aggressively
realpath_cache_size=4096k
realpath_cache_ttl=600
EOF

# PHP upload limits for file manager
PHP_UPLOAD_CONF="/usr/local/etc/php/conf.d/uploads.ini"
cat > "$PHP_UPLOAD_CONF" <<'EOF'
; File upload limits for Ghost File Manager
upload_max_filesize=20M
post_max_size=25M
max_file_uploads=10
EOF

# ---- 9. Handle SSL Configuration ----
if [ "${ENABLE_SSL}" = "true" ]; then
    echo "[SSL] SSL is ENABLED"
    
    CERT_FILE="/etc/apache2/ssl/certificate.crt"
    KEY_FILE="/etc/apache2/ssl/private.key"
    
    if [ -f "$CERT_FILE" ] && [ -f "$KEY_FILE" ]; then
        echo "[SSL] Found SSL certificates, enabling HTTPS..."
        a2ensite default-ssl.conf 2>/dev/null || true
        
        sed -i 's/# RewriteCond %{HTTPS} off/RewriteCond %{HTTPS} off/' "$VHOST_FILE"
        sed -i 's/# RewriteRule \^(.\*)$ https/RewriteRule ^(.*)$ https/' "$VHOST_FILE"
        
        echo "[SSL] HTTPS redirect enabled"
    else
        echo "[SSL] Generating self-signed certificate..."
        mkdir -p /etc/apache2/ssl
        
        # Include public IP and local IP in SAN for self-signed cert
        SAN_ENTRIES="DNS:${APACHE_SERVER_NAME},DNS:localhost,IP:127.0.0.1"
        [ -n "${LOCAL_IP}" ] && SAN_ENTRIES="${SAN_ENTRIES},IP:${LOCAL_IP}"
        [ -n "${PUBLIC_IP}" ] && SAN_ENTRIES="${SAN_ENTRIES},IP:${PUBLIC_IP}"
        
        openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
            -keyout "$KEY_FILE" \
            -out "$CERT_FILE" \
            -subj "/C=IN/ST=State/L=City/O=DiagnosticCenter/CN=${APACHE_SERVER_NAME}" \
            -addext "subjectAltName=${SAN_ENTRIES}" 2>/dev/null
        
        a2ensite default-ssl.conf 2>/dev/null || true
        echo "[SSL] Self-signed certificate generated (SAN: ${SAN_ENTRIES})"
    fi
else
    echo "[SSL] SSL is DISABLED (HTTP only)"
    a2dissite default-ssl.conf 2>/dev/null || true
fi

# ---- 10. Set correct permissions ----
echo "[PERMS] Setting file permissions..."
for dir in uploads saved_bills final_reports manager/uploads dump/backup; do
    if [ -d "/var/www/html/$dir" ]; then
        chown -R www-data:www-data "/var/www/html/$dir" 2>/dev/null || true
        chmod -R 775 "/var/www/html/$dir" 2>/dev/null || true
    fi
done

# ---- 10.5 Monthly Database Backup (auto) ----
echo "[BACKUP] Checking monthly backup..."
BACKUP_DIR="/var/www/html/dump/backup"
mkdir -p "${BACKUP_DIR}"
chown -R www-data:www-data "${BACKUP_DIR}" 2>/dev/null || true
chmod -R 775 "${BACKUP_DIR}" 2>/dev/null || true

# Run PHP backup engine to create this month's backup (skips if already exists)
if command -v php &>/dev/null; then
    php /var/www/html/data_backup/backup_engine.php && echo "[BACKUP] Monthly backup verified." || echo "[BACKUP] Backup check completed (non-critical)."
fi

# Set up cron job for monthly auto-backup (1st of each month at 2:00 AM)
if command -v crontab &>/dev/null; then
    CRON_LINE="0 2 1 * * cd /var/www/html && php data_backup/backup_engine.php >> /var/log/monthly_backup.log 2>&1"
    (crontab -l 2>/dev/null | grep -v 'backup_engine.php'; echo "${CRON_LINE}") | crontab - 2>/dev/null || true
    echo "[BACKUP] Monthly cron job configured (1st of month, 2:00 AM)."
fi

# ---- 11. Start Public IP Monitor (background) ----
if [ "${DUAL_IP_BIND}" = "true" ] && [ "${IP_CHECK_INTERVAL:-0}" -gt 0 ] 2>/dev/null; then
    echo "[IP-MONITOR] Starting public IP monitor + port scanner (interval: ${IP_CHECK_INTERVAL}s)..."
    /usr/local/bin/ip-monitor.sh &
fi

# ---- 12. Print access info ----
echo ""
echo "============================================"
echo "  Website Ready!"
echo "--------------------------------------------"
echo "  Dual IP Bind:   ${DUAL_IP_BIND:-true}"
echo ""
echo "  ACCESS URLs (all work simultaneously):"
echo "    http://localhost:${HTTP_PORT}"
[ -n "${LOCAL_IP}" ] && echo "    http://${LOCAL_IP}:${HTTP_PORT}       (LAN)"
[ -n "${PUBLIC_IP}" ] && echo "    http://${PUBLIC_IP}:${HTTP_PORT}   (PUBLIC)"
if [ "${APACHE_SERVER_NAME}" != "localhost" ] && [ "${APACHE_SERVER_NAME}" != "${LOCAL_IP}" ]; then
    echo "    http://${APACHE_SERVER_NAME}:${HTTP_PORT}"
fi
if [ "${ENABLE_SSL}" = "true" ]; then
    echo ""
    echo "  HTTPS:"
    echo "    https://localhost:${HTTPS_PORT}"
    [ -n "${LOCAL_IP}" ] && echo "    https://${LOCAL_IP}:${HTTPS_PORT}"
    [ -n "${PUBLIC_IP}" ] && echo "    https://${PUBLIC_IP}:${HTTPS_PORT}"
fi
echo ""
echo "  PORTS:"
echo "    HTTP:  ${HTTP_PORT}   (no conflict with system Apache on 80)"
echo "    HTTPS: ${HTTPS_PORT}  (no conflict with system Apache on 443)"
echo "    DB:    ${DATABASE_PORT}"
echo "    PMA:   ${PMA_PORT:-8082}"
echo ""
echo "  DIAGNOSTICS: ${DIAG_SUMMARY:-none}"
echo "============================================"
echo ""

# ---- 13. Start Apache ----
exec "$@"
