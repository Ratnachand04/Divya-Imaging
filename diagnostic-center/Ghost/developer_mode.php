<?php
require_once 'includes/header.php';

// ---- Handle AJAX actions ----
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'toggle_dev_mode':
            $new_state = $_POST['state'] ?? 'false';
            $new_state = ($new_state === 'true') ? 'true' : 'false';
            
            $stmt = $conn->prepare("INSERT INTO developer_settings (setting_key, setting_value) VALUES ('developer_mode', ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
            $stmt->bind_param("ss", $new_state, $new_state);
            $stmt->execute();
            
            // Log in audit
            $log_msg = "Developer mode " . ($new_state === 'true' ? 'ENABLED' : 'DISABLED') . " by " . $_SESSION['username'];
            $conn->query("INSERT INTO system_audit_log (user_id, action, details, ip_address) VALUES (
                {$_SESSION['user_id']}, 'developer_mode_toggle', '$log_msg', '{$_SERVER['REMOTE_ADDR']}'
            )");
            
            // When turning ON: signal Apache to watch for changes
            if ($new_state === 'true') {
                // Create a flag file that entrypoint can check
                @file_put_contents('/tmp/dev_mode_active', '1');
                
                // Reset OPcache if available
                if (function_exists('opcache_reset')) {
                    opcache_reset();
                }
            } else {
                @unlink('/tmp/dev_mode_active');
            }
            
            echo json_encode(['success' => true, 'state' => $new_state]);
            exit;
            
        case 'hot_reload':
            // Force Apache graceful restart to pick up file changes
            $dev_mode = $conn->query("SELECT setting_value FROM developer_settings WHERE setting_key='developer_mode'")->fetch_row()[0] ?? 'false';
            
            if ($dev_mode !== 'true') {
                echo json_encode(['success' => false, 'error' => 'Developer mode must be ON to use hot reload']);
                exit;
            }
            
            // Clear OPcache
            if (function_exists('opcache_reset')) {
                opcache_reset();
                $opcache_cleared = true;
            } else {
                $opcache_cleared = false;
            }
            
            // Clear realpath cache
            clearstatcache(true);
            
            // Signal Apache for graceful reload
            $reload_result = @shell_exec('apache2ctl graceful 2>&1');
            
            // Update timestamp
            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("INSERT INTO developer_settings (setting_key, setting_value) VALUES ('last_reload_time', ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
            $stmt->bind_param("ss", $now, $now);
            $stmt->execute();
            
            echo json_encode([
                'success' => true,
                'opcache_cleared' => $opcache_cleared,
                'reload_output' => trim($reload_result ?? ''),
                'timestamp' => $now
            ]);
            exit;
            
        case 'get_status':
            $status = [];
            $res = $conn->query("SELECT setting_key, setting_value, updated_at FROM developer_settings");
            while ($row = $res->fetch_assoc()) {
                $status[$row['setting_key']] = $row['setting_value'];
            }
            
            // OPcache status
            $opcache = [];
            if (function_exists('opcache_get_status')) {
                $oc = opcache_get_status(false);
                if ($oc) {
                    $opcache = [
                        'enabled' => $oc['opcache_enabled'] ?? false,
                        'cached_scripts' => $oc['opcache_statistics']['num_cached_scripts'] ?? 0,
                        'memory_used' => round(($oc['memory_usage']['used_memory'] ?? 0) / 1024 / 1024, 2) . ' MB',
                        'hit_rate' => round($oc['opcache_statistics']['opcache_hit_rate'] ?? 0, 1) . '%'
                    ];
                }
            }
            
            $status['opcache'] = $opcache;
            $status['php_version'] = phpversion();
            $status['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
            
            echo json_encode(['success' => true, 'status' => $status]);
            exit;
            
        case 'clear_opcache':
            if (function_exists('opcache_reset')) {
                opcache_reset();
                echo json_encode(['success' => true, 'message' => 'OPcache cleared']);
            } else {
                echo json_encode(['success' => false, 'error' => 'OPcache not available']);
            }
            exit;
    }
}

// ---- Load settings ----
$settings = [];
$res = $conn->query("SELECT setting_key, setting_value FROM developer_settings");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

$dev_mode = ($settings['developer_mode'] ?? 'false') === 'true';
$last_reload = $settings['last_reload_time'] ?? 'Never';
$hot_reload = ($settings['hot_reload'] ?? 'false') === 'true';
?>

<div class="card">
    <h2><i class="fas fa-code"></i> Developer Mode</h2>
    <p style="color:var(--text-muted); margin-bottom:1.5rem;">
        Control live file editing. When <strong>ON</strong>, file changes apply immediately (OPcache disabled).
        When <strong>OFF</strong>, changes are cached and require container restart.
    </p>
    
    <!-- Main Toggle -->
    <div style="display:flex; align-items:center; gap:2rem; padding:2rem; background:<?php echo $dev_mode ? '#dcfce7' : '#f1f5f9'; ?>; border-radius:1rem; margin-bottom:1.5rem; transition: background 0.3s;">
        <div style="flex:1;">
            <h3 style="margin:0; font-size:1.5rem;" id="dev-mode-label">
                <i class="fas fa-<?php echo $dev_mode ? 'toggle-on' : 'toggle-off'; ?>" id="dev-mode-icon" style="color:<?php echo $dev_mode ? 'var(--success)' : '#94a3b8'; ?>; font-size:2rem;"></i>
                Developer Mode is <span id="dev-mode-text"><?php echo $dev_mode ? 'ON' : 'OFF'; ?></span>
            </h3>
            <p style="margin:0.5rem 0 0; color:var(--text-muted);" id="dev-mode-desc">
                <?php echo $dev_mode ? 'File changes will apply immediately. OPcache is disabled.' : 'File changes are cached. Enable to start live editing.'; ?>
            </p>
        </div>
        <button class="btn <?php echo $dev_mode ? 'btn-danger' : 'btn-success'; ?>" id="btn-toggle-dev" onclick="toggleDevMode()" style="padding:0.75rem 2rem; font-size:1rem;">
            <i class="fas fa-power-off"></i> <?php echo $dev_mode ? 'Turn OFF' : 'Turn ON'; ?>
        </button>
    </div>
</div>

<!-- Hot Reload Panel (only when dev mode is ON) -->
<div class="card" id="hot-reload-panel" style="<?php echo $dev_mode ? '' : 'opacity:0.5; pointer-events:none;'; ?>">
    <h3><i class="fas fa-sync-alt"></i> Hot Reload</h3>
    <p style="color:var(--text-muted); margin-bottom:1rem;">Force reload all PHP files and Apache config. Use after making file changes.</p>
    
    <div style="display:flex; gap:10px; align-items:center;">
        <button onclick="hotReload()" class="btn btn-primary" id="btn-hot-reload">
            <i class="fas fa-redo"></i> Hot Reload Now
        </button>
        <button onclick="clearOpcache()" class="btn btn-success" id="btn-clear-cache">
            <i class="fas fa-broom"></i> Clear OPcache
        </button>
        <span style="color:var(--text-muted); font-size:0.85rem;">
            Last reload: <span id="last-reload"><?php echo $last_reload; ?></span>
        </span>
    </div>
    <div id="reload-result" style="margin-top:1rem;"></div>
</div>

<!-- System Status -->
<div class="card">
    <h3><i class="fas fa-info-circle"></i> System Status</h3>
    <div id="system-status">
        <button onclick="refreshStatus()" class="btn btn-primary btn-sm"><i class="fas fa-sync"></i> Refresh Status</button>
    </div>
    <div id="status-details" style="margin-top:1rem;"></div>
</div>

<!-- Apache Coexistence Panel -->
<div class="card" style="border-left:4px solid #0ea5e9;">
    <h3><i class="fas fa-server"></i> Apache Coexistence</h3>
    <p style="color:var(--text-muted); margin-bottom:1rem;">
        This Docker container runs its <strong>own Apache instance</strong> on separate ports, so it will 
        <strong>never conflict</strong> with your system Apache 2.4 installation.
    </p>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem; margin-bottom:1rem;">
        <div style="padding:1rem; background:#f0f9ff; border-radius:0.75rem; border:1px solid #bae6fd;">
            <h4 style="margin:0 0 0.5rem; color:#0c4a6e;"><i class="fas fa-docker"></i> Docker Apache (this container)</h4>
            <table style="width:100%; font-size:0.85rem;">
                <tr><td style="font-weight:600;">HTTP</td><td>Port <strong>8081</strong> (host) &rarr; 80 (internal)</td></tr>
                <tr><td style="font-weight:600;">HTTPS</td><td>Port <strong>8443</strong> (host) &rarr; 443 (internal)</td></tr>
                <tr><td style="font-weight:600;">Status</td><td><span class="badge badge-active">Running</span></td></tr>
            </table>
        </div>
        <div style="padding:1rem; background:#fef3c7; border-radius:0.75rem; border:1px solid #fcd34d;">
            <h4 style="margin:0 0 0.5rem; color:#92400e;"><i class="fas fa-desktop"></i> System Apache 2.4</h4>
            <table style="width:100%; font-size:0.85rem;">
                <tr><td style="font-weight:600;">HTTP</td><td>Port <strong>80</strong> (standard)</td></tr>
                <tr><td style="font-weight:600;">HTTPS</td><td>Port <strong>443</strong> (standard)</td></tr>
                <tr><td style="font-weight:600;">Conflict</td><td><span class="badge badge-active">None</span> — separate ports</td></tr>
            </table>
        </div>
    </div>
    <div style="padding:0.75rem; background:#dcfce7; border-radius:0.5rem; color:#166534; font-size:0.85rem;">
        <i class="fas fa-check-circle"></i> <strong>No port conflict:</strong> Docker uses 8081/8443, system Apache uses 80/443. Both can run simultaneously.
    </div>
</div>

<!-- Recent File Changes -->
<div class="card">
    <h3><i class="fas fa-history"></i> Recent File Changes</h3>
    <p style="color:var(--text-muted); margin-bottom:1rem;">Last file operations logged by Ghost Developer Console.</p>
    <div id="recent-changes">
        <button onclick="loadRecentChanges()" class="btn btn-primary btn-sm"><i class="fas fa-sync"></i> Load Changes</button>
    </div>
</div>

<!-- How It Works -->
<div class="card">
    <h3><i class="fas fa-question-circle"></i> How Developer Mode Works</h3>
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:1.5rem;">
        <div style="padding:1rem; background:#dbeafe; border-radius:0.75rem;">
            <h4 style="margin:0 0 0.5rem; color:#1e40af;"><i class="fas fa-toggle-on"></i> When ON</h4>
            <ul style="margin:0; padding-left:1.5rem; color:#1e3a5f; font-size:0.9rem;">
                <li>PHP OPcache is <strong>disabled</strong></li>
                <li>File changes reflect <strong>immediately</strong></li>
                <li>Realpath cache cleared on each request</li>
                <li>Hot Reload available for Apache restart</li>
                <li>Slightly slower performance (no caching)</li>
            </ul>
        </div>
        <div style="padding:1rem; background:#f1f5f9; border-radius:0.75rem;">
            <h4 style="margin:0 0 0.5rem; color:#475569;"><i class="fas fa-toggle-off"></i> When OFF</h4>
            <ul style="margin:0; padding-left:1.5rem; color:#64748b; font-size:0.9rem;">
                <li>PHP OPcache is <strong>enabled</strong></li>
                <li>File changes <strong>cached</strong> for 60s+</li>
                <li>Aggressive caching for performance</li>
                <li>Changes require container restart</li>
                <li>Best performance (production mode)</li>
            </ul>
        </div>
    </div>
</div>

<script>
function toggleDevMode() {
    const btn = document.getElementById('btn-toggle-dev');
    const currentState = document.getElementById('dev-mode-text').textContent === 'ON';
    const newState = !currentState;
    
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Switching...';
    btn.disabled = true;
    
    fetch('developer_mode.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=toggle_dev_mode&state=${newState}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const isOn = data.state === 'true';
            
            // Update UI
            document.getElementById('dev-mode-text').textContent = isOn ? 'ON' : 'OFF';
            document.getElementById('dev-mode-icon').className = `fas fa-${isOn ? 'toggle-on' : 'toggle-off'}`;
            document.getElementById('dev-mode-icon').style.color = isOn ? 'var(--success)' : '#94a3b8';
            document.getElementById('dev-mode-desc').textContent = isOn 
                ? 'File changes will apply immediately. OPcache is disabled.' 
                : 'File changes are cached. Enable to start live editing.';
            
            btn.className = `btn ${isOn ? 'btn-danger' : 'btn-success'}`;
            btn.innerHTML = `<i class="fas fa-power-off"></i> Turn ${isOn ? 'OFF' : 'ON'}`;
            btn.disabled = false;
            
            // Toggle card background
            btn.closest('.card').querySelector('div[style*="background"]').style.background = isOn ? '#dcfce7' : '#f1f5f9';
            
            // Toggle hot reload panel
            const hotPanel = document.getElementById('hot-reload-panel');
            hotPanel.style.opacity = isOn ? '1' : '0.5';
            hotPanel.style.pointerEvents = isOn ? 'auto' : 'none';
            
            // If turning ON, auto-reload
            if (isOn) {
                setTimeout(() => hotReload(), 500);
            }
        }
    })
    .catch(err => {
        btn.innerHTML = '<i class="fas fa-power-off"></i> Retry';
        btn.disabled = false;
        alert('Error: ' + err.message);
    });
}

