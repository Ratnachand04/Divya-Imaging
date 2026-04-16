#!/bin/bash
# ============================================================
# Public IP Monitor + Port Scanner - Background Service
# ============================================================
# Periodically checks if public IP has changed, verifies port
# reachability on both local and public IPs, and runs error
# diagnostics. Updates Apache config + database accordingly.
# ============================================================

INTERVAL="${IP_CHECK_INTERVAL:-300}"
LAST_KNOWN_IP="${PUBLIC_IP:-}"
HTTP_PORT="${APP_PORT:-8081}"
HTTPS_PORT="${SSL_PORT:-8443}"
DATABASE_PORT="${DB_PORT:-3301}"
APACHE_RELOAD_ON_IP_CHANGE="${IP_MONITOR_APACHE_RELOAD:-false}"

echo "[IP-MONITOR] Started. Checking every ${INTERVAL}s. Current IP: ${LAST_KNOWN_IP}"
echo "[IP-MONITOR] Monitoring ports: HTTP=${HTTP_PORT}, HTTPS=${HTTPS_PORT}, DB=${DATABASE_PORT}"
echo "[IP-MONITOR] Apache reload on IP change: ${APACHE_RELOAD_ON_IP_CHANGE}"

# ---- Helper Functions ----

# Detect public IP with fallback chain
detect_public_ip() {
    local ip=""
    for svc in "https://api.ipify.org" "https://ifconfig.me" "https://icanhazip.com" "https://checkip.amazonaws.com"; do
        ip=$(curl -s --max-time 10 "$svc" 2>/dev/null | tr -d '[:space:]')
        if echo "$ip" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$'; then
            echo "$ip"
            return 0
        fi
    done
    echo ""
    return 1
}

is_private_or_loopback_ip() {
    local ip="$1"
    if [ -z "$ip" ]; then
        return 0
    fi

    if [[ "$ip" =~ ^127\. ]] || [[ "$ip" =~ ^10\. ]] || [[ "$ip" =~ ^192\.168\. ]] || [[ "$ip" =~ ^172\.(1[6-9]|2[0-9]|3[0-1])\. ]] || [[ "$ip" =~ ^169\.254\. ]]; then
        return 0
    fi

    return 1
}

should_reload_apache_for_public_ip_change() {
    local server_name="${APACHE_SERVER_NAME:-localhost}"

    # Only relevant when dual-IP mode is explicitly enabled.
    if [ "${DUAL_IP_BIND:-false}" != "true" ]; then
        return 1
    fi

    # Localhost deployments should not reload Apache on WAN IP churn.
    if [ "$server_name" = "localhost" ] || [ "$server_name" = "127.0.0.1" ]; then
        return 1
    fi

    # If ServerName is an IP and it is private/local, skip reload.
    if echo "$server_name" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$'; then
        if is_private_or_loopback_ip "$server_name"; then
            return 1
        fi
    fi

    return 0
}

# Log a diagnostic entry to the ip_diagnostics table
log_diagnostic() {
    local check_type="$1"
    local ip="$2"
    local port="$3"
    local status="$4"
    local message="$5"
    local details="$6"
    mysql -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" --skip-ssl "${DB_NAME}" \
        -e "INSERT INTO ip_diagnostics (check_type, ip_address, port, status, message, details) VALUES ('${check_type}', '${ip}', ${port}, '${status}', '$(echo "$message" | sed "s/'/''/g")', '$(echo "$details" | sed "s/'/''/g")');" 2>/dev/null || true
}

# Test if a specific port is reachable on a given public IP
# Returns: 0 = reachable, 1 = not reachable
# Sets PORT_CHECK_DETAIL with the result
PORT_CHECK_DETAIL=""
test_public_port() {
    local ip="$1"
    local port="$2"
    local protocol="${3:-http}"

    # Use curl to test the port from inside the container to the public IP
    local http_code
    http_code=$(curl -k -s -o /dev/null -w "%{http_code}" --max-time 5 "${protocol}://${ip}:${port}/" 2>/dev/null || true)
    if [ -z "$http_code" ]; then
        http_code="000"
    fi

    if [ "$http_code" != "000" ]; then
        PORT_CHECK_DETAIL="open:${http_code}"
        return 0
    fi

    # Try a raw TCP check via /dev/tcp (bash built-in)
    if (echo >/dev/tcp/"$ip"/"$port") 2>/dev/null; then
        PORT_CHECK_DETAIL="tcp_open"
        return 0
    fi

    # Determine likely cause
    PORT_CHECK_DETAIL="closed"
    return 1
}

