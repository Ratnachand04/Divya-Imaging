<?php
// FIX: The required role must be set to "manager"
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$users_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'users', 'u') : '`users` u';

$feedback = '';
$user_id_to_delete = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$user_id_to_delete) {
    header("Location: employees.php");
    exit();
}

// Get user info before deleting
$stmt_fetch = $conn->prepare("SELECT u.username, u.role FROM {$users_source} WHERE u.id = ?");
$stmt_fetch->bind_param("i", $user_id_to_delete);
$stmt_fetch->execute();
$user = $stmt_fetch->get_result()->fetch_assoc();
$stmt_fetch->close();

if (!$user) {
    $feedback = "<div class='error-banner'>Error: User not found.</div>";
} elseif ($user['role'] === 'superadmin' || $user['role'] === 'manager') {
    // Security: Prevent manager from deleting superadmins or other managers
    $feedback = "<div class='error-banner'>Error: You do not have permission to delete this user.</div>";
} else {
    // Log the deletion action
    $log_details = "Manager permanently deleted user '{$user['username']}' (ID: {$user_id_to_delete}).";
    log_system_action($conn, 'USER_DELETED', $user_id_to_delete, $log_details);

    $delete_table = function_exists('table_scale_find_physical_table_by_id')
        ? table_scale_find_physical_table_by_id($conn, 'users', $user_id_to_delete)
        : 'users';
    if (!$delete_table || (function_exists('table_scale_is_safe_identifier') && !table_scale_is_safe_identifier($delete_table))) {
        $delete_table = 'users';
    }
    $delete_table_sql = function_exists('table_scale_quote_identifier')
        ? table_scale_quote_identifier($delete_table)
        : '`' . str_replace('`', '', $delete_table) . '`';

    // Delete the user
    $stmt_delete = $conn->prepare("DELETE FROM {$delete_table_sql} WHERE id = ?");
    $stmt_delete->bind_param("i", $user_id_to_delete);
    if ($stmt_delete->execute()) {
        $feedback = "<div class='success-banner'>User '{$user['username']}' was deleted successfully.</div>";
    } else {
        $feedback = "<div class='error-banner'>Error: Could not delete user.</div>";
    }
    $stmt_delete->close();
}

$_SESSION['feedback'] = $feedback;
header("Location: employees.php");
exit();