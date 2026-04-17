<?php
$required_role = 'superadmin';
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$users_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'users', 'u') : '`users` u';

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($userId <= 0) {
    header('Location: employee_access.php');
    exit;
}

$stmt = $conn->prepare("SELECT u.username, u.role, COALESCE(NULLIF(u.full_name, ''), '') AS full_name FROM {$users_source} WHERE u.id = ? LIMIT 1");
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

$allowedRoles = ['receptionist', 'accountant', 'writer', 'manager'];
if (!in_array((string)$user['role'], $allowedRoles, true) || (string)$user['full_name'] !== '') {
    $_SESSION['feedback'] = "<div class='error-banner'>This record is employee-details only and has no access credentials to delete.</div>";
    header('Location: employee_access.php');
    exit;
}

$delete_table = function_exists('table_scale_find_physical_table_by_id')
    ? table_scale_find_physical_table_by_id($conn, 'users', $userId)
    : 'users';
if (!$delete_table || (function_exists('table_scale_is_safe_identifier') && !table_scale_is_safe_identifier($delete_table))) {
    $delete_table = 'users';
}
$delete_table_sql = function_exists('table_scale_quote_identifier')
    ? table_scale_quote_identifier($delete_table)
    : '`' . str_replace('`', '', $delete_table) . '`';

$del = $conn->prepare("DELETE FROM {$delete_table_sql} WHERE id = ?");
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
