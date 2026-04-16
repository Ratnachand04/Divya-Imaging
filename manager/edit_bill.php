<?php
// manager/edit_bill.php
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$bill_id = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;
$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

if (!$bill_id) {
    header("Location: requests.php");
    exit();
}

$error_message = '';
$success_message = '';

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
        // Mark request as completed
        if ($request_id) {
            $conn->query("UPDATE bill_edit_requests SET status='completed' WHERE id=" . (int)$request_id);
        }
        $success_message = "Bill updated and request marked as completed.";
        header("Refresh:2; url=requests.php");
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
        </fieldset>
        <button type="submit" class="btn-submit">Update Bill</button>
        <a href="requests.php" class="btn-cancel">Cancel</a>
    </form>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