# Run full port scan on public IP and categorize errors
run_port_diagnostics() {
    local ip="$1"
    local diag_summary=""

    if [ -z "$ip" ]; then
        diag_summary="no_public_ip"
        log_diagnostic "periodic_scan" "" 0 "error" "No public IP available for port scan" ""
        echo "$diag_summary"
        return
    fi

    # Test HTTP port
    if test_public_port "$ip" "$HTTP_PORT" "http"; then
        diag_summary="${diag_summary}http=${HTTP_PORT}:ok(${PORT_CHECK_DETAIL});"
        log_diagnostic "port_scan" "$ip" "$HTTP_PORT" "ok" "HTTP port ${HTTP_PORT} is reachable" "Result: ${PORT_CHECK_DETAIL}"
    else
        diag_summary="${diag_summary}http=${HTTP_PORT}:BLOCKED;"
        # Classify the error
        local error_detail="Port ${HTTP_PORT} unreachable on ${ip}. "
        error_detail="${error_detail}Possible causes: 1) Router port forwarding not configured for ${HTTP_PORT}, "
        error_detail="${error_detail}2) ISP blocking inbound connections (CGNAT/NAT), "
        error_detail="${error_detail}3) Windows Firewall blocking port ${HTTP_PORT}, "
        error_detail="${error_detail}4) Hairpin NAT not supported by router (test from external device)"
        log_diagnostic "port_scan" "$ip" "$HTTP_PORT" "error" "HTTP port ${HTTP_PORT} BLOCKED on public IP" "$error_detail"
    fi

    # Test HTTPS port
    if test_public_port "$ip" "$HTTPS_PORT" "https"; then
        diag_summary="${diag_summary}https=${HTTPS_PORT}:ok(${PORT_CHECK_DETAIL});"
        log_diagnostic "port_scan" "$ip" "$HTTPS_PORT" "ok" "HTTPS port ${HTTPS_PORT} is reachable" "Result: ${PORT_CHECK_DETAIL}"
    else
        diag_summary="${diag_summary}https=${HTTPS_PORT}:BLOCKED;"
        log_diagnostic "port_scan" "$ip" "$HTTPS_PORT" "error" "HTTPS port ${HTTPS_PORT} BLOCKED on public IP" "Port forwarding may not be configured for SSL port"
    fi

    # Test internet connectivity (outbound)
    if curl -s --max-time 5 https://www.google.com -o /dev/null 2>/dev/null; then
        diag_summary="${diag_summary}internet=ok;"
    else
        diag_summary="${diag_summary}internet=FAIL;"
        log_diagnostic "internet_check" "" 0 "error" "Outbound internet connectivity failed" "Container cannot reach external services"
    fi

    # Check for ISP CGNAT (Carrier-Grade NAT)
    # If the detected IP is in certain known CGNAT ranges (100.64.0.0/10) it's behind ISP NAT
    local first_octet second_octet
    first_octet=$(echo "$ip" | cut -d. -f1)
    second_octet=$(echo "$ip" | cut -d. -f2)
    if [ "$first_octet" = "100" ] && [ "$second_octet" -ge 64 ] && [ "$second_octet" -le 127 ]; then
        diag_summary="${diag_summary}cgnat=DETECTED;"
        log_diagnostic "cgnat_check" "$ip" 0 "error" "ISP CGNAT detected - IP ${ip} is in 100.64.0.0/10 range" "Your ISP uses Carrier-Grade NAT. Inbound connections will NOT work. Contact ISP for a static/dedicated IP or use a tunneling service (ngrok, cloudflared)."
    else
        diag_summary="${diag_summary}cgnat=none;"
    fi

    echo "$diag_summary"
}

