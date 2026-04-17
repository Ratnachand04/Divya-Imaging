<?php
$page_title = "Edit Bill";
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_bill_edit_request_workflow_schema($conn);

$error_message = '';
$success_message = '';
$bill_id = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;

if (!$bill_id) {
    header("Location: bill_history.php");
    exit();
}

// --- CHECK IF BILL IS EDITABLE (within 12 hours) ---
$stmt_check = $conn->prepare("SELECT created_at FROM bills WHERE id = ? AND receptionist_id = ?");
$stmt_check->bind_param("ii", $bill_id, $_SESSION['user_id']);
$stmt_check->execute();
$check_result = $stmt_check->get_result();
if ($check_result->num_rows === 0) {
    die("Error: Bill not found or you don't have permission to edit it.");
}
$bill_data_check = $check_result->fetch_assoc();
$created_time = new DateTime($bill_data_check['created_at']);
$current_time = new DateTime();
$interval = $current_time->diff($created_time);
$hours_diff = $interval->h + ($interval->days * 24);
$stmt_check->close();

$active_request = null;
$active_request_status = '';
$active_request_stmt = $conn->prepare("SELECT id, status, manager_comment, receptionist_response, reason_for_change, created_at, updated_at
                                      FROM bill_edit_requests
                                      WHERE bill_id = ? AND receptionist_id = ?
                                        AND status IN ('pending', 'query_raised', 'approved')
                                      ORDER BY id DESC
                                      LIMIT 1");
if ($active_request_stmt) {
    $active_request_stmt->bind_param('ii', $bill_id, $_SESSION['user_id']);
    $active_request_stmt->execute();
    $active_request = $active_request_stmt->get_result()->fetch_assoc();
    $active_request_stmt->close();
}
if ($active_request) {
    $active_request_status = normalize_bill_edit_request_status($active_request['status'] ?? 'pending');
}

if ($hours_diff >= 12 && $active_request_status !== 'query_raised') {
    die("Error: This bill can no longer be edited. The 12-hour editing window has passed.");
}

// --- FORM SUBMISSION LOGIC FOR UPDATE ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $reason_for_change = trim($_POST['reason_for_change']);
    if (empty($reason_for_change)) {
        $error_message = "A reason for the change is mandatory.";
    } else {
        $receptionist_id = $_SESSION['user_id'];
        if ($active_request && in_array($active_request_status, ['pending', 'approved'], true)) {
            $status_label = get_bill_edit_request_status_label($active_request_status);
            $error_message = "An active request (#" . (int)$active_request['id'] . ") is already in {$status_label} status.";
        } elseif ($active_request && $active_request_status === 'query_raised') {
            $request_id = (int)$active_request['id'];
            $response_stmt = $conn->prepare("UPDATE bill_edit_requests
                SET status = 'pending',
                    reason_for_change = ?,
                    receptionist_response = ?,
                    manager_unread = 1,
                    receptionist_unread = 0,
                    last_receptionist_action_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?");
            if (!$response_stmt) {
                $error_message = "Failed to submit query response. Please try again.";
            } else {
                $response_stmt->bind_param('ssi', $reason_for_change, $reason_for_change, $request_id);
                if ($response_stmt->execute()) {
                    log_bill_edit_request_event(
                        $conn,
                        $request_id,
                        'receptionist',
                        $receptionist_id,
                        'query_responded',
                        'query_raised',
                        'pending',
                        $reason_for_change
                    );
                    $success_message = "Query response sent to manager. Request is now pending review again.";
                    header("Refresh:2; url=requests.php");
                } else {
                    $error_message = "Failed to submit query response. Please try again.";
                }
                $response_stmt->close();
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO bill_edit_requests
                (bill_id, receptionist_id, reason_for_change, status, manager_comment, receptionist_response, manager_unread, receptionist_unread, last_receptionist_action_at, created_at, updated_at)
                VALUES (?, ?, ?, 'pending', NULL, NULL, 1, 0, NOW(), NOW(), NOW())");
            if (!$stmt) {
                $error_message = "Failed to send request. Please try again.";
            } else {
                $stmt->bind_param("iis", $bill_id, $receptionist_id, $reason_for_change);
                if ($stmt->execute()) {
                    $new_request_id = (int)$stmt->insert_id;
                    log_bill_edit_request_event(
                        $conn,
                        $new_request_id,
                        'receptionist',
                        $receptionist_id,
                        'request_created',
                        null,
                        'pending',
                        $reason_for_change
                    );
                    $success_message = "Edit request sent to manager.";
                    header("Refresh:2; url=requests.php");
                } else {
                    $error_message = "Failed to send request. Please try again.";
                }
                $stmt->close();
            }
        }
    }
}

// --- FETCH CURRENT BILL DATA FOR FORM ---
$stmt = $conn->prepare(
    "SELECT b.*, p.name as patient_name, p.age, p.sex, p.address, p.city, p.mobile_number
     FROM bills b JOIN patients p ON b.patient_id = p.id
     WHERE b.id = ?"
);
$stmt->bind_param("i", $bill_id);
$stmt->execute();
$bill = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch currently selected tests for the bill
$current_items_stmt = $conn->query("SELECT t.id, t.main_test_name, t.sub_test_name, t.price FROM bill_items bi JOIN tests t ON bi.test_id = t.id WHERE bi.bill_id = $bill_id");
$current_tests = $current_items_stmt->fetch_all(MYSQLI_ASSOC);

// Fetch all doctors and tests for dropdowns
$doctors_result = $conn->query("SELECT id, doctor_name FROM referral_doctors WHERE is_active = 1 ORDER BY doctor_name ASC");
$tests_result = $conn->query("SELECT id, main_test_name, sub_test_name, price FROM tests ORDER BY main_test_name, sub_test_name ASC");
$tests_by_category = [];
while ($test = $tests_result->fetch_assoc()) {
    $tests_by_category[$test['main_test_name']][] = $test;
}

require_once '../includes/header.php';
?>
<div class="form-container">

    <h1>Editing Bill #<?php echo $bill_id; ?></h1>
    <p>Submit bill edit requests for manager approval and track response lifecycle.</p>

    <?php if ($active_request && $active_request_status === 'pending'): ?>
        <div class="success-banner">Request #<?php echo (int)$active_request['id']; ?> is currently pending manager review.</div>
    <?php elseif ($active_request && $active_request_status === 'approved'): ?>
        <div class="success-banner">Request #<?php echo (int)$active_request['id']; ?> is approved and being processed by manager.</div>
    <?php elseif ($active_request && $active_request_status === 'query_raised'): ?>
        <div class="error-banner">
            Manager raised a query on request #<?php echo (int)$active_request['id']; ?>.<br>
            <strong>Manager Query:</strong> <?php echo nl2br(htmlspecialchars((string)($active_request['manager_comment'] ?? 'Please provide clarification.'))); ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?><div class="error-banner"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="success-banner"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <form action="edit_bill.php?bill_id=<?php echo $bill_id; ?>" method="POST" id="bill-form">
        <fieldset>
            <legend>Reason for Modification</legend>
            <div class="form-group">
                <label for="reason_for_change">
                    <?php if ($active_request_status === 'query_raised'): ?>
                        Respond to manager query (required)
                    <?php else: ?>
                        Please provide a reason for editing this bill (required)
                    <?php endif; ?>
                </label>
                <textarea id="reason_for_change" name="reason_for_change" rows="3" required></textarea>
            </div>
        </fieldset>
        <button type="submit" class="btn-submit">
            <?php echo ($active_request_status === 'query_raised') ? 'Send Query Response' : 'Send Request'; ?>
        </button>
        <a href="requests.php" class="btn-cancel">View Requests</a>
    </form>
</div>

<?php require_once '../includes/footer.php'; ?>