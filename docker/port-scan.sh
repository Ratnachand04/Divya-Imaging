#!/bin/bash
# ============================================================
# Port Scanner & Diagnostics - On-demand helper
# ============================================================
# Called by Ghost UI or entrypoint to run full port diagnostics.
# Outputs JSON-formatted results for easy PHP consumption.
# Usage: /usr/local/bin/port-scan.sh [public_ip] [local_ip]
# ============================================================

PUBLIC_IP="${1:-}"
LOCAL_IP="${2:-}"
HTTP_PORT="${APP_PORT:-8081}"
HTTPS_PORT="${SSL_PORT:-8443}"
DATABASE_PORT="${DB_PORT:-3301}"

# Auto-detect if not provided
if [ -z "$LOCAL_IP" ]; then
    LOCAL_IP=$(hostname -I 2>/dev/null | awk '{print $1}' || echo "127.0.0.1")
fi
if [ -z "$PUBLIC_IP" ]; then
    for svc in "https://api.ipify.org" "https://ifconfig.me" "https://icanhazip.com" "https://checkip.amazonaws.com"; do
        PUBLIC_IP=$(curl -s --max-time 5 "$svc" 2>/dev/null | tr -d '[:space:]')
        if echo "$PUBLIC_IP" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$'; then
            break
        fi
        PUBLIC_IP=""
    done
fi

# Output JSON
echo "{"
echo "  \"timestamp\": \"$(date -u '+%Y-%m-%dT%H:%M:%SZ')\","
echo "  \"public_ip\": \"${PUBLIC_IP}\","
echo "  \"local_ip\": \"${LOCAL_IP}\","
echo "  \"ports\": {"
echo "    \"http\": ${HTTP_PORT},"
echo "    \"https\": ${HTTPS_PORT},"
echo "    \"db\": ${DATABASE_PORT}"
echo "  },"
echo "  \"checks\": ["

FIRST=true

# ---- Test 1: Local Apache (container ports 80/443) ----
for port in 80 443; do
    [ "$FIRST" = "true" ] && FIRST=false || echo "    ,"
    http_code=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 3 "http://127.0.0.1:${port}/" 2>/dev/null || echo "000")
    if [ "$http_code" != "000" ]; then
        echo "    {\"type\": \"container_port\", \"ip\": \"127.0.0.1\", \"port\": ${port}, \"status\": \"ok\", \"detail\": \"HTTP ${http_code}\", \"message\": \"Apache listening on container port ${port}\"}"
    else
        echo "    {\"type\": \"container_port\", \"ip\": \"127.0.0.1\", \"port\": ${port}, \"status\": \"error\", \"detail\": \"no_response\", \"message\": \"Apache NOT listening on container port ${port}\"}"
    fi
done