function hotReload() {
    const btn = document.getElementById('btn-hot-reload');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reloading...';
    btn.disabled = true;
    
    fetch('developer_mode.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=hot_reload'
    })
    .then(r => r.json())
    .then(data => {
        btn.innerHTML = '<i class="fas fa-redo"></i> Hot Reload Now';
        btn.disabled = false;
        
        const el = document.getElementById('reload-result');
        if (data.success) {
            document.getElementById('last-reload').textContent = data.timestamp;
            el.innerHTML = '<div style="padding:10px; background:#dcfce7; border-radius:8px; color:#166534;">' +
                '<i class="fas fa-check-circle"></i> Reload complete! OPcache: ' + (data.opcache_cleared ? 'Cleared' : 'N/A') +
                (data.reload_output ? ' | Apache: ' + data.reload_output : '') + '</div>';
        } else {
            el.innerHTML = '<div style="padding:10px; background:#fee2e2; border-radius:8px; color:#991b1b;">' +
                '<i class="fas fa-times-circle"></i> ' + (data.error || 'Reload failed') + '</div>';
        }
    });
}

function clearOpcache() {
    fetch('developer_mode.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=clear_opcache'
    })
    .then(r => r.json())
    .then(data => {
        const el = document.getElementById('reload-result');
        el.innerHTML = '<div style="padding:10px; background:' + (data.success ? '#dcfce7' : '#fee2e2') + 
            '; border-radius:8px; color:' + (data.success ? '#166534' : '#991b1b') + ';">' +
            '<i class="fas fa-' + (data.success ? 'check' : 'times') + '-circle"></i> ' + 
            (data.message || data.error) + '</div>';
    });
}

