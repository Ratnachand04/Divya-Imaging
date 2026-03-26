<?php
require_once 'includes/header.php';

// Stats
$user_count = $conn->query("SELECT count(*) FROM users")->fetch_row()[0];
$error_count = $conn->query("SELECT count(*) FROM error_logs")->fetch_row()[0];
$active_msgs = $conn->query("SELECT count(*) FROM site_messages WHERE is_active=1")->fetch_row()[0];
$audit_count = $conn->query("SELECT count(*) FROM system_audit_log")->fetch_row()[0];

// Developer settings
$settings = [];
$res = $conn->query("SELECT setting_key, setting_value FROM developer_settings");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}
$dev_mode = ($settings['developer_mode'] ?? 'false') === 'true';
$public_ip = $settings['public_ip'] ?? '';
$local_ip = $settings['local_ip'] ?? '';
$last_ip_check = $settings['last_ip_check'] ?? 'Never';
$ip_error = $settings['ip_last_error'] ?? '';
$app_port = getenv('APP_PORT') ?: '8081';
?>

<!-- Developer Mode Banner -->
<?php if ($dev_mode): ?>
<div class="card" style="border-left: 5px solid var(--success); background: #f0fdf4;">
    <div style="display:flex; align-items:center; gap:1rem;">
        <i class="fas fa-code" style="font-size:1.5rem; color:var(--success);"></i>
        <div>
            <h3 style="margin:0; color:#166534;">Developer Mode is ACTIVE</h3>
            <p style="margin:0.25rem 0 0; color:#15803d; font-size:0.9rem;">File changes apply immediately. OPcache disabled.</p>
        </div>
        <div style="flex:1;"></div>
        <a href="developer_mode.php" class="btn btn-success"><i class="fas fa-cog"></i> Manage</a>
    </div>
</div>
<?php endif; ?>

<!-- IP Error Alert -->
<?php if ($ip_error): ?>
<div class="card" style="border-left: 5px solid var(--danger); background: #fef2f2;">
    <div style="display:flex; align-items:center; gap:1rem;">
        <i class="fas fa-exclamation-triangle" style="font-size:1.5rem; color:var(--danger);"></i>
        <div>
            <h3 style="margin:0; color:#991b1b;">Public IP Error</h3>
            <p style="margin:0.25rem 0 0; color:#b91c1c; font-size:0.9rem;"><?php echo htmlspecialchars($ip_error); ?></p>
        </div>
        <div style="flex:1;"></div>
        <a href="ip_manager.php" class="btn btn-danger"><i class="fas fa-network-wired"></i> Fix</a>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h2>Welcome, Developer</h2>
    <p style="margin-bottom:1rem; color:var(--text-muted);">System Overview</p>
    <div class="stats-grid">
        <a href="users.php" class="stat-card stat-primary">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-content">
                <h3><?php echo $user_count; ?></h3>
                <p>Total Users</p>
            </div>
            <div class="stat-arrow"><i class="fas fa-arrow-right"></i></div>
        </a>
        <a href="error_logs.php" class="stat-card stat-danger">
            <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-content">
                <h3><?php echo $error_count; ?></h3>
                <p>Error Logs</p>
            </div>
            <div class="stat-arrow"><i class="fas fa-arrow-right"></i></div>
        </a>
        <a href="messages.php" class="stat-card stat-success">
            <div class="stat-icon"><i class="fas fa-bullhorn"></i></div>
            <div class="stat-content">
                <h3><?php echo $active_msgs; ?></h3>
                <p>Active Messages</p>
            </div>
            <div class="stat-arrow"><i class="fas fa-arrow-right"></i></div>
        </a>
        <a href="audit_log.php" class="stat-card stat-warning">
            <div class="stat-icon"><i class="fas fa-shield-alt"></i></div>
            <div class="stat-content">
                <h3><?php echo $audit_count; ?></h3>
                <p>Audit Entries</p>
            </div>
            <div class="stat-arrow"><i class="fas fa-arrow-right"></i></div>
        </a>
    </div>
</div>

<!-- Network Status Card -->
<div class="card">
    <h3><i class="fas fa-network-wired"></i> Network Status</h3>
    <div class="stats-grid" style="margin-bottom:1rem;">
        <div class="stat-card" style="border-color: var(--primary);">
            <div class="stat-icon" style="background-color: #dbeafe; color: var(--primary);"><i class="fas fa-globe"></i></div>
            <div class="stat-content">
                <h3 style="font-size:1rem;"><?php echo $public_ip ?: 'Not detected'; ?></h3>
                <p>Public IP</p>
            </div>
        </div>
        <div class="stat-card" style="border-color: var(--success);">
            <div class="stat-icon" style="background-color: #dcfce7; color: var(--success);"><i class="fas fa-home"></i></div>
            <div class="stat-content">
                <h3 style="font-size:1rem;"><?php echo $local_ip ?: 'Not detected'; ?></h3>
                <p>Local IP</p>
            </div>
        </div>
        <div class="stat-card" style="border-color: #f59e0b;">
            <div class="stat-icon" style="background-color: #fef3c7; color: #d97706;"><i class="fas fa-plug"></i></div>
            <div class="stat-content">
                <h3 style="font-size:1rem;"><?php echo $app_port; ?> / 8443 / 3301</h3>
                <p>Active Ports</p>
            </div>
        </div>
    </div>
    <div style="font-size:0.85rem; color:var(--text-muted);">
        <i class="fas fa-clock"></i> Last IP check: <?php echo $last_ip_check; ?>
        &nbsp;|&nbsp;
        <a href="ip_manager.php" style="color:var(--primary);"><i class="fas fa-external-link-alt"></i> Full network manager</a>
    </div>
</div>

<div class="card">
    <h3>Quick Actions</h3>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="developer_mode.php" class="btn btn-<?php echo $dev_mode ? 'danger' : 'success'; ?>"><i class="fas fa-code"></i> Dev Mode: <?php echo $dev_mode ? 'ON' : 'OFF'; ?></a>
        <a href="file_manager.php" class="btn btn-primary"><i class="fas fa-folder-open"></i> File Manager</a>
        <a href="ip_manager.php" class="btn btn-primary"><i class="fas fa-network-wired"></i> Network</a>
        <a href="messages.php" class="btn btn-primary"><i class="fas fa-plus"></i> Post Message</a>
        <a href="manage_database.php" class="btn btn-success"><i class="fas fa-terminal"></i> Database</a>
        <a href="data_backup.php" class="btn btn-primary"><i class="fas fa-archive"></i> Data Backup</a>
        <a href="error_logs.php" class="btn btn-danger"><i class="fas fa-bug"></i> View Errors</a>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
