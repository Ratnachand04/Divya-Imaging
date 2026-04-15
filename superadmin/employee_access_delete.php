<?php
$required_role = 'superadmin';
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($userId <= 0) {
    header('Location: employee_access.php');
    exit;
}

$stmt = $conn->prepare("SELECT username, role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    $_SESSION['feedback'] = "<div class='error-banner'>User not found.</div>";
    header('Location: employee_access.php');
    exit;
}

if (in_array($user['role'], ['superadmin', 'platform_admin', 'developer'], true)) {
    $_SESSION['feedback'] = "<div class='error-banner'>You cannot delete this account.</div>";
    header('Location: employee_access.php');
    exit;
}

$del = $conn->prepare("DELETE FROM users WHERE id = ?");
$del->bind_param('i', $userId);
if ($del->execute()) {
    log_system_action($conn, 'USER_DELETED', $userId, "Superadmin deleted access account '{$user['username']}'.");
    $_SESSION['feedback'] = "<div class='success-banner'>Access account deleted.</div>";
} else {
    $_SESSION['feedback'] = "<div class='error-banner'>Could not delete access account.</div>";
}
$del->close();

header('Location: employee_access.php');
exit;
