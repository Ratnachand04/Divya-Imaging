<?php
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php'; // For logging

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'])) {
    $request_id = (int)$_POST['request_id'];

    // Update the request status to 'rejected'
    $update_stmt = $conn->prepare("UPDATE bill_edit_requests SET status = 'rejected' WHERE id = ? AND status = 'pending'");
    $update_stmt->bind_param("i", $request_id);
    $update_stmt->execute();

    if ($update_stmt->affected_rows > 0) {
        // Log the rejection action
        $details = "Manager ({$_SESSION['username']}) rejected Bill Edit Request #{$request_id}.";
        log_system_action($conn, 'EDIT_REQUEST_REJECTED', $request_id, $details);
        $_SESSION['feedback'] = "<div class='success-banner'>Request #{$request_id} has been rejected.</div>";
    } else {
        $_SESSION['feedback'] = "<div class='error-banner'>Could not reject request #{$request_id}. It might have already been processed.</div>";
    }
    $update_stmt->close();

} else {
    $_SESSION['feedback'] = "<div class='error-banner'>Invalid request method.</div>";
}

header("Location: requests.php");
exit();
?>