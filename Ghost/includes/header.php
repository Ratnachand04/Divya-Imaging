<?php
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['platform_admin', 'developer'], true)) {
    header("Location: ../login.php"); exit(); 
}
require_once __DIR__ . '/../../includes/db_connect.php';

// Check developer mode status for indicator
$dev_mode_active = false;
$dev_check = $conn->query("SELECT setting_value FROM developer_settings WHERE setting_key='developer_mode'");
if ($dev_check && $row = $dev_check->fetch_row()) {
    $dev_mode_active = ($row[0] === 'true');
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Platform Console</title>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/ghost.css?v=<?php echo time(); ?>">
</head>
<body>
<nav class="dev-nav">
    <a href="dashboard.php" class="brand"><i class="fas fa-layer-group"></i> Platform Console</a>
    <?php if ($dev_mode_active): ?>
    <span style="background:#22c55e; color:white; padding:2px 8px; border-radius:4px; font-size:0.7rem; font-weight:600; animation: pulse 2s infinite;">DEV ON</span>
    <?php endif; ?>
    <div style="flex:1;"></div>
    <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Dashboard</a>
    <a href="developer_mode.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'developer_mode.php' ? 'active' : ''; ?>"><i class="fas fa-code"></i> Dev Mode</a>
    <a href="file_manager.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'file_manager.php' ? 'active' : ''; ?>"><i class="fas fa-folder-open"></i> Files</a>
    <a href="ip_manager.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'ip_manager.php' ? 'active' : ''; ?>"><i class="fas fa-network-wired"></i> Network</a>
    <a href="messages.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'active' : ''; ?>"><i class="fas fa-bullhorn"></i> Messages</a>
    <a href="manage_database.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_database.php' ? 'active' : ''; ?>"><i class="fas fa-database"></i> Database</a>
    <a href="data_backup.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'data_backup.php' ? 'active' : ''; ?>"><i class="fas fa-archive"></i> Backups</a>
    <a href="audit_log.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'audit_log.php' ? 'active' : ''; ?>"><i class="fas fa-shield-alt"></i> Audit</a>
    <a href="error_logs.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'error_logs.php' ? 'active' : ''; ?>"><i class="fas fa-exclamation-triangle"></i> Errors</a>
    <a href="users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Users</a>
    <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
</nav>
<style>
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}
</style>
<div class="container">
