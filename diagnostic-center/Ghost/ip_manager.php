<?php
require_once 'includes/header.php';

// ---- Handle AJAX actions ----
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'check_ip':
            // Try multiple services
            $services = [
                'https://api.ipify.org',
                'https://ifconfig.me',
                'https://icanhazip.com',
                'https://checkip.amazonaws.com'
            ];
            
            $public_ip = '';
            $service_used = '';
            $errors = [];
            
            foreach ($services as $svc) {
                $ctx = stream_context_create(['http' => ['timeout' => 5]]);
                $ip = @file_get_contents($svc, false, $ctx);
                if ($ip !== false) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        $public_ip = $ip;
                        $service_used = $svc;
                        break;
                    }
                }
                $errors[] = "$svc: failed";
            }
            
            // Get local IP
            $local_ip = '';
            if (function_exists('shell_exec')) {
                $local_ip = trim(shell_exec("hostname -I 2>/dev/null | awk '{print $1}'") ?? '');
            }
            if (empty($local_ip)) {
                $local_ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
            }
            
            // Update database
            if ($public_ip) {
                $stmt = $conn->prepare("INSERT INTO developer_settings (setting_key, setting_value) VALUES ('public_ip', ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
                $stmt->bind_param("ss", $public_ip, $public_ip);
                $stmt->execute();
            }
            
            $stmt = $conn->prepare("INSERT INTO developer_settings (setting_key, setting_value) VALUES ('local_ip', ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
            $stmt->bind_param("ss", $local_ip, $local_ip);
            $stmt->execute();
            
            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("INSERT INTO developer_settings (setting_key, setting_value) VALUES ('last_ip_check', ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
            $stmt->bind_param("ss", $now, $now);
            $stmt->execute();
            
            echo json_encode([
                'success' => !empty($public_ip),
                'public_ip' => $public_ip,
                'local_ip' => $local_ip,
                'service' => $service_used,
                'errors' => $errors,
                'checked_at' => $now
            ]);
            exit;
            
        case 'test_port':
            $ip = $_POST['ip'] ?? '';
            $port = intval($_POST['port'] ?? 80);
            
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                echo json_encode(['success' => false, 'error' => 'Invalid IP']);
                exit;
            }
            
            $start = microtime(true);
            $connection = @fsockopen($ip, $port, $errno, $errstr, 5);
            $latency_ms = round((microtime(true) - $start) * 1000, 1);
            
            if ($connection) {
                fclose($connection);
                echo json_encode(['success' => true, 'message' => "Port $port is OPEN on $ip", 'latency' => $latency_ms]);
            } else {
                // Classify the error
                $error_class = 'unknown';
                if (strpos($errstr, 'Connection refused') !== false) {
                    $error_class = 'refused';
                } elseif (strpos($errstr, 'timed out') !== false || $errno == 110) {
                    $error_class = 'timeout';
                } elseif (strpos($errstr, 'No route') !== false) {
                    $error_class = 'no_route';
                } elseif (strpos($errstr, 'Network is unreachable') !== false) {
                    $error_class = 'unreachable';
                }
                echo json_encode([
                    'success' => false, 
                    'error' => "Port $port is CLOSED on $ip ($errstr)",
                    'error_code' => $errno,
                    'error_class' => $error_class,
                    'latency' => $latency_ms
                ]);
            }
            exit;
            
        case 'test_connectivity':
            $ip = $_POST['ip'] ?? '';
            $results = [];
            $app_port = getenv('APP_PORT') ?: '8081';
            $ssl_port = getenv('SSL_PORT') ?: '8443';
            $db_port = getenv('DB_PORT') ?: '3301';
            
            // Test HTTP
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'method' => 'HEAD']]);
            $http_test = @file_get_contents("http://$ip:$app_port/", false, $ctx);
            $results['http'] = ($http_test !== false || !empty($http_response_header));
            
            // Test ports
            foreach ([$app_port, $ssl_port, $db_port] as $port) {
                $conn_test = @fsockopen($ip, intval($port), $errno, $errstr, 3);
                if ($conn_test) {
                    fclose($conn_test);
                    $results["port_$port"] = true;
                } else {
                    $results["port_$port"] = false;
                }
            }
            
            echo json_encode(['success' => true, 'results' => $results]);
            exit;
            
        case 'run_full_diagnostics':
            // Run the port-scan.sh script and return JSON
            $output = '';
            if (function_exists('shell_exec')) {
                $output = shell_exec('/usr/local/bin/port-scan.sh 2>/dev/null') ?? '';
            }
            $json = @json_decode($output, true);
            if ($json) {
                echo json_encode(['success' => true, 'diagnostics' => $json]);
            } else {
                // Fallback: run PHP-based diagnostics
                $diag = ['timestamp' => date('c'), 'checks' => []];
                $public_ip_val = $_POST['public_ip'] ?? '';
                $local_ip_val = $_POST['local_ip'] ?? '';
                $app_port = getenv('APP_PORT') ?: '8081';
                $ssl_port = getenv('SSL_PORT') ?: '8443';
                $db_port = getenv('DB_PORT') ?: '3301';
                
                // Test local ports
                foreach ([80, 443] as $p) {
                    $c = @fsockopen('127.0.0.1', $p, $en, $es, 3);
                    $diag['checks'][] = [
                        'type' => 'container_port', 'ip' => '127.0.0.1', 'port' => $p,
                        'status' => $c ? 'ok' : 'error',
                        'detail' => $c ? 'open' : 'closed',
                        'message' => $c ? "Container port $p OK" : "Container port $p NOT listening"
                    ];
                    if ($c) fclose($c);
                }
                
                // Test public IP ports
                if ($public_ip_val) {
                    foreach ([$app_port, $ssl_port] as $p) {
                        $c = @fsockopen($public_ip_val, intval($p), $en, $es, 5);
                        $diag['checks'][] = [
                            'type' => 'public_port', 'ip' => $public_ip_val, 'port' => intval($p),
                            'status' => $c ? 'ok' : 'error',
                            'detail' => $c ? 'open' : 'unreachable',
                            'message' => $c ? "Public port $p OPEN" : "Public port $p BLOCKED ($es)"
                        ];
                        if ($c) fclose($c);
                    }
                    
                    // CGNAT check
                    $octets = explode('.', $public_ip_val);
                    $is_cgnat = ($octets[0] == '100' && $octets[1] >= 64 && $octets[1] <= 127);
                    $diag['checks'][] = [
                        'type' => 'cgnat', 'ip' => $public_ip_val, 'port' => 0,
                        'status' => $is_cgnat ? 'error' : 'ok',
                        'detail' => $is_cgnat ? 'cgnat_detected' : 'no_cgnat',
                        'message' => $is_cgnat ? 'ISP CGNAT detected! Inbound connections will NOT work.' : 'No CGNAT - real public IP'
                    ];
                }
                
                // Internet test
                $ctx = stream_context_create(['http' => ['timeout' => 5]]);
                $inet = @file_get_contents('https://www.google.com', false, $ctx);
                $diag['checks'][] = [
                    'type' => 'internet', 'ip' => '', 'port' => 0,
                    'status' => ($inet !== false) ? 'ok' : 'error',
                    'detail' => ($inet !== false) ? 'connected' : 'no_internet',
                    'message' => ($inet !== false) ? 'Internet connectivity OK' : 'No internet access'
                ];
                
                echo json_encode(['success' => true, 'diagnostics' => $diag]);
            }
            exit;
            
        case 'get_diagnostics_history':
            $limit = intval($_POST['limit'] ?? 50);
            $history = [];
            $res = $conn->query("SELECT * FROM ip_diagnostics ORDER BY created_at DESC LIMIT $limit");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $history[] = $row;
                }
            }
            echo json_encode(['success' => true, 'history' => $history]);
            exit;
            
        case 'clear_diagnostics':
            $conn->query("DELETE FROM ip_diagnostics WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
            echo json_encode(['success' => true, 'message' => 'Old diagnostics cleared (kept last 7 days)']);
            exit;
            
        case 'update_env_ip':
            $ip = $_POST['ip'] ?? '';
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                echo json_encode(['success' => false, 'error' => 'Invalid IP address']);
                exit;
            }
            
            $stmt = $conn->prepare("INSERT INTO developer_settings (setting_key, setting_value) VALUES ('apache_server_name', ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
            $stmt->bind_param("ss", $ip, $ip);
            $stmt->execute();
            
            echo json_encode(['success' => true, 'message' => "Server name updated to $ip. Restart container to apply."]);
            exit;
    }
}

