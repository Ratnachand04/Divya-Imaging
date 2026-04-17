<?php
$page_title = "Log Expense";
// The required role for this page is now "manager"
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$error_message = '';
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $expense_type = trim($_POST['expense_type']);
    $amount = (float)$_POST['amount'];
    $status = trim($_POST['status']);
    // The accountant_id is now the logged-in manager's ID
    $accountant_id = $_SESSION['user_id'];
    $proof_path = null;

    if (isset($_FILES['proof']) && $_FILES['proof']['error'] == 0) {
        try {
            $proof_path_meta = data_storage_expense_proof_directory($expense_type);
            $target_dir = rtrim($proof_path_meta['absolute_path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $storage_dir = rtrim($proof_path_meta['storage_path'], '/');
        } catch (Throwable $e) {
            $error_message = 'Unable to prepare expense proof directory.';
            $target_dir = '';
            $storage_dir = '';
        }

        if ($target_dir !== '' && $storage_dir !== '') {
            $file_extension = pathinfo($_FILES["proof"]["name"], PATHINFO_EXTENSION);
            $target_file = $target_dir . uniqid('expense_') . '.' . $file_extension;
            $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
            if (in_array(strtolower($file_extension), $allowed_types) && $_FILES["proof"]["size"] < 5000000) {
                if (move_uploaded_file($_FILES["proof"]["tmp_name"], $target_file)) {
                    $proof_path = $storage_dir . '/' . basename($target_file);
                    if (function_exists('data_storage_copy_absolute_file_to_mirror')) {
                        data_storage_copy_absolute_file_to_mirror($target_file);
                    }
                } else {
                    $error_message = "Sorry, there was an error uploading your file.";
                }
            } else {
                $error_message = "Invalid file type or size (Max 5MB, JPG, PNG, PDF).";
            }
        }
    }

    if (empty($error_message)) {
        $stmt = $conn->prepare("INSERT INTO expenses (expense_type, amount, status, proof_path, accountant_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sdssi", $expense_type, $amount, $status, $proof_path, $accountant_id);
        if ($stmt->execute()) {
            $success_message = "Expense logged successfully!";
        } else {
            $error_message = "Database error: Could not log expense.";
        }
        $stmt->close();
    }
}
require_once '../includes/header.php';
?>
<div class="page-container">
    <div class="dashboard-header">
        <h1>Log New Expense</h1>
        <p>Record a new company expense.</p>
    </div>

    <div class="form-container" style="max-width: 600px;">
        <?php if ($error_message): ?><div class="error-banner"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
        <?php if ($success_message): ?><div class="success-banner"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
        
        <form action="log_expense.php" method="POST" enctype="multipart/form-data">
             <div class="filter-group" style="margin-bottom: 15px;">
                <label for="expense_type" style="display: block; margin-bottom: 5px; font-weight: 600;">Expense Type</label>
                <input type="text" id="expense_type" name="expense_type" required placeholder="e.g. Office Supplies, Rent, etc." style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
            </div>
            
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div class="filter-group" style="flex: 1;">
                    <label for="amount" style="display: block; margin-bottom: 5px; font-weight: 600;">Amount (₹)</label>
                    <input type="number" id="amount" name="amount" required step="0.01" min="0" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
                <div class="filter-group" style="flex: 1;">
                    <label for="status" style="display: block; margin-bottom: 5px; font-weight: 600;">Payment Status</label>
                    <select id="status" name="status" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                        <option value="Paid">Paid</option>
                        <option value="Due">Due</option>
                    </select>
                </div>
            </div>
            
            <div class="filter-group" style="margin-bottom: 25px;">
                <label for="proof" style="display: block; margin-bottom: 5px; font-weight: 600;">Upload Proof/Bill (Optional)</label>
                <input type="file" id="proof" name="proof" accept=".jpg,.jpeg,.png,.pdf" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; background-color: #f9f9f9;">
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn-submit">Log Expense</button>
                <a href="expenses.php" class="btn-cancel">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>