function refreshStatus() {
    fetch('developer_mode.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get_status'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            let html = '<div class="table-responsive"><table>';
            html += '<thead><tr><th>Setting</th><th>Value</th></tr></thead><tbody>';
            
            const s = data.status;
            const rows = [
                ['Developer Mode', s.developer_mode === 'true' ? '<span class="badge badge-active">ON</span>' : '<span class="badge badge-inactive">OFF</span>'],
                ['PHP Version', s.php_version || '-'],
                ['Server', s.server_software || '-'],
                ['Public IP', s.public_ip || 'Not detected'],
                ['Local IP', s.local_ip || 'Not detected'],
                ['Last IP Check', s.last_ip_check || 'Never'],
                ['Last Reload', s.last_reload_time || 'Never']
            ];
            
            if (s.opcache) {
                rows.push(['OPcache Enabled', s.opcache.enabled ? '<span class="badge badge-active">YES</span>' : '<span class="badge badge-danger">NO</span>']);
                rows.push(['Cached Scripts', s.opcache.cached_scripts]);
                rows.push(['Memory Used', s.opcache.memory_used]);
                rows.push(['Hit Rate', s.opcache.hit_rate]);
            }
            
            rows.forEach(r => {
                html += `<tr><td style="font-weight:600;">${r[0]}</td><td>${r[1]}</td></tr>`;
            });
            
            html += '</tbody></table></div>';
            document.getElementById('status-details').innerHTML = html;
        }
    });
}