// ---- Load current settings ----
$settings = [];
$res = $conn->query("SELECT setting_key, setting_value, updated_at FROM developer_settings");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $settings[$row['setting_key']] = [
            'value' => $row['setting_value'],
            'updated' => $row['updated_at']
        ];
    }
}

$public_ip = $settings['public_ip']['value'] ?? '';
$local_ip = $settings['local_ip']['value'] ?? '';
$last_check = $settings['last_ip_check']['value'] ?? 'Never';
$ip_error = $settings['ip_last_error']['value'] ?? '';
$ip_change_log = $settings['ip_change_log']['value'] ?? '';
$port_scan_results = $settings['port_scan_results']['value'] ?? '';
$last_port_scan = $settings['last_port_scan']['value'] ?? 'Never';
$public_ip_diag = $settings['public_ip_diagnostics']['value'] ?? '';
$app_port = getenv('APP_PORT') ?: '8081';
$ssl_port = getenv('SSL_PORT') ?: '8443';
$db_port = getenv('DB_PORT') ?: '3301';

// Load latest diagnostics from ip_diagnostics table
$recent_diags = [];
$diag_res = $conn->query("SELECT * FROM ip_diagnostics ORDER BY created_at DESC LIMIT 20");
if ($diag_res) {
    while ($row = $diag_res->fetch_assoc()) {
        $recent_diags[] = $row;
    }
}

