<?php
$page_title = "Update Payment";
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$error_message = '';
$bill_id = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;

if (!$bill_id) {
    header("Location: bill_history.php");
    exit();
}

// Fetch current bill data
$stmt = $conn->prepare("SELECT b.*, p.name as patient_name FROM bills b JOIN patients p ON b.patient_id = p.id WHERE b.id = ? AND b.receptionist_id = ?");
$stmt->bind_param("ii", $bill_id, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    header("Location: bill_history.php?error=Bill not found or permission denied.");
    exit();
}
$bill = $result->fetch_assoc();
$stmt->close();

if ($bill['payment_status'] === 'Paid') {
    header("Location: bill_history.php?error=This bill is already fully paid.");
    exit();
}


// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn->begin_transaction();
    try {
        $amount_now_paying = (float)$_POST['amount_now_paying'];
        if ($amount_now_paying <= 0) {
            throw new Exception("Amount must be positive.");
        }
        
        $new_amount_paid = $bill['amount_paid'] + $amount_now_paying;
        $new_balance_amount = $bill['net_amount'] - $new_amount_paid;
        
        if ($new_amount_paid > $bill['net_amount']) {
             throw new Exception("Amount paid cannot exceed the net amount.");
        }
        
        $new_payment_status = ($new_balance_amount <= 0) ? 'Paid' : 'Half Paid';
        if ($new_balance_amount < 0) $new_balance_amount = 0; // Just in case of float precision issues
        
        $update_stmt = $conn->prepare("UPDATE bills SET amount_paid = ?, balance_amount = ?, payment_status = ? WHERE id = ?");
        $update_stmt->bind_param("ddsi", $new_amount_paid, $new_balance_amount, $new_payment_status, $bill_id);
        $update_stmt->execute();
        $update_stmt->close();

        $conn->commit();
        require_once '../includes/functions.php';
        log_system_action($conn, 'PAYMENT_UPDATED', $bill_id, "Payment updated for Bill #{$bill_id}. New status: {$new_payment_status}.");
        header("Location: bill_history.php?success=Payment for Bill #{$bill_id} updated successfully.");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Update failed: " . $e->getMessage();
    }
}


require_once '../includes/header.php';
?>

<div class="form-container">
    <h1>Update Payment for Bill #<?php echo $bill_id; ?></h1>
    <p><strong>Patient:</strong> <?php echo htmlspecialchars($bill['patient_name']); ?></p>

    <?php if ($error_message): ?><div class="error-banner"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <div class="payment-summary">
        <div><strong>Net Amount:</strong><span>₹ <?php echo number_format($bill['net_amount'], 2); ?></span></div>
        <div><strong>Already Paid:</strong><span>₹ <?php echo number_format($bill['amount_paid'], 2); ?></span></div>
        <div class="balance"><strong>Balance Due:</strong><span>₹ <?php echo number_format($bill['balance_amount'], 2); ?></span></div>
    </div>

    <form action="update_payment.php?bill_id=<?php echo $bill_id; ?>" method="POST" id="update-payment-form">
        <fieldset>
            <legend>Enter Payment Details</legend>
            <div class="form-group">
                <label for="amount_now_paying">Amount Paying Now</label>
                <input type="number" name="amount_now_paying" id="amount_now_paying" step="0.01" min="0.01" max="<?php echo $bill['balance_amount']; ?>" required>
            </div>
             <div class="form-group">
                <label for="payment_mode">Payment Mode</label>
                <select id="payment_mode" name="payment_mode" required>
                    <option value="Cash">Cash</option>
                    <option value="Card">Card</option>
                    <option value="UPI">UPI</option>
                    <option value="Other">Other</option>
                </select>
            </div>
        </fieldset>
        
        <button type="submit" class="btn-submit">Update Payment</button>
        <a href="bill_history.php" class="btn-cancel">Cancel</a>
    </form>
</div>

<?php require_once '../includes/footer.php'; ?>
