<?php
$page_title = "Delete Bill";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php'; // For logging the action

// Check if a bill ID was submitted via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['bill_id'])) {
    header("Location: analytics.php");
    exit();
}

$bill_id_to_delete = (int)$_POST['bill_id'];
$feedback = '';

// Use a transaction to ensure all related data is deleted safely
$conn->begin_transaction();
try {
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
        // 4. Log this critical action
        $details = "Manager ({$_SESSION['username']}) permanently deleted Bill #{$bill_id_to_delete}.";
        log_system_action($conn, 'BILL_DELETED', $bill_id_to_delete, $details);

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
header("Location: analytics.php");
exit();
?>