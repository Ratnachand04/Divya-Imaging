<?php
$page_title = "Delete Bill";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php'; // For logging the action

ensure_bill_edit_request_workflow_schema($conn);

// Check if a bill ID was submitted via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['bill_id'])) {
    header("Location: analytics.php");
    exit();
}

$bill_id_to_delete = (int)$_POST['bill_id'];
$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$return_to = trim((string)($_POST['return_to'] ?? 'analytics'));

$redirect_url = 'analytics.php';
if ($return_to === 'request_details' && $request_id > 0) {
    $redirect_url = 'view_request_details.php?request_id=' . $request_id;
}

$feedback = '';

// Use a transaction to ensure all related data is deleted safely
$conn->begin_transaction();
try {
    $patient_id_for_deleted_bill = 0;
    $bill_lookup_stmt = $conn->prepare("SELECT patient_id FROM bills WHERE id = ? FOR UPDATE");
    if (!$bill_lookup_stmt) {
        throw new Exception("Failed to prepare bill lookup.");
    }
    $bill_lookup_stmt->bind_param('i', $bill_id_to_delete);
    $bill_lookup_stmt->execute();
    $bill_lookup_row = $bill_lookup_stmt->get_result()->fetch_assoc();
    $bill_lookup_stmt->close();

    if (!$bill_lookup_row) {
        throw new Exception("Bill not found or already deleted.");
    }
    $patient_id_for_deleted_bill = (int)($bill_lookup_row['patient_id'] ?? 0);

    $old_request_status = null;
    $existing_request_comment = '';
    if ($request_id > 0) {
        $request_stmt = $conn->prepare('SELECT status, manager_comment FROM bill_edit_requests WHERE id = ? FOR UPDATE');
        if ($request_stmt) {
            $request_stmt->bind_param('i', $request_id);
            $request_stmt->execute();
            $request_row = $request_stmt->get_result()->fetch_assoc();
            $request_stmt->close();
            if ($request_row) {
                $old_request_status = normalize_bill_edit_request_status($request_row['status'] ?? 'pending');
                $existing_request_comment = trim((string)($request_row['manager_comment'] ?? ''));
            }
        }
    }

    $stmt_history = $conn->prepare("DELETE FROM payment_history WHERE bill_id = ?");
    if ($stmt_history) {
        $stmt_history->bind_param('i', $bill_id_to_delete);
        $stmt_history->execute();
        $stmt_history->close();
    }

    $stmt_pkg = $conn->prepare("DELETE FROM bill_package_items WHERE bill_id = ?");
    if ($stmt_pkg) {
        $stmt_pkg->bind_param('i', $bill_id_to_delete);
        $stmt_pkg->execute();
        $stmt_pkg->close();
    }

    // 1. Delete from the log table first
    $stmt1 = $conn->prepare("DELETE FROM bill_edit_log WHERE bill_id = ?");
    $stmt1->bind_param("i", $bill_id_to_delete);
    $stmt1->execute();
    $stmt1->close();

    // 2. Delete the associated test items
    $stmt2 = $conn->prepare("DELETE FROM bill_items WHERE bill_id = ?");
    $stmt2->bind_param("i", $bill_id_to_delete);
    $stmt2->execute();
    $stmt2->close();

    // 3. Delete the main bill record
    $stmt3 = $conn->prepare("DELETE FROM bills WHERE id = ?");
    $stmt3->bind_param("i", $bill_id_to_delete);
    $stmt3->execute();
    
    if ($stmt3->affected_rows > 0) {
        $orphan_patient_deleted = false;
        if ($patient_id_for_deleted_bill > 0) {
            $remaining_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM bills WHERE patient_id = ? AND bill_status != 'Void'");
            if ($remaining_stmt) {
                $remaining_stmt->bind_param('i', $patient_id_for_deleted_bill);
                $remaining_stmt->execute();
                $remaining_total_row = $remaining_stmt->get_result()->fetch_assoc();
                $remaining_stmt->close();

                $remaining_total = (int)($remaining_total_row['total'] ?? 0);
                if ($remaining_total === 0) {
                    $delete_patient_stmt = $conn->prepare("DELETE FROM patients WHERE id = ?");
                    if ($delete_patient_stmt) {
                        $delete_patient_stmt->bind_param('i', $patient_id_for_deleted_bill);
                        $delete_patient_stmt->execute();
                        $orphan_patient_deleted = $delete_patient_stmt->affected_rows > 0;
                        $delete_patient_stmt->close();
                    }
                }
            }
        }

        // 4. Log this critical action
        $details = "Manager ({$_SESSION['username']}) permanently deleted Bill #{$bill_id_to_delete}.";
        if ($orphan_patient_deleted) {
            $details .= " Patient #{$patient_id_for_deleted_bill} removed because no active billing history remained.";
        }
        log_system_action($conn, 'BILL_DELETED', $bill_id_to_delete, $details);

        if ($request_id > 0) {
            $delete_comment = $existing_request_comment !== ''
                ? $existing_request_comment
                : 'Bill permanently deleted by manager from request workflow.';

            $request_update_stmt = $conn->prepare("UPDATE bill_edit_requests
                SET status = 'completed',
                    manager_comment = ?,
                    manager_unread = 0,
                    receptionist_unread = 1,
                    last_manager_action_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?");
            if ($request_update_stmt) {
                $request_update_stmt->bind_param('si', $delete_comment, $request_id);
                $request_update_stmt->execute();
                $request_update_stmt->close();
            }

            log_bill_edit_request_event(
                $conn,
                $request_id,
                'manager',
                $_SESSION['user_id'] ?? null,
                'completed_by_manager',
                $old_request_status,
                'completed',
                'Bill #' . $bill_id_to_delete . ' deleted permanently by manager.'
            );
        }

        // 5. If all deletions were successful, commit the changes
        $conn->commit();
        $feedback = "<div class='success-banner'>Bill #{$bill_id_to_delete} and all related records have been permanently deleted.</div>";
    } else {
        throw new Exception("Bill not found or already deleted.");
    }
    $stmt3->close();

} catch (Exception $e) {
    // If any step fails, roll back all changes
    $conn->rollback();
    $feedback = "<div class='error-banner'>Error deleting bill: " . $e->getMessage() . "</div>";
}

// Store feedback in session and redirect back to the analytics page
$_SESSION['feedback'] = $feedback;
header("Location: " . $redirect_url);
exit();
?>