# ---- Test 2: Public IP ports ----
if [ -n "$PUBLIC_IP" ]; then
    for port in ${HTTP_PORT} ${HTTPS_PORT}; do
        echo "    ,"
        http_code=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 5 "http://${PUBLIC_IP}:${port}/" 2>/dev/null || echo "000")
        if [ "$http_code" != "000" ]; then
            echo "    {\"type\": \"public_port\", \"ip\": \"${PUBLIC_IP}\", \"port\": ${port}, \"status\": \"ok\", \"detail\": \"HTTP ${http_code}\", \"message\": \"Port ${port} reachable on public IP\"}"
        else
            # Classify error
            error_reasons="Possible: 1) Port forwarding not configured on router, 2) ISP firewall/CGNAT, 3) Windows firewall blocking, 4) Hairpin NAT unsupported"
            echo "    {\"type\": \"public_port\", \"ip\": \"${PUBLIC_IP}\", \"port\": ${port}, \"status\": \"error\", \"detail\": \"unreachable\", \"message\": \"Port ${port} BLOCKED on public IP. ${error_reasons}\"}"
        fi
    done

    # ---- Test 3: Hairpin NAT ----
    echo "    ,"
    hairpin=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 5 "http://${PUBLIC_IP}:${HTTP_PORT}/" 2>/dev/null || echo "000")
    if [ "$hairpin" != "000" ]; then
        echo "    {\"type\": \"hairpin_nat\", \"ip\": \"${PUBLIC_IP}\", \"port\": ${HTTP_PORT}, \"status\": \"ok\", \"detail\": \"supported\", \"message\": \"Hairpin NAT works - can reach own public IP from inside\"}"
    else
        echo "    {\"type\": \"hairpin_nat\", \"ip\": \"${PUBLIC_IP}\", \"port\": ${HTTP_PORT}, \"status\": \"warning\", \"detail\": \"unsupported\", \"message\": \"Hairpin NAT not supported. External users may still connect. Test from outside network.\"}"
    fi

    # ---- Test 4: CGNAT Detection ----
    echo "    ,"
    first_octet=$(echo "$PUBLIC_IP" | cut -d. -f1)
    second_octet=$(echo "$PUBLIC_IP" | cut -d. -f2)
    if [ "$first_octet" = "100" ] && [ "$second_octet" -ge 64 ] && [ "$second_octet" -le 127 ]; then
        echo "    {\"type\": \"cgnat\", \"ip\": \"${PUBLIC_IP}\", \"port\": 0, \"status\": \"error\", \"detail\": \"cgnat_detected\", \"message\": \"ISP CGNAT detected (100.64.0.0/10). Inbound connections will fail. Contact ISP for dedicated IP or use tunneling (ngrok/cloudflared).\"}"
    else
        echo "    {\"type\": \"cgnat\", \"ip\": \"${PUBLIC_IP}\", \"port\": 0, \"status\": \"ok\", \"detail\": \"no_cgnat\", \"message\": \"No CGNAT detected - public IP appears to be a real public address\"}"
    fi

    # ---- Test 5: IP Consistency ----
    echo "    ,"
    verify_ip=$(curl -s --max-time 5 https://api.ipify.org 2>/dev/null | tr -d '[:space:]')
    if [ "$verify_ip" = "$PUBLIC_IP" ]; then
        echo "    {\"type\": \"ip_consistency\", \"ip\": \"${PUBLIC_IP}\", \"port\": 0, \"status\": \"ok\", \"detail\": \"consistent\", \"message\": \"Public IP is consistent across detection services\"}"
    elif [ -n "$verify_ip" ]; then
        echo "    {\"type\": \"ip_consistency\", \"ip\": \"${verify_ip}\", \"port\": 0, \"status\": \"warning\", \"detail\": \"mismatch\", \"message\": \"IP mismatch: expected ${PUBLIC_IP} but got ${verify_ip}. ISP may have changed your IP.\"}"
    else
        echo "    {\"type\": \"ip_consistency\", \"ip\": \"\", \"port\": 0, \"status\": \"warning\", \"detail\": \"unknown\", \"message\": \"Could not verify IP consistency - detection service unavailable\"}"
    fi
else
    echo "    ,"
    echo "    {\"type\": \"public_ip\", \"ip\": \"\", \"port\": 0, \"status\": \"error\", \"detail\": \"no_public_ip\", \"message\": \"Could not detect public IP. All detection services failed. Check internet connectivity.\"}"
fi

# ---- Test 6: Outbound Internet ----
echo "    ,"
if curl -s --max-time 5 https://www.google.com -o /dev/null 2>/dev/null; then
    echo "    {\"type\": \"internet\", \"ip\": \"\", \"port\": 0, \"status\": \"ok\", \"detail\": \"connected\", \"message\": \"Outbound internet connectivity working\"}"
else
    echo "    {\"type\": \"internet\", \"ip\": \"\", \"port\": 0, \"status\": \"error\", \"detail\": \"no_internet\", \"message\": \"No outbound internet. Container cannot reach external services.\"}"
fi

# ---- Test 7: DNS Resolution ----
if [ -n "${APACHE_SERVER_NAME}" ] && echo "${APACHE_SERVER_NAME}" | grep -qE '[a-zA-Z]'; then
    echo "    ,"
    resolved=$(getent hosts "${APACHE_SERVER_NAME}" 2>/dev/null | awk '{print $1}' || echo "")
    if [ -n "$resolved" ]; then
        echo "    {\"type\": \"dns\", \"ip\": \"${resolved}\", \"port\": 0, \"status\": \"ok\", \"detail\": \"resolved\", \"message\": \"${APACHE_SERVER_NAME} resolves to ${resolved}\"}"
    else
        echo "    {\"type\": \"dns\", \"ip\": \"\", \"port\": 0, \"status\": \"error\", \"detail\": \"no_resolution\", \"message\": \"DNS resolution failed for ${APACHE_SERVER_NAME}. Set A record to ${PUBLIC_IP}.\"}"
    fi
fi

# ---- Test 8: Database port ----
echo "    ,"
if mysqladmin ping -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" --skip-ssl --silent 2>/dev/null; then
    echo "    {\"type\": \"database\", \"ip\": \"${DB_HOST}\", \"port\": ${DATABASE_PORT}, \"status\": \"ok\", \"detail\": \"connected\", \"message\": \"Database reachable on port ${DATABASE_PORT}\"}"
else
    echo "    {\"type\": \"database\", \"ip\": \"${DB_HOST}\", \"port\": ${DATABASE_PORT}, \"status\": \"error\", \"detail\": \"unreachable\", \"message\": \"Database NOT reachable on ${DB_HOST}:${DATABASE_PORT}\"}"
fi

echo "  ]"
echo "}"
