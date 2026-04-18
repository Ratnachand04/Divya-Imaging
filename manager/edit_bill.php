<?php
// manager/edit_bill.php
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_bill_edit_request_workflow_schema($conn);

$bill_id = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;
$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

if (!$bill_id) {
    header("Location: requests.php");
    exit();
}

$error_message = '';
$success_message = '';

$linked_request = null;
$linked_request_status = '';
if ($request_id > 0) {
    $request_stmt = $conn->prepare('SELECT id, bill_id, status, manager_comment FROM bill_edit_requests WHERE id = ?');
    if (!$request_stmt) {
        $_SESSION['feedback'] = "<div class='error-banner'>Unable to load linked request.</div>";
        header('Location: requests.php');
        exit();
    }

    $request_stmt->bind_param('i', $request_id);
    $request_stmt->execute();
    $linked_request = $request_stmt->get_result()->fetch_assoc();
    $request_stmt->close();

    if (!$linked_request || (int)$linked_request['bill_id'] !== $bill_id) {
        $_SESSION['feedback'] = "<div class='error-banner'>Invalid request reference for this bill.</div>";
        header('Location: requests.php');
        exit();
    }

    $linked_request_status = normalize_bill_edit_request_status($linked_request['status'] ?? 'pending');
    if ($linked_request_status !== 'approved') {
        $_SESSION['feedback'] = "<div class='error-banner'>This request must be approved before editing the bill.</div>";
        header('Location: view_request_details.php?request_id=' . $request_id);
        exit();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gross_amount = (float)$_POST['gross_amount'];
    $discount = (float)$_POST['discount'];
    $net_amount = (float)$_POST['net_amount'];
    $payment_mode = trim($_POST['payment_mode']);
    $bill_status = ($net_amount == 0) ? 'Void' : 'Re-Billed';

    $stmt = $conn->prepare("UPDATE bills SET gross_amount=?, discount=?, net_amount=?, payment_mode=?, bill_status=? WHERE id=?");
    $stmt->bind_param("dddssi", $gross_amount, $discount, $net_amount, $payment_mode, $bill_status, $bill_id);
    if ($stmt->execute()) {
        // Mark request as completed and notify receptionist.
        if ($request_id > 0 && $linked_request) {
            $completion_note = trim((string)($_POST['completion_note'] ?? ''));
            $existing_comment = trim((string)($linked_request['manager_comment'] ?? ''));
            $final_comment = $completion_note !== '' ? $completion_note : $existing_comment;

            $complete_stmt = $conn->prepare("UPDATE bill_edit_requests
                SET status = 'completed',
                    manager_comment = ?,
                    manager_unread = 0,
                    receptionist_unread = 1,
                    last_manager_action_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?");
            if ($complete_stmt) {
                $complete_stmt->bind_param('si', $final_comment, $request_id);
                $complete_stmt->execute();
                $complete_stmt->close();
            }

            log_bill_edit_request_event(
                $conn,
                $request_id,
                'manager',
                $_SESSION['user_id'] ?? null,
                'completed_by_manager',
                $linked_request_status,
                'completed',
                $completion_note
            );

            log_system_action(
                $conn,
                'BILL_EDIT_REQUEST_COMPLETED',
                $request_id,
                'Manager completed bill edit request #' . $request_id . ' for bill #' . $bill_id
            );
        }
        $success_message = "Bill updated and request marked as completed.";
        if ($request_id > 0) {
            header('Refresh:2; url=view_request_details.php?request_id=' . $request_id);
        } else {
            header('Refresh:2; url=requests.php');
        }
    } else {
        $error_message = "Failed to update bill.";
    }
    $stmt->close();
}

// Fetch bill data
$stmt = $conn->prepare("SELECT * FROM bills WHERE id = ?");
$stmt->bind_param("i", $bill_id);
$stmt->execute();
$bill = $stmt->get_result()->fetch_assoc();
$stmt->close();

require_once '../includes/header.php';
?>
<div class="page-container">
    <div class="dashboard-header">
        <h1>Edit Bill #<?php echo $bill_id; ?></h1>
        <p>Update bill amounts and payment details.</p>
    </div>
    <?php if ($error_message): ?><div class="error-banner"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="success-banner"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
    <div class="form-container">
    <form action="edit_bill.php?bill_id=<?php echo $bill_id; ?>&request_id=<?php echo $request_id; ?>" method="POST">
        <fieldset>
            <legend>Billing Details</legend>
            <div class="form-row">
                <div class="form-group"><label for="gross_amount">Gross Amount</label><input type="text" id="gross_amount" name="gross_amount" required value="<?php echo htmlspecialchars($bill['gross_amount']); ?>"></div>
                <div class="form-group"><label for="discount">Discount (in amount)</label><input type="number" id="discount" name="discount" value="<?php echo htmlspecialchars($bill['discount']); ?>" step="0.01" min="0"></div>
                <div class="form-group"><label for="net_amount">Net Amount</label><input type="text" id="net_amount" name="net_amount" required value="<?php echo htmlspecialchars($bill['net_amount']); ?>"></div>
            </div>
            <div class="form-group">
                <label for="payment_mode">Payment Mode</label>
                <select id="payment_mode" name="payment_mode" required>
                    <option value="Cash" <?php if($bill['payment_mode'] == 'Cash') echo 'selected'; ?>>Cash</option>
                    <option value="Card" <?php if($bill['payment_mode'] == 'Card') echo 'selected'; ?>>Card</option>
                    <option value="UPI" <?php if($bill['payment_mode'] == 'UPI') echo 'selected'; ?>>UPI</option>
                    <option value="Cash + Card" <?php if($bill['payment_mode'] == 'Cash + Card') echo 'selected'; ?>>Cash + Card</option>
                    <option value="UPI + Cash" <?php if($bill['payment_mode'] == 'UPI + Cash') echo 'selected'; ?>>UPI + Cash</option>
                    <option value="Card + UPI" <?php if($bill['payment_mode'] == 'Card + UPI') echo 'selected'; ?>>Card + UPI</option>
                </select>
            </div>

            <?php if ($request_id > 0): ?>
            <div class="form-group">
                <label for="completion_note">Completion Note (optional)</label>
                <textarea id="completion_note" name="completion_note" rows="3" placeholder="Add closure remarks for receptionist visibility."></textarea>
            </div>
            <?php endif; ?>
        </fieldset>
        <button type="submit" class="btn-submit">Update Bill</button>
        <?php if ($request_id > 0): ?>
            <a href="view_request_details.php?request_id=<?php echo (int)$request_id; ?>" class="btn-cancel">Cancel</a>
        <?php else: ?>
            <a href="requests.php" class="btn-cancel">Cancel</a>
        <?php endif; ?>
    </form>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
