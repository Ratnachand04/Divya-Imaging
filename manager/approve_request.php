<?php
// manager/approve_request.php
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$bill_edit_requests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_edit_requests', 'r') : '`bill_edit_requests` r';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'])) {
    $request_id = (int)$_POST['request_id'];
    // Get the bill id from the request
    $stmt = $conn->prepare("SELECT r.bill_id FROM {$bill_edit_requests_source} WHERE r.id = ? AND r.status = 'pending'");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $bill_id = $row['bill_id'];
        // Mark request as approved
        $update = $conn->prepare("UPDATE bill_edit_requests SET status = 'approved' WHERE id = ?");
        $update->bind_param("i", $request_id);
        $update->execute();
        $update->close();
        // Redirect manager to bill edit page (manager version)
        header("Location: edit_bill.php?bill_id=" . $bill_id . "&request_id=" . $request_id);
        exit();
    } else {
        // Invalid or already processed request
        header("Location: requests.php?error=notfound");
        exit();
    }
}
header("Location: requests.php");
exit();
