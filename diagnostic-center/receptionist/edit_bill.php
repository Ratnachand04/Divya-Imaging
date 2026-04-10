<?php
$page_title = "Edit Bill";
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

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
if ($hours_diff >= 12) {
    die("Error: This bill can no longer be edited. The 12-hour editing window has passed.");
}
$stmt_check->close();

// --- FORM SUBMISSION LOGIC FOR UPDATE ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Only log the request for manager approval
    $reason_for_change = trim($_POST['reason_for_change']);
    if (empty($reason_for_change)) {
        $error_message = "A reason for the change is mandatory.";
    } else {
        $receptionist_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("INSERT INTO bill_edit_requests (bill_id, receptionist_id, reason_for_change, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
        $stmt->bind_param("iis", $bill_id, $receptionist_id, $reason_for_change);
        if ($stmt->execute()) {
            $success_message = "Edit request sent to manager.";
            header("Refresh:2; url=bill_history.php");
        } else {
            $error_message = "Failed to send request. Please try again.";
        }
        $stmt->close();
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
    <p>You are editing this bill within the 12-hour window. Any changes will be logged.</p>

    <?php if ($error_message): ?><div class="error-banner"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="success-banner"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <form action="edit_bill.php?bill_id=<?php echo $bill_id; ?>" method="POST" id="bill-form">
        <fieldset>
            <legend>Reason for Modification</legend>
            <div class="form-group">
                <label for="reason_for_change">Please provide a reason for editing this bill (Required)</label>
                <textarea id="reason_for_change" name="reason_for_change" rows="3" required></textarea>
            </div>
        </fieldset>
        <button type="submit" class="btn-submit">Send Request</button>
        <a href="bill_history.php" class="btn-cancel">Cancel</a>
    </form>
</div>

<?php require_once '../includes/footer.php'; ?>