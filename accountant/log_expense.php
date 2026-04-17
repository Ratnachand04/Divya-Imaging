<?php
$page_title = "Log Expense";
$required_role = "accountant";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$error_message = '';
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $expense_type = trim($_POST['expense_type']);
    $amount = (float)$_POST['amount'];
    $status = trim($_POST['status']);
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
    <div class="main-content page-container">
        <div class="dashboard-header">
            <div>
                <h1>Log New Expense</h1>
                <p>Record a new operational expense.</p>
            </div>
            <a href="view_expenses.php" class="btn-cancel" style="text-decoration:none;">View All Expenses</a>
        </div>
        
        <div class="insight-card" style="max-width: 600px; margin: 0 auto;">
            <h3 style="margin-bottom: 1.5rem;">Expense Details</h3>
            <?php if ($error_message): ?><div class="error-banner"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
            <?php if ($success_message): ?><div class="success-banner"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
            
            <form action="log_expense.php" method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="expense_type">Expense Type (e.g., "Office Supplies", "Rent")</label>
                        <input type="text" id="expense_type" name="expense_type" class="form-control" required style="width: 100%; padding: 0.6rem; border: 1px solid var(--border-light); border-radius: 8px;">
                    </div>
                    <div class="filter-group" style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                        <div class="form-group" style="flex: 1;">
                            <label for="amount">Amount (₹)</label>
                            <input type="number" id="amount" name="amount" class="form-control" required step="0.01" min="0" style="width: 100%; padding: 0.6rem; border: 1px solid var(--border-light); border-radius: 8px;">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label for="status">Payment Status</label>
                            <select id="status" name="status" class="form-control" required style="width: 100%; padding: 0.6rem; border: 1px solid var(--border-light); border-radius: 8px; background:white;">
                                <option value="Paid">Paid</option>
                                <option value="Due">Due</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 1.5rem;">
                        <label for="proof">Upload Proof/Bill (Optional)</label>
                        <input type="file" id="proof" name="proof" accept=".jpg,.jpeg,.png,.pdf" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-light); border-radius: 8px;">
                    </div>
                <button type="submit" class="btn-submit" style="width: 100%; padding: 0.75rem;">Log Expense</button>
            </form>
        </div>
    </div>
<?php require_once '../includes/footer.php'; ?>