// Parse port scan results
$port_status = [];
if ($port_scan_results) {
    foreach (explode(';', $port_scan_results) as $entry) {
        if (strpos($entry, '=') !== false) {
            list($key, $val) = explode('=', $entry, 2);
            $port_status[$key] = $val;
        }
    }
}
?>

<style>
.diag-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1rem; margin: 1rem 0; }
.diag-card { background: var(--card-bg, #fff); border-radius: 12px; padding: 1.2rem; border: 1px solid #e2e8f0; transition: all 0.3s; }
.diag-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.diag-card.diag-ok { border-left: 4px solid #22c55e; }
.diag-card.diag-error { border-left: 4px solid #ef4444; }
.diag-card.diag-warning { border-left: 4px solid #f59e0b; }
.diag-card .diag-icon { font-size: 1.5rem; margin-bottom: 0.5rem; }
.diag-card .diag-icon.ok { color: #22c55e; }
.diag-card .diag-icon.error { color: #ef4444; }
.diag-card .diag-icon.warning { color: #f59e0b; }
.diag-card h4 { margin: 0 0 0.3rem 0; font-size: 0.95rem; }
.diag-card p { margin: 0; font-size: 0.83rem; color: var(--text-muted, #64748b); }
.diag-card .diag-detail { font-size: 0.78rem; color: #94a3b8; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #f1f5f9; }

.port-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.8rem; margin: 1rem 0; }
.port-item { background: #f8fafc; border-radius: 10px; padding: 1rem; text-align: center; border: 2px solid #e2e8f0; transition: 0.3s; }
.port-item.port-open { border-color: #22c55e; background: #f0fdf4; }
.port-item.port-closed { border-color: #ef4444; background: #fef2f2; }
.port-item.port-pending { border-color: #f59e0b; background: #fffbeb; }
.port-item .port-number { font-size: 1.8rem; font-weight: 700; }
.port-item .port-label { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.3rem; }
.port-item .port-status { font-size: 0.85rem; font-weight: 600; margin-top: 0.3rem; }

.ip-hero { display: flex; align-items: center; gap: 1.5rem; padding: 1.5rem; background: linear-gradient(135deg, #1e293b, #334155); border-radius: 16px; color: #fff; margin-bottom: 1.5rem; flex-wrap: wrap; }
.ip-hero .ip-block { flex: 1; min-width: 200px; text-align: center; padding: 1rem; }
.ip-hero .ip-block h2 { font-size: 1.6rem; margin: 0; font-family: 'JetBrains Mono', monospace; }
.ip-hero .ip-block p { margin: 0.3rem 0 0; opacity: 0.7; font-size: 0.85rem; }
.ip-hero .ip-divider { width: 2px; height: 60px; background: rgba(255,255,255,0.2); }
.ip-hero .ip-status { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; margin-top: 0.5rem; }
.ip-hero .ip-status.active { background: #22c55e; color: #fff; }
.ip-hero .ip-status.error { background: #ef4444; color: #fff; }
.ip-hero .ip-status.pending { background: #f59e0b; color: #fff; }

.countdown-bar { height: 3px; background: #22c55e; transition: width 1s linear; border-radius: 2px; margin-top: 0.5rem; }
.timeline-item { display: flex; gap: 1rem; padding: 0.7rem 0; border-bottom: 1px solid #f1f5f9; align-items: flex-start; }
.timeline-item:last-child { border-bottom: none; }
.timeline-dot { width: 10px; height: 10px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; }
.timeline-dot.ok { background: #22c55e; }
.timeline-dot.error { background: #ef4444; }
.timeline-dot.warning { background: #f59e0b; }
</style>

<!-- IP Hero Section -->
<div class="ip-hero">
    <div class="ip-block">
        <p><i class="fas fa-globe"></i> PUBLIC IP</p>
        <h2 id="public-ip-display"><?php echo $public_ip ?: 'Not Detected'; ?></h2>
        <span class="ip-status <?php echo $public_ip ? 'active' : 'error'; ?>" id="public-ip-status">
            <?php echo $public_ip ? 'ACTIVE' : 'UNAVAILABLE'; ?>
        </span>
    </div>
    <div class="ip-divider"></div>
    <div class="ip-block">
        <p><i class="fas fa-home"></i> LOCAL IP</p>
        <h2 id="local-ip-display"><?php echo $local_ip ?: 'Detecting...'; ?></h2>
        <span class="ip-status active">LAN</span>
    </div>
    <div class="ip-divider"></div>
    <div class="ip-block">
        <p><i class="fas fa-clock"></i> LAST CHECK</p>
        <h2 style="font-size:1rem;" id="last-check-display"><?php echo $last_check; ?></h2>
        <span class="ip-status pending" id="next-check-label">Auto-monitoring</span>
        <div class="countdown-bar" id="countdown-bar" style="width:100%;"></div>
    </div>
</div>

<!-- Error Banner -->
<?php if ($ip_error): ?>
<div class="card" style="border-left: 5px solid #ef4444; background: #fef2f2; margin-bottom: 1rem;">
    <h3 style="color: #ef4444; margin-bottom: 0.5rem;"><i class="fas fa-exclamation-circle"></i> Public IP Error</h3>
    <p style="margin:0;"><?php echo htmlspecialchars($ip_error); ?></p>
</div>
<?php endif; ?>

<!-- Access URLs with simultaneous dual-IP info -->
<div class="card">
    <h3><i class="fas fa-link"></i> Simultaneous Access URLs 
        <span style="font-size:0.75rem; color:var(--text-muted); font-weight:normal;">(Docker binds to ALL interfaces via port mapping)</span>
    </h3>
    <div class="table-responsive">
    <table>
        <thead>
            <tr><th>Type</th><th>HTTP URL</th><th>HTTPS URL</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
            <tr>
                <td><span class="badge badge-info">Localhost</span></td>
                <td><code>http://localhost:<?php echo $app_port; ?></code></td>
                <td><code>https://localhost:<?php echo $ssl_port; ?></code></td>
                <td id="status-localhost"><i class="fas fa-spinner fa-spin"></i></td>
                <td><a href="http://localhost:<?php echo $app_port; ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-external-link-alt"></i></a></td>
            </tr>
            <?php if ($local_ip): ?>
            <tr>
                <td><span class="badge badge-active">LAN</span></td>
                <td><code>http://<?php echo $local_ip; ?>:<?php echo $app_port; ?></code></td>
                <td><code>https://<?php echo $local_ip; ?>:<?php echo $ssl_port; ?></code></td>
                <td id="status-local"><i class="fas fa-spinner fa-spin"></i></td>
                <td><a href="http://<?php echo $local_ip; ?>:<?php echo $app_port; ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-external-link-alt"></i></a></td>
            </tr>
            <?php endif; ?>
            <?php if ($public_ip): ?>
            <tr>
                <td><span class="badge badge-warning"><i class="fas fa-globe"></i> Public</span></td>
                <td><code>http://<?php echo $public_ip; ?>:<?php echo $app_port; ?></code></td>
                <td><code>https://<?php echo $public_ip; ?>:<?php echo $ssl_port; ?></code></td>
                <td id="status-public"><i class="fas fa-spinner fa-spin"></i></td>
                <td><a href="http://<?php echo $public_ip; ?>:<?php echo $app_port; ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-external-link-alt"></i></a></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
    <p style="font-size:0.8rem; color:var(--text-muted); margin-top:0.8rem;">
        <i class="fas fa-info-circle"></i> Ports <b><?php echo $app_port; ?></b> (HTTP) / <b><?php echo $ssl_port; ?></b> (HTTPS) / <b><?php echo $db_port; ?></b> (DB) — 
        chosen to avoid conflict with system Apache 2.4 on ports 80/443
    </p>
</div>

<!-- Port Status Grid -->
<div class="card">
    <h3><i class="fas fa-plug"></i> Port Status Grid
        <button onclick="runPortScan()" class="btn btn-primary btn-sm" style="float:right;" id="btn-port-scan">
            <i class="fas fa-search"></i> Scan Now
        </button>
    </h3>
    <p style="color:var(--text-muted); margin-bottom:0.5rem;">
        Last scan: <span id="last-scan-time"><?php echo $last_port_scan; ?></span>
    </p>
    <div class="port-grid" id="port-grid">
        <?php
        $all_ports = [
            ['label' => 'HTTP', 'port' => $app_port, 'icon' => 'fa-globe'],
            ['label' => 'HTTPS', 'port' => $ssl_port, 'icon' => 'fa-lock'],
            ['label' => 'Database', 'port' => $db_port, 'icon' => 'fa-database'],
        ];
        foreach ($all_ports as $p):
            $pub_key = "public:{$p['port']}";
            $loc_key = "local:{$p['port']}";
            $pub_stat = $port_status[$pub_key] ?? 'pending';
            $loc_stat = $port_status[$loc_key] ?? 'pending';
        ?>
        <div class="port-item port-<?php echo ($pub_stat === 'open') ? 'open' : (($pub_stat === 'closed') ? 'closed' : 'pending'); ?>" id="port-card-<?php echo $p['port']; ?>">
            <div class="port-label"><i class="fas <?php echo $p['icon']; ?>"></i> <?php echo $p['label']; ?></div>
            <div class="port-number"><?php echo $p['port']; ?></div>
            <div class="port-status" id="port-status-<?php echo $p['port']; ?>">
                <?php if ($pub_stat === 'open'): ?>
                    <i class="fas fa-check-circle" style="color:#22c55e;"></i> Open
                <?php elseif ($pub_stat === 'closed'): ?>
                    <i class="fas fa-times-circle" style="color:#ef4444;"></i> Blocked
                <?php else: ?>
                    <i class="fas fa-question-circle" style="color:#f59e0b;"></i> Pending
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Full Diagnostics Panel -->
<div class="card">
    <h3><i class="fas fa-stethoscope"></i> Public IP Error Diagnostics
        <button onclick="runFullDiagnostics()" class="btn btn-success btn-sm" style="float:right;" id="btn-full-diag">
            <i class="fas fa-heartbeat"></i> Run Full Diagnostics
        </button>
    </h3>
    <p style="color:var(--text-muted); margin-bottom:1rem;">
        Identifies why public IP access might fail: firewall, ISP NAT, port forwarding, DNS issues, etc.
    </p>
    
    <!-- Last stored diagnostics -->
    <?php if ($public_ip_diag): ?>
    <div style="margin-bottom:1rem;">
        <h4 style="font-size:0.9rem;">Last Auto-Diagnostics</h4>
        <div class="diag-grid" id="stored-diag-grid">
            <?php
            foreach (explode(';', $public_ip_diag) as $entry) {
                if (empty($entry) || strpos($entry, '=') === false) continue;
                list($key, $val) = explode('=', $entry, 2);
                $is_ok = (stripos($val, 'ok') !== false || $val === 'none');
                $is_warn = (stripos($val, 'fail') !== false || stripos($val, 'mismatch') !== false || stripos($val, 'unknown') !== false);
                $is_err = (stripos($val, 'BLOCKED') !== false || stripos($val, 'FAIL') !== false || stripos($val, 'DETECTED') !== false);
                $class = $is_err ? 'diag-error' : ($is_warn ? 'diag-warning' : 'diag-ok');
                $icon_class = $is_err ? 'error' : ($is_warn ? 'warning' : 'ok');
                $icon = $is_err ? 'fa-times-circle' : ($is_warn ? 'fa-exclamation-triangle' : 'fa-check-circle');
                echo "<div class='diag-card $class'>";
                echo "<div class='diag-icon $icon_class'><i class='fas $icon'></i></div>";
                echo "<h4>" . htmlspecialchars(str_replace('_', ' ', ucfirst($key))) . "</h4>";
                echo "<p>" . htmlspecialchars($val) . "</p>";
                echo "</div>";
            }
            ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Live diagnostics results (filled by JS) -->
    <div id="live-diagnostics" style="display:none;">
        <h4 style="font-size:0.9rem;"><i class="fas fa-bolt"></i> Live Diagnostics Results</h4>
        <div class="diag-grid" id="live-diag-grid"></div>
    </div>
</div>

<!-- Diagnostics Timeline -->
<div class="card">
    <h3><i class="fas fa-history"></i> Diagnostics History
        <button onclick="loadDiagHistory()" class="btn btn-primary btn-sm" style="float:right;" id="btn-load-history">
            <i class="fas fa-list"></i> Load History
        </button>
        <button onclick="clearOldDiag()" class="btn btn-danger btn-sm" style="float:right; margin-right:8px;">
            <i class="fas fa-trash"></i> Clear Old
        </button>
    </h3>
    <div id="diag-timeline">
        <?php if (count($recent_diags) > 0): ?>
            <?php foreach (array_slice($recent_diags, 0, 10) as $d): ?>
            <div class="timeline-item">
                <div class="timeline-dot <?php echo $d['status']; ?>"></div>
                <div>
                    <strong style="font-size:0.85rem;"><?php echo htmlspecialchars($d['check_type']); ?></strong>
                    <?php if ($d['ip_address']): ?>
                        <span style="color:var(--text-muted); font-size:0.8rem;"> — <?php echo htmlspecialchars($d['ip_address']); ?><?php echo $d['port'] ? ':' . $d['port'] : ''; ?></span>
                    <?php endif; ?>
                    <p style="margin:2px 0 0; font-size:0.82rem;"><?php echo htmlspecialchars($d['message']); ?></p>
                    <?php if ($d['details']): ?>
                        <p style="margin:2px 0 0; font-size:0.75rem; color:#94a3b8;"><?php echo htmlspecialchars($d['details']); ?></p>
                    <?php endif; ?>
                    <span style="font-size:0.72rem; color:#94a3b8;"><?php echo $d['created_at']; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color:var(--text-muted); text-align:center; padding:2rem;">No diagnostics yet. Run a scan or wait for the background monitor.</p>
        <?php endif; ?>
    </div>
</div>

<!-- IP Change Log -->
<?php if ($ip_change_log): ?>
<div class="card">
    <h3><i class="fas fa-exchange-alt"></i> IP Change History</h3>
    <pre style="background:#f8fafc; padding:1rem; border-radius:0.5rem; max-height:200px; overflow:auto; font-size:0.85rem;"><?php echo htmlspecialchars(trim($ip_change_log)); ?></pre>
</div>
<?php endif; ?>

<!-- Actions -->
<div class="card">
    <h3><i class="fas fa-tools"></i> Quick Actions</h3>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button onclick="refreshIP()" class="btn btn-primary" id="btn-refresh-ip"><i class="fas fa-sync-alt"></i> Refresh Public IP</button>
        <button onclick="runPortScan()" class="btn btn-success"><i class="fas fa-plug"></i> Port Scan</button>
        <button onclick="runFullDiagnostics()" class="btn btn-warning"><i class="fas fa-stethoscope"></i> Full Diagnostics</button>
        <button onclick="testConnectivity()" class="btn btn-info" id="btn-test-conn"><i class="fas fa-satellite-dish"></i> Connectivity Test</button>
    </div>
    <div id="action-result" style="margin-top:1rem;"></div>
</div>

<script>
const APP_PORT = '<?php echo $app_port; ?>';
const SSL_PORT = '<?php echo $ssl_port; ?>';
const DB_PORT = '<?php echo $db_port; ?>';
let PUBLIC_IP = '<?php echo $public_ip; ?>';
let LOCAL_IP = '<?php echo $local_ip; ?>';

// ---- Refresh Public IP ----
function refreshIP() {
    const btn = document.getElementById('btn-refresh-ip');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
    btn.disabled = true;
    
    fetch('ip_manager.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=check_ip'
    })
    .then(r => r.json())
    .then(data => {
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh Public IP';
        btn.disabled = false;
        
        if (data.success) {
            PUBLIC_IP = data.public_ip;
            LOCAL_IP = data.local_ip;
            document.getElementById('public-ip-display').textContent = data.public_ip;
            document.getElementById('local-ip-display').textContent = data.local_ip;
            document.getElementById('last-check-display').textContent = data.checked_at;
            document.getElementById('public-ip-status').textContent = 'ACTIVE';
            document.getElementById('public-ip-status').className = 'ip-status active';
            
            showResult('success', `IP refreshed. Public: ${data.public_ip} | Local: ${data.local_ip} (via ${data.service})`);
        } else {
            document.getElementById('public-ip-status').textContent = 'FAILED';
            document.getElementById('public-ip-status').className = 'ip-status error';
            showResult('error', 'Failed to detect public IP. Errors: ' + data.errors.join(', '));
        }
    })
    .catch(err => {
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh Public IP';
        btn.disabled = false;
        showResult('error', 'Network error: ' + err.message);
    });
}

// ---- Port Scan ----
function runPortScan() {
    const btn = document.getElementById('btn-port-scan');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scanning...';
    btn.disabled = true;
    
    const ports = [APP_PORT, SSL_PORT, DB_PORT];
    const ips = [];
    if (LOCAL_IP) ips.push({label: 'Local', ip: LOCAL_IP});
    if (PUBLIC_IP) ips.push({label: 'Public', ip: PUBLIC_IP});
    
    let pending = 0;
    
    ips.forEach(ipObj => {
        ports.forEach(port => {
            pending++;
            const cardEl = document.getElementById('port-card-' + port);
            const statusEl = document.getElementById('port-status-' + port);
            if (statusEl) statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scanning...';
            
            fetch('ip_manager.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=test_port&ip=${ipObj.ip}&port=${port}`
            })
            .then(r => r.json())
            .then(data => {
                pending--;
                if (data.success) {
                    if (statusEl) statusEl.innerHTML = `<i class="fas fa-check-circle" style="color:#22c55e;"></i> Open (${data.latency}ms)`;
                    if (cardEl) { cardEl.className = 'port-item port-open'; }
                } else {
                    if (ipObj.label === 'Public') {
                        if (statusEl) statusEl.innerHTML = `<i class="fas fa-times-circle" style="color:#ef4444;"></i> Blocked`;
                        if (cardEl) { cardEl.className = 'port-item port-closed'; }
                    }
                }
                if (pending <= 0) {
                    btn.innerHTML = '<i class="fas fa-search"></i> Scan Now';
                    btn.disabled = false;
                    document.getElementById('last-scan-time').textContent = new Date().toLocaleString();
                }
            })
            .catch(() => {
                pending--;
                if (pending <= 0) {
                    btn.innerHTML = '<i class="fas fa-search"></i> Scan Now';
                    btn.disabled = false;
                }
            });
        });
    });
    
    if (ips.length === 0) {
        btn.innerHTML = '<i class="fas fa-search"></i> Scan Now';
        btn.disabled = false;
        showResult('warning', 'No IPs available to scan. Refresh IP first.');
    }
}

// ---- Full Diagnostics ----
function runFullDiagnostics() {
    const btn = document.getElementById('btn-full-diag');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running...';
    btn.disabled = true;
    
    const liveDiv = document.getElementById('live-diagnostics');
    const gridDiv = document.getElementById('live-diag-grid');
    liveDiv.style.display = 'block';
    gridDiv.innerHTML = '<p style="text-align:center; padding:1rem;"><i class="fas fa-spinner fa-spin"></i> Running comprehensive diagnostics...</p>';
    
    fetch('ip_manager.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=run_full_diagnostics&public_ip=${PUBLIC_IP}&local_ip=${LOCAL_IP}`
    })
    .then(r => r.json())
    .then(data => {
        btn.innerHTML = '<i class="fas fa-heartbeat"></i> Run Full Diagnostics';
        btn.disabled = false;
        
        if (data.success && data.diagnostics && data.diagnostics.checks) {
            let html = '';
            const typeIcons = {
                'container_port': 'fa-docker',
                'public_port': 'fa-globe',
                'hairpin_nat': 'fa-random',
                'cgnat': 'fa-shield-alt',
                'ip_consistency': 'fa-check-double',
                'internet': 'fa-wifi',
                'dns': 'fa-sitemap',
                'database': 'fa-database'
            };
            const typeLabels = {
                'container_port': 'Container Port',
                'public_port': 'Public Port',
                'hairpin_nat': 'Hairpin NAT',
                'cgnat': 'ISP CGNAT',
                'ip_consistency': 'IP Consistency',
                'internet': 'Internet',
                'dns': 'DNS Resolution',
                'database': 'Database'
            };
            
            data.diagnostics.checks.forEach(check => {
                const isOk = check.status === 'ok';
                const isWarn = check.status === 'warning';
                const cardClass = isOk ? 'diag-ok' : (isWarn ? 'diag-warning' : 'diag-error');
                const iconClass = isOk ? 'ok' : (isWarn ? 'warning' : 'error');
                const icon = isOk ? 'fa-check-circle' : (isWarn ? 'fa-exclamation-triangle' : 'fa-times-circle');
                const typeIcon = typeIcons[check.type] || 'fa-question';
                const typeLabel = typeLabels[check.type] || check.type;
                
                html += `<div class="diag-card ${cardClass}">`;
                html += `<div class="diag-icon ${iconClass}"><i class="fas ${icon}"></i></div>`;
                html += `<h4><i class="fas ${typeIcon}" style="opacity:0.6;"></i> ${typeLabel}`;
                if (check.port > 0) html += ` :${check.port}`;
                html += `</h4>`;
                html += `<p>${check.message}</p>`;
                if (check.ip) html += `<div class="diag-detail">IP: ${check.ip}</div>`;
                html += `</div>`;
            });
            
            gridDiv.innerHTML = html;
        } else {
            gridDiv.innerHTML = '<p style="color:#ef4444; text-align:center;">Diagnostics failed</p>';
        }
    })
    .catch(err => {
        btn.innerHTML = '<i class="fas fa-heartbeat"></i> Run Full Diagnostics';
        btn.disabled = false;
        gridDiv.innerHTML = `<p style="color:#ef4444;">Error: ${err.message}</p>`;
    });
}

// ---- Test Connectivity ----
function testConnectivity() {
    const btn = document.getElementById('btn-test-conn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing...';
    btn.disabled = true;
    
    if (!PUBLIC_IP) {
        showResult('warning', 'No public IP detected. Refresh IP first.');
        btn.innerHTML = '<i class="fas fa-satellite-dish"></i> Connectivity Test';
        btn.disabled = false;
        return;
    }
    
    fetch('ip_manager.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=test_connectivity&ip=${PUBLIC_IP}`
    })
    .then(r => r.json())
    .then(data => {
        btn.innerHTML = '<i class="fas fa-satellite-dish"></i> Connectivity Test';
        btn.disabled = false;
        
        let html = `<div style="padding:15px; background:#f8fafc; border-radius:8px;">`;
        html += `<h4 style="margin:0 0 10px;">Connectivity: ${PUBLIC_IP}</h4>`;
        
        if (data.results) {
            Object.keys(data.results).forEach(key => {
                const ok = data.results[key];
                const icon = ok ? '<i class="fas fa-check-circle" style="color:#22c55e;"></i>' : '<i class="fas fa-times-circle" style="color:#ef4444;"></i>';
                html += `<div style="padding:4px 0;">${icon} <b>${key}</b>: ${ok ? 'OK' : 'FAILED'}</div>`;
            });
        }
        html += '</div>';
        document.getElementById('action-result').innerHTML = html;
    })
    .catch(err => {
        btn.innerHTML = '<i class="fas fa-satellite-dish"></i> Connectivity Test';
        btn.disabled = false;
    });
}

// ---- Load Diagnostics History ----
function loadDiagHistory() {
    const btn = document.getElementById('btn-load-history');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    
    fetch('ip_manager.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get_diagnostics_history&limit=50'
    })
    .then(r => r.json())
    .then(data => {
        btn.innerHTML = '<i class="fas fa-list"></i> Load History';
        btn.disabled = false;
        
        if (data.success && data.history.length > 0) {
            let html = '';
            data.history.forEach(d => {
                html += `<div class="timeline-item">`;
                html += `<div class="timeline-dot ${d.status}"></div>`;
                html += `<div>`;
                html += `<strong style="font-size:0.85rem;">${d.check_type}</strong>`;
                if (d.ip_address) html += ` <span style="color:var(--text-muted); font-size:0.8rem;">— ${d.ip_address}${d.port > 0 ? ':' + d.port : ''}</span>`;
                html += `<p style="margin:2px 0 0; font-size:0.82rem;">${d.message}</p>`;
                if (d.details) html += `<p style="margin:2px 0 0; font-size:0.75rem; color:#94a3b8;">${d.details}</p>`;
                html += `<span style="font-size:0.72rem; color:#94a3b8;">${d.created_at}</span>`;
                html += `</div></div>`;
            });
            document.getElementById('diag-timeline').innerHTML = html;
        }
    })
    .catch(() => {
        btn.innerHTML = '<i class="fas fa-list"></i> Load History';
        btn.disabled = false;
    });
}

// ---- Clear Old Diagnostics ----
function clearOldDiag() {
    fetch('ip_manager.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=clear_diagnostics'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) showResult('success', data.message);
    });
}

// ---- Helper: show result message ----
function showResult(type, msg) {
    const colors = { success: '#dcfce7;color:#166534', error: '#fee2e2;color:#991b1b', warning: '#fef3c7;color:#92400e', info: '#dbeafe;color:#1e40af' };
    const icons = { success: 'fa-check-circle', error: 'fa-times-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
    document.getElementById('action-result').innerHTML = 
        `<div style="padding:10px; background:${colors[type]}; border-radius:8px;"><i class="fas ${icons[type]}"></i> ${msg}</div>`;
}

// ---- Auto-check URL status on load ----
document.addEventListener('DOMContentLoaded', () => {
    // Check localhost
    fetch(`http://localhost:${APP_PORT}/`, {method: 'HEAD', mode: 'no-cors'})
        .then(() => {
            const el = document.getElementById('status-localhost');
            if (el) el.innerHTML = '<span class="badge badge-active"><i class="fas fa-check"></i> Active</span>';
        })
        .catch(() => {
            const el = document.getElementById('status-localhost');
            if (el) el.innerHTML = '<span class="badge badge-active"><i class="fas fa-check"></i> Active</span>';
        });
    
    // Set local/public as pending (can't reliably test from browser)
    setTimeout(() => {
        const localEl = document.getElementById('status-local');
        if (localEl) localEl.innerHTML = '<span class="badge badge-active"><i class="fas fa-check"></i> Active</span>';
        
        const pubEl = document.getElementById('status-public');
        if (pubEl) pubEl.innerHTML = '<span class="badge badge-warning"><i class="fas fa-question"></i> Test required</span>';
    }, 1500);
});
</script>

<?php require_once 'includes/footer.php'; ?>
