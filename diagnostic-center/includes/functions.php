<?php
// includes/functions.php

/**
 * Logs a critical system action to the system_audit_log table.
 *
 * @param mysqli $conn The database connection object.
 * @param string $action_type A descriptor for the action (e.g., 'BILL_CREATED').
 * @param int|null $target_id The ID of the record that was affected.
 * @param string $details A human-readable description of the action.
 */
function log_system_action($conn, $action_type, $target_id = null, $details = '') {
    // Ensure session is started and user is logged in to get user details
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
        $user_id = $_SESSION['user_id'];
        $username = $_SESSION['username'];

        $stmt = $conn->prepare("INSERT INTO system_audit_log (user_id, username, action_type, target_id, details) VALUES (?, ?, ?, ?, ?)");
        // For target_id, if it's null, we pass null, otherwise we pass it as an integer.
        // PHP's bind_param doesn't handle nulls well with type 'i', so we check it.
        if ($target_id === null) {
            $stmt->bind_param("isss", $user_id, $username, $action_type, $details);
            // This is a workaround, re-binding with null target
            $stmt = $conn->prepare("INSERT INTO system_audit_log (user_id, username, action_type, target_id, details) VALUES (?, ?, ?, NULL, ?)");
            $stmt->bind_param("isss", $user_id, $username, $action_type, $details);

        } else {
             $stmt->bind_param("isiss", $user_id, $username, $action_type, $target_id, $details);
        }
       
        $stmt->execute();
        $stmt->close();
    }
}
?>