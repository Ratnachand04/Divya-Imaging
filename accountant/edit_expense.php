<?php
$page_title = "Update Expense";
$required_role = "accountant";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$expenses_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'expenses', 'e') : '`expenses` e';

$error_message = '';
$expense_id = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expense_id = isset($_POST['expense_id']) ? (int) $_POST['expense_id'] : 0;
} else {
    $expense_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
}

if ($expense_id <= 0) {
    header('Location: view_expenses.php?status=error&message=' . urlencode('Invalid expense reference.'));
    exit();
}

$expense_stmt = $conn->prepare("SELECT e.id, e.expense_type, e.amount, e.status, e.proof_path, e.accountant_id, e.created_at FROM {$expenses_source} WHERE e.id = ?");
$expense_stmt->bind_param('i', $expense_id);
$expense_stmt->execute();
$expense_result = $expense_stmt->get_result();
$expense = $expense_result->fetch_assoc();
$expense_stmt->close();

if (!$expense) {
    header('Location: view_expenses.php?status=error&message=' . urlencode('Expense record not found.'));
    exit();
}

$form_status_value = $expense['status'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_status = $expense['status'];
    $proof_updated = false;
    $new_proof_path = $expense['proof_path'];
    $uploaded_file_path = null;

    $allowed_statuses = ['Paid', 'Due'];
    $posted_status = $_POST['status'] ?? $expense['status'];
    if (!in_array($posted_status, $allowed_statuses, true)) {
        $error_message = 'Invalid payment status selected.';
    } else {
        $new_status = $posted_status;
        $form_status_value = $posted_status;
    }

    if (empty($error_message) && isset($_FILES['proof']) && $_FILES['proof']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['proof']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
            $file_extension = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
            $max_size = 5 * 1024 * 1024; // 5 MB
            if (!in_array($file_extension, $allowed_types, true)) {
                $error_message = 'Only JPG, PNG, or PDF files are allowed.';
            } elseif ($_FILES['proof']['size'] > $max_size) {
                $error_message = 'Proof file must be under 5 MB.';
            } else {
                try {
                    $proof_path_meta = data_storage_expense_proof_directory($expense['expense_type'] ?? 'general');
                    $target_dir = rtrim($proof_path_meta['absolute_path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                    $storage_dir = rtrim($proof_path_meta['storage_path'], '/');
                } catch (Throwable $e) {
                    $target_dir = '';
                    $storage_dir = '';
                    $error_message = 'Unable to prepare expense proof directory.';
                }

                if ($target_dir !== '' && $storage_dir !== '') {
                    $target_file = $target_dir . uniqid('expense_', true) . '.' . $file_extension;
                    if (move_uploaded_file($_FILES['proof']['tmp_name'], $target_file)) {
                        $new_proof_path = $storage_dir . '/' . basename($target_file);
                        $proof_updated = true;
                        $uploaded_file_path = $new_proof_path;
                        if (function_exists('data_storage_copy_absolute_file_to_mirror')) {
                            data_storage_copy_absolute_file_to_mirror($target_file);
                        }
                    } else {
                        $error_message = 'Unable to upload the proof. Please retry.';
                    }
                }
            }
        } else {
            $error_message = 'File upload failed. Please retry.';
        }
    }

    if (empty($error_message)) {
        if ($proof_updated) {
            $update_stmt = $conn->prepare('UPDATE expenses SET status = ?, proof_path = ? WHERE id = ?');
            $update_stmt->bind_param('ssi', $new_status, $new_proof_path, $expense_id);
        } else {
            $update_stmt = $conn->prepare('UPDATE expenses SET status = ? WHERE id = ?');
            $update_stmt->bind_param('si', $new_status, $expense_id);
        }

        if ($update_stmt->execute()) {
            $details = sprintf(
                'Expense #%d (%s, ₹%0.2f) updated from %s to %s. Proof uploaded: %s',
                $expense['id'],
                $expense['expense_type'],
                $expense['amount'],
                $expense['status'],
                $new_status,
                $proof_updated ? 'Yes' : 'No'
            );
            log_system_action($conn, 'EXPENSE_STATUS_UPDATED', $expense['id'], $details);
            $update_stmt->close();
            header('Location: view_expenses.php?status=success&message=' . urlencode('Expense updated successfully.'));
            exit();
        }
        if ($proof_updated && $uploaded_file_path) {
            $relative_cleanup = ltrim(str_replace(['..\\', '../'], '', $uploaded_file_path), '/');
            $absolute_cleanup = realpath(dirname(__DIR__) . '/' . $relative_cleanup);
            if ($absolute_cleanup && file_exists($absolute_cleanup)) {
                @unlink($absolute_cleanup);
            }
        }
        $error_message = 'Database error: failed to update the expense.';
        $update_stmt->close();
    }
}

require_once '../includes/header.php';
$proof_url = '';
if (!empty($expense['proof_path'])) {
    $proof_url = 'download_proof.php?file=' . urlencode(ltrim(str_replace('../', '', $expense['proof_path']), '/'));
}
?>
<div class="main-content page-container">
    <div class="dashboard-header">
        <div>
            <h1>Update Expense</h1>
            <p>Review the expense details, mark it as paid, and attach payment proof.</p>
        </div>
        <a href="view_expenses.php" class="btn-cancel">Back to Expenses</a>
    </div>

    <div class="insight-card" style="max-width:720px;margin:0 auto;">
        <h3 style="margin-bottom:1rem;">Expense Overview</h3>
        <?php if (!empty($error_message)): ?>
        <div class="error-banner" style="margin-bottom:1rem;"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form action="edit_expense.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="expense_id" value="<?php echo $expense['id']; ?>">
            <div class="filter-group" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                <div class="form-group">
                    <label>Expense Type</label>
                    <input type="text" value="<?php echo htmlspecialchars($expense['expense_type']); ?>" readonly style="width:100%;padding:0.6rem;border:1px solid var(--border-light);border-radius:8px;background:var(--bg-muted,#f7f8fb);">
                </div>
                <div class="form-group">
                    <label>Amount (₹)</label>
                    <input type="text" value="<?php echo number_format($expense['amount'], 2); ?>" readonly style="width:100%;padding:0.6rem;border:1px solid var(--border-light);border-radius:8px;background:var(--bg-muted,#f7f8fb);">
                </div>
                <div class="form-group">
                    <label>Logged On</label>
                    <input type="text" value="<?php echo date('d M Y, h:i A', strtotime($expense['created_at'])); ?>" readonly style="width:100%;padding:0.6rem;border:1px solid var(--border-light);border-radius:8px;background:var(--bg-muted,#f7f8fb);">
                </div>
            </div>

            <div class="form-group" style="margin-top:1rem;">
                <label>Current Status</label>
                <div style="padding:0.6rem;border:1px solid var(--border-light);border-radius:8px;background:var(--bg-muted,#f7f8fb);font-weight:600;">
                    <?php echo htmlspecialchars($expense['status']); ?>
                </div>
            </div>

            <div class="form-group" style="margin-top:1rem;">
                <label>Update Payment Status</label>
                <select name="status" required style="width:100%;padding:0.6rem;border:1px solid var(--border-light);border-radius:8px;background:var(--bg-primary,#fff);">
                    <option value="Paid" <?php echo ($form_status_value === 'Paid') ? 'selected' : ''; ?>>Mark as Paid</option>
                    <option value="Due" <?php echo ($form_status_value === 'Due') ? 'selected' : ''; ?>>Mark as Due</option>
                </select>
                <small style="color:#666;display:block;margin-top:0.35rem;">Use this option to correct the payment state. Upload proof whenever marking an expense as Paid.</small>
            </div>

            <div class="form-group" style="margin-top:1rem;">
                <label for="proof">Upload Payment Proof (JPG, PNG, PDF, max 5 MB)</label>
                <?php if (!empty($proof_url)): ?>
                <p style="margin:0.4rem 0;font-size:0.9rem;">Current proof: <a href="<?php echo $proof_url; ?>" class="btn-action btn-view" target="_blank">Download</a></p>
                <?php endif; ?>
                <input type="file" id="proof" name="proof" accept=".jpg,.jpeg,.png,.pdf" style="width:100%;padding:0.5rem;border:1px solid var(--border-light);border-radius:8px;">
            </div>

            <button type="submit" class="btn-submit" style="margin-top:1.5rem;width:100%;max-width:280px;">Save Changes</button>
        </form>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
