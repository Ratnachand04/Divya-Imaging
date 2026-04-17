<?php
$required_role = 'manager';
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_bill_edit_request_workflow_schema($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['feedback'] = "<div class='error-banner'>Invalid request method.</div>";
    header('Location: requests.php');
    exit();
}

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$action = trim((string)($_POST['action'] ?? ''));
$manager_comment = trim((string)($_POST['manager_comment'] ?? ''));
$open_editor = isset($_POST['open_editor']) && (string)$_POST['open_editor'] === '1';
$return_to = trim((string)($_POST['return_to'] ?? 'details'));
if ($return_to !== 'list') {
    $return_to = 'details';
}

if ($request_id <= 0 || !in_array($action, ['approve', 'reject', 'raise_query'], true)) {
    $_SESSION['feedback'] = "<div class='error-banner'>Invalid request action.</div>";
    header('Location: requests.php');
    exit();
}

$details_url = 'view_request_details.php?request_id=' . $request_id;

$conn->begin_transaction();

try {
    $select_stmt = $conn->prepare('SELECT id, bill_id, status, manager_comment FROM bill_edit_requests WHERE id = ? FOR UPDATE');
    if (!$select_stmt) {
        throw new Exception('Failed to load request: ' . $conn->error);
    }

    $select_stmt->bind_param('i', $request_id);
    $select_stmt->execute();
    $request_row = $select_stmt->get_result()->fetch_assoc();
    $select_stmt->close();

    if (!$request_row) {
        throw new Exception('Request not found.');
    }

    $current_status = normalize_bill_edit_request_status($request_row['status'] ?? 'pending');
    if (in_array($current_status, ['rejected', 'completed'], true)) {
        throw new Exception('This request has already been finalized and cannot be changed.');
    }

    if (!in_array($current_status, ['pending', 'query_raised'], true)) {
        throw new Exception('Only Pending or Query Raised requests can be processed by manager actions.');
    }

    if ($action === 'raise_query' && $manager_comment === '') {
        throw new Exception('Please add a query comment before raising a query.');
    }

    $next_status_map = [
        'approve' => 'approved',
        'reject' => 'rejected',
        'raise_query' => 'query_raised',
    ];
    $event_map = [
        'approve' => 'approved_by_manager',
        'reject' => 'rejected_by_manager',
        'raise_query' => 'query_raised_by_manager',
    ];

    $new_status = $next_status_map[$action];
    $event_key = $event_map[$action];

    if ($current_status === $new_status && $manager_comment === '') {
        throw new Exception('Request is already in this status.');
    }

    $effective_comment = $manager_comment;
    if ($effective_comment === '') {
        $effective_comment = trim((string)($request_row['manager_comment'] ?? ''));
    }

    $update_stmt = $conn->prepare('UPDATE bill_edit_requests
        SET status = ?,
            manager_comment = ?,
            manager_unread = 0,
            receptionist_unread = 1,
            last_manager_action_at = NOW(),
            updated_at = NOW()
        WHERE id = ?');
    if (!$update_stmt) {
        throw new Exception('Failed to update request: ' . $conn->error);
    }

    $update_stmt->bind_param('ssi', $new_status, $effective_comment, $request_id);
    if (!$update_stmt->execute()) {
        $err = $update_stmt->error;
        $update_stmt->close();
        throw new Exception('Failed to update request: ' . $err);
    }
    $update_stmt->close();

    log_bill_edit_request_event(
        $conn,
        $request_id,
        'manager',
        $_SESSION['user_id'] ?? null,
        $event_key,
        $current_status,
        $new_status,
        $manager_comment
    );

    $status_label = get_bill_edit_request_status_label($new_status);
    log_system_action(
        $conn,
        'BILL_EDIT_REQUEST_STATUS_UPDATED',
        $request_id,
        'Manager set bill edit request #' . $request_id . ' status to ' . $status_label
        . ($manager_comment !== '' ? ' with comment: ' . $manager_comment : '.')
    );

    $conn->commit();

    if ($action === 'approve' && $open_editor) {
        $_SESSION['feedback'] = "<div class='success-banner'>Request #{$request_id} approved. You can now edit the bill.</div>";
        $bill_id = (int)$request_row['bill_id'];
        header('Location: ../receptionist/generate_bill.php?edit_id=' . $bill_id . '&request_id=' . $request_id);
        exit();
    }

    $_SESSION['feedback'] = "<div class='success-banner'>Request #{$request_id} updated to {$status_label}.</div>";
    if ($return_to === 'list') {
        header('Location: requests.php');
    } else {
        header('Location: ' . $details_url);
    }
    exit();
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['feedback'] = "<div class='error-banner'>" . htmlspecialchars($e->getMessage()) . "</div>";
    if ($return_to === 'list') {
        header('Location: requests.php');
    } else {
        header('Location: ' . $details_url);
    }
    exit();
}
