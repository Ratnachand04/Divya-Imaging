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

/**
 * Ensures patient uid schema exists and is enforced.
 *
 * @param mysqli $conn The database connection object.
 */
function ensure_patient_registration_schema(mysqli $conn) {
    static $is_checked = false;

    if ($is_checked) {
        return;
    }

    // Migrate old registration_id column to uid if it still exists
    $old_col = $conn->query("SHOW COLUMNS FROM patients LIKE 'registration_id'");
    if ($old_col && $old_col->num_rows > 0) {
        $conn->query("ALTER TABLE patients CHANGE COLUMN registration_id uid VARCHAR(12) NULL");
        // Drop old unique key if present then recreate under new name
        $conn->query("ALTER TABLE patients DROP INDEX uniq_patient_registration_id");
    }
    if ($old_col instanceof mysqli_result) {
        $old_col->free();
    }

    $column_result = $conn->query("SHOW COLUMNS FROM patients LIKE 'uid'");
    if ($column_result && $column_result->num_rows === 0) {
        if (!$conn->query("ALTER TABLE patients ADD COLUMN uid VARCHAR(12) NULL AFTER id")) {
            throw new Exception('Unable to add patients.uid column: ' . $conn->error);
        }
    }
    if ($column_result instanceof mysqli_result) {
        $column_result->free();
    }

    if (!$conn->query("UPDATE patients SET uid = CONCAT('DC', YEAR(created_at), LPAD(id, 4, '0')) WHERE uid IS NULL OR uid = ''")) {
        throw new Exception('Unable to backfill patient UIDs: ' . $conn->error);
    }

    $index_result = $conn->query("SHOW INDEX FROM patients WHERE Key_name = 'uniq_patient_uid'");
    if ($index_result && $index_result->num_rows === 0) {
        if (!$conn->query("ALTER TABLE patients ADD UNIQUE KEY uniq_patient_uid (uid)")) {
            throw new Exception('Unable to create unique index on patients.uid: ' . $conn->error);
        }
    }
    if ($index_result instanceof mysqli_result) {
        $index_result->free();
    }

    if (!$conn->query("ALTER TABLE patients MODIFY uid VARCHAR(12) NOT NULL")) {
        throw new Exception('Unable to enforce NOT NULL on patients.uid: ' . $conn->error);
    }

    $is_checked = true;
}

/**
 * Generates next patient UID in DCYYYYNNNN format.
 *
 * @param mysqli $conn The database connection object.
 * @return string UID like DC20260001.
 */
function generate_patient_uid(mysqli $conn) {
    ensure_patient_registration_schema($conn);

    $year = date('Y');
    $prefix = 'DC' . $year;

    $stmt = $conn->prepare("SELECT uid FROM patients WHERE uid LIKE CONCAT(?, '%') ORDER BY uid DESC LIMIT 1 FOR UPDATE");
    if (!$stmt) {
        throw new Exception('Unable to prepare uid query: ' . $conn->error);
    }

    $stmt->bind_param('s', $prefix);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception('Unable to fetch latest uid: ' . $err);
    }

    $result = $stmt->get_result();
    $latest = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $next_number = 1;
    if ($latest && !empty($latest['uid'])) {
        $last_number = (int)substr($latest['uid'], 6);
        $next_number = $last_number + 1;
    }

    return $prefix . str_pad((string)$next_number, 4, '0', STR_PAD_LEFT);
}
?>