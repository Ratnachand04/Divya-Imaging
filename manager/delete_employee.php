<?php
// FIX: The required role must be set to "manager"
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$feedback = '';
$user_id_to_delete = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$user_id_to_delete) {
    header("Location: manage_employees.php");
    exit();
}

// Get user info before deleting
$stmt_fetch = $conn->prepare("SELECT username, role FROM users WHERE id = ?");
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
    require_once '../includes/functions.php';
    $log_details = "Manager permanently deleted user '{$user['username']}' (ID: {$user_id_to_delete}).";
    log_system_action($conn, 'USER_DELETED', $user_id_to_delete, $log_details);

    // Delete the user
    $stmt_delete = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt_delete->bind_param("i", $user_id_to_delete);
    if ($stmt_delete->execute()) {
        $feedback = "<div class='success-banner'>User '{$user['username']}' was deleted successfully.</div>";
    } else {
        $feedback = "<div class='error-banner'>Error: Could not delete user.</div>";
    }
    $stmt_delete->close();
}

$_SESSION['feedback'] = $feedback;
header("Location: manage_employees.php");
exit();