// Auto-load status on page load
document.addEventListener('DOMContentLoaded', () => {
    refreshStatus();
    loadRecentChanges();
});

function loadRecentChanges() {
    const el = document.getElementById('recent-changes');
    el.innerHTML = '<div style="text-align:center; padding:1rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    
    // Use a simple SQL query via the database manager (but we'll do a direct fetch here)
    fetch('manage_database.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=run_sql&sql=${encodeURIComponent("SELECT action, details, ip_address, created_at FROM system_audit_log WHERE action IN ('file_edit','file_create','file_delete','file_rename','file_upload','dir_create','db_insert','db_update','db_delete','db_truncate','db_drop','db_create_table','db_import','sql_execute') ORDER BY id DESC LIMIT 20")}`
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.results || data.results.length === 0) {
            el.innerHTML = '<div style="color:var(--text-muted); padding:1rem;">No recent changes found</div>';
            return;
        }
        
        const rs = data.results[0];
        if (!rs.rows || rs.rows.length === 0) {
            el.innerHTML = '<div style="color:var(--text-muted); padding:1rem;">No recent changes found</div>';
            return;
        }
        
        let html = '<div class="table-responsive"><table><thead><tr><th>Action</th><th>Details</th><th>IP</th><th>Time</th></tr></thead><tbody>';
        rs.rows.forEach(row => {
            const actionColors = {
                'file_edit': '#2563eb', 'file_create': '#16a34a', 'file_delete': '#dc2626',
                'file_rename': '#d97706', 'file_upload': '#7c3aed', 'dir_create': '#16a34a',
                'db_insert': '#16a34a', 'db_update': '#2563eb', 'db_delete': '#dc2626',
                'db_truncate': '#dc2626', 'db_drop': '#dc2626', 'db_create_table': '#16a34a',
                'sql_execute': '#6366f1'
            };
            const color = actionColors[row.action] || '#64748b';
            html += `<tr>
                <td><span style="color:${color}; font-weight:600; font-size:0.8rem;">${row.action}</span></td>
                <td style="font-size:0.85rem; max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${row.details || ''}">${row.details || '-'}</td>
                <td style="font-size:0.8rem; color:var(--text-muted);">${row.ip_address || '-'}</td>
                <td style="font-size:0.8rem; color:var(--text-muted); white-space:nowrap;">${row.created_at || '-'}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        el.innerHTML = html;
    })
    .catch(() => {
        el.innerHTML = '<div style="color:var(--text-muted); padding:1rem;">Could not load changes (database may be unavailable)</div>';
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