# ---- Main Monitor Loop ----
CYCLE=0
while true; do
    sleep "${INTERVAL}"
    CYCLE=$((CYCLE + 1))

    echo "[IP-MONITOR] $(date '+%Y-%m-%d %H:%M:%S') Cycle #${CYCLE} starting..."

    # ---- Detect current public IP ----
    NEW_IP=$(detect_public_ip)

    if [ -z "$NEW_IP" ]; then
        echo "[IP-MONITOR] $(date '+%Y-%m-%d %H:%M:%S') WARNING: Could not detect valid public IP"
        # Log error to database
        mysql -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" --skip-ssl "${DB_NAME}" <<EOSQL 2>/dev/null || true
INSERT INTO error_logs (error_level, error_message, file_path, line_number, request_url)
VALUES (2, 'Public IP detection failed - all services unreachable', 'ip-monitor.sh', 0, 'background-service');

INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('ip_last_error', 'All IP detection services failed at $(date "+%Y-%m-%d %H:%M:%S"). Check internet connectivity.')
ON DUPLICATE KEY UPDATE setting_value = 'All IP detection services failed at $(date "+%Y-%m-%d %H:%M:%S"). Check internet connectivity.', updated_at = NOW();
EOSQL
        log_diagnostic "ip_detection" "" 0 "error" "All IP detection services unreachable" "Tried: ipify, ifconfig.me, icanhazip, checkip.amazonaws. Container may have lost internet."
        continue
    fi

    # ---- Check if IP changed ----
    if [ "$NEW_IP" != "$LAST_KNOWN_IP" ] && [ -n "$LAST_KNOWN_IP" ]; then
        echo "[IP-MONITOR] $(date '+%Y-%m-%d %H:%M:%S') PUBLIC IP CHANGED: ${LAST_KNOWN_IP} -> ${NEW_IP}"
        
        log_diagnostic "ip_change" "$NEW_IP" 0 "warning" "Public IP changed from ${LAST_KNOWN_IP} to ${NEW_IP}" "Apache ServerAlias will be updated automatically."

        LOCAL_IP_CURRENT=$(hostname -I 2>/dev/null | awk '{print $1}' || echo "127.0.0.1")

        if [ "${APACHE_RELOAD_ON_IP_CHANGE}" = "true" ] && should_reload_apache_for_public_ip_change; then
            # Update Apache ServerAlias
            VHOST_FILE="/etc/apache2/sites-available/000-default.conf"
            VHOST_SSL_FILE="/etc/apache2/sites-available/default-ssl.conf"
            
            # Remove old auto-generated alias lines
            sed -i '/ServerAlias.*#auto-dual-ip/d' "$VHOST_FILE" 2>/dev/null || true
            sed -i '/ServerAlias.*#auto-dual-ip/d' "$VHOST_SSL_FILE" 2>/dev/null || true
            
            # Rebuild aliases
            ALIASES="localhost 127.0.0.1 ${LOCAL_IP_CURRENT} ${NEW_IP}"
            [ "${APACHE_SERVER_NAME}" != "localhost" ] && [ "${APACHE_SERVER_NAME}" != "${LOCAL_IP_CURRENT}" ] && [ "${APACHE_SERVER_NAME}" != "${NEW_IP}" ] && ALIASES="${ALIASES} ${APACHE_SERVER_NAME}"
            
            sed -i "/ServerName/a\\    ServerAlias ${ALIASES} #auto-dual-ip" "$VHOST_FILE" 2>/dev/null || true
            sed -i "/ServerName/a\\    ServerAlias ${ALIASES} #auto-dual-ip" "$VHOST_SSL_FILE" 2>/dev/null || true
            
            # Graceful Apache reload (no downtime)
            apache2ctl graceful 2>/dev/null || true
            echo "[IP-MONITOR] Apache reloaded with new ServerAlias: ${ALIASES}"
            
            # If SSL is enabled, regenerate self-signed cert with new IP in SAN
            if [ "${ENABLE_SSL}" = "true" ] && [ -f "/etc/apache2/ssl/private.key" ]; then
                SAN_ENTRIES="DNS:${APACHE_SERVER_NAME},DNS:localhost,IP:127.0.0.1,IP:${LOCAL_IP_CURRENT},IP:${NEW_IP}"
                openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
                    -keyout "/etc/apache2/ssl/private.key" \
                    -out "/etc/apache2/ssl/certificate.crt" \
                    -subj "/C=IN/ST=State/L=City/O=DiagnosticCenter/CN=${APACHE_SERVER_NAME}" \
                    -addext "subjectAltName=${SAN_ENTRIES}" 2>/dev/null
                apache2ctl graceful 2>/dev/null || true
                echo "[IP-MONITOR] SSL certificate regenerated with new IP SAN"
                log_diagnostic "ssl_update" "$NEW_IP" 0 "ok" "SSL cert regenerated with new public IP SAN" "SAN: ${SAN_ENTRIES}"
            fi
        else
            echo "[IP-MONITOR] Skipping Apache reload for IP change (reload disabled or local/private mode)."
            log_diagnostic "ip_change" "$NEW_IP" 0 "ok" "Public IP changed; Apache reload skipped" "IP_MONITOR_APACHE_RELOAD=${APACHE_RELOAD_ON_IP_CHANGE}; APACHE_SERVER_NAME=${APACHE_SERVER_NAME:-localhost}; DUAL_IP_BIND=${DUAL_IP_BIND:-false}"
        fi
        
        # Update database
        mysql -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" --skip-ssl "${DB_NAME}" <<EOSQL 2>/dev/null || true
INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('public_ip', '${NEW_IP}')
ON DUPLICATE KEY UPDATE setting_value = '${NEW_IP}', updated_at = NOW();

INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('local_ip', '${LOCAL_IP_CURRENT}')
ON DUPLICATE KEY UPDATE setting_value = '${LOCAL_IP_CURRENT}', updated_at = NOW();

INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('last_ip_check', NOW())
ON DUPLICATE KEY UPDATE setting_value = NOW(), updated_at = NOW();

INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('ip_change_log', CONCAT(IFNULL((SELECT setting_value FROM (SELECT * FROM developer_settings) AS t WHERE setting_key='ip_change_log'), ''), '\n', NOW(), ': ${LAST_KNOWN_IP} -> ${NEW_IP}'))
ON DUPLICATE KEY UPDATE setting_value = CONCAT(IFNULL(setting_value, ''), '\n', NOW(), ': ${LAST_KNOWN_IP} -> ${NEW_IP}'), updated_at = NOW();

INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('ip_last_error', '')
ON DUPLICATE KEY UPDATE setting_value = '', updated_at = NOW();
EOSQL
        
        LAST_KNOWN_IP="$NEW_IP"
    else
        # IP unchanged
        if [ -z "$LAST_KNOWN_IP" ]; then
            LAST_KNOWN_IP="$NEW_IP"
        fi
        mysql -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" --skip-ssl "${DB_NAME}" <<EOSQL 2>/dev/null || true
INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('last_ip_check', NOW())
ON DUPLICATE KEY UPDATE setting_value = NOW(), updated_at = NOW();

INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('public_ip', '${NEW_IP}')
ON DUPLICATE KEY UPDATE setting_value = '${NEW_IP}', updated_at = NOW();

INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('ip_last_error', '')
ON DUPLICATE KEY UPDATE setting_value = '', updated_at = NOW();
EOSQL
    fi

    # ---- Periodic Port Scan & Diagnostics ----
    # Run full diagnostics every cycle (public IP port scanning + error identification)
    echo "[IP-MONITOR] Running port diagnostics on ${LAST_KNOWN_IP}..."
    DIAG_RESULT=$(run_port_diagnostics "$LAST_KNOWN_IP")
    echo "[IP-MONITOR] Diagnostics: ${DIAG_RESULT}"

    # Store latest diagnostics in DB
    mysql -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" --skip-ssl "${DB_NAME}" <<EOSQL 2>/dev/null || true
INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('port_scan_results', '${DIAG_RESULT}')
ON DUPLICATE KEY UPDATE setting_value = '${DIAG_RESULT}', updated_at = NOW();

INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('last_port_scan', NOW())
ON DUPLICATE KEY UPDATE setting_value = NOW(), updated_at = NOW();

INSERT INTO developer_settings (setting_key, setting_value)
VALUES ('public_ip_diagnostics', '${DIAG_RESULT}')
ON DUPLICATE KEY UPDATE setting_value = '${DIAG_RESULT}', updated_at = NOW();
EOSQL

    echo "[IP-MONITOR] Cycle #${CYCLE} complete. Next check in ${INTERVAL}s."
done
