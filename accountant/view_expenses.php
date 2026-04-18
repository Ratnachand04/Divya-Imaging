<?php
$page_title = "View Expenses";
$required_role = "accountant";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

$query = "SELECT e.id, e.expense_type, e.amount, e.status, e.created_at, e.proof_path, u.username as accountant_name FROM expenses e JOIN users u ON e.accountant_id = u.id WHERE e.created_at BETWEEN ? AND ? ORDER BY e.created_at DESC";
$stmt = $conn->prepare($query);
$end_date_for_query = $end_date . ' 23:59:59';
$stmt->bind_param("ss", $start_date, $end_date_for_query);
$stmt->execute();
$expenses_result = $stmt->get_result();

$total_expenses = 0;
$paid_expenses = 0;
$pending_expenses = 0;
$expenses_data = $expenses_result->fetch_all(MYSQLI_ASSOC);
foreach ($expenses_data as $expense) {
    $total_expenses += $expense['amount'];
    if ($expense['status'] === 'Paid') {
        $paid_expenses += $expense['amount'];
    } else {
        $pending_expenses += $expense['amount'];
    }
}
$feedback_status = $_GET['status'] ?? '';
$feedback_message = $_GET['message'] ?? '';
require_once '../includes/header.php';
?>
    <div class="main-content page-container">
        <?php if (!empty($feedback_message)): ?>
        <div class="<?php echo ($feedback_status === 'error') ? 'error-banner' : 'success-banner'; ?>" style="margin-bottom:1rem;">
            <?php echo htmlspecialchars($feedback_message); ?>
        </div>
        <?php endif; ?>
        <div class="dashboard-header">
            <div>
                <h1>Expense Records</h1>
                <p>View and manage all logged expenses.</p>
            </div>
            <a href="log_expense.php" class="btn-submit"><i class="fas fa-plus"></i> New Expense</a>
        </div>

        <form method="GET" action="view_expenses.php" class="filter-form compact-filters" style="margin-bottom: 1.5rem;">
            <div class="filter-group">
                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-submit">Filter</button>
            </div>
        </form>

        <div class="summary-cards" style="margin-bottom: 2rem;">
            <div class="summary-card">
                <h3>Total Expenses</h3>
                <p>₹ <?php echo number_format($total_expenses, 2); ?></p>
            </div>
            <div class="summary-card earnings">
                <h3>Paid Expenses</h3>
                <p>₹ <?php echo number_format($paid_expenses, 2); ?></p>
            </div>
            <div class="summary-card pending">
                <h3>Pending Payments</h3>
                <p>₹ <?php echo number_format($pending_expenses, 2); ?></p>
            </div>
        </div>

        <div class="insight-card">
            <h3 style="margin-bottom: 1rem;">Expense History</h3>
            <?php if (count($expenses_data) === 0): ?>
            <div class="no-data" style="padding:0.65rem 1rem;margin-bottom:0.75rem;border:1px dashed var(--border-light,#dcdfe6);border-radius:8px;color:var(--text-secondary,#666);">No expenses found for the selected period.</div>
            <?php endif; ?>
            <div class="table-responsive">
            <table class="data-table">
                <thead><tr><th>ID</th><th>Expense Type</th><th>Amount</th><th>Status</th><th>Date</th><th>Logged By</th><th>Proof</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (count($expenses_data) > 0): foreach($expenses_data as $expense): ?>
                    <tr>
                        <td><?php echo $expense['id']; ?></td>
                        <td><?php echo htmlspecialchars($expense['expense_type']); ?></td>
                        <td>₹ <?php echo number_format($expense['amount'], 2); ?></td>
                        <td><span class="status-<?php echo strtolower($expense['status']); ?>"><?php echo $expense['status']; ?></span></td>
                        <td><?php echo date('d-m-Y', strtotime($expense['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($expense['accountant_name']); ?></td>
                        <td>
                            <?php if (!empty($expense['proof_path'])):
                                // UPDATED: Link points to the new download script for zipping
                                $proof_url = 'download_proof.php?file=' . urlencode(ltrim(str_replace('../', '', $expense['proof_path']), '/'));
                            ?>
                            <a href="<?php echo $proof_url; ?>" class="btn-action btn-view">Download Proof</a>
                            <?php else: ?> N/A <?php endif; ?>
                        </td>
                        <td>
                            <div class="expense-action-row" style="display:flex;flex-direction:column;gap:0.3rem;">
                                <a href="edit_expense.php?id=<?php echo $expense['id']; ?>" class="btn-action btn-edit">Edit</a>
                                <button type="button" class="btn-action btn-delete show-delete-panel" data-target="delete-panel-<?php echo $expense['id']; ?>">Delete</button>
                            </div>
                            <div id="delete-panel-<?php echo $expense['id']; ?>" class="delete-panel" style="display:none;margin-top:0.5rem;">
                                <form action="delete_expense.php" method="POST" class="delete-expense-form" style="border:1px solid var(--border-light);padding:0.7rem;border-radius:8px;background:var(--bg-muted,#fafbff);">
                                    <input type="hidden" name="expense_id" value="<?php echo $expense['id']; ?>">
                                    <label for="delete-reason-<?php echo $expense['id']; ?>" style="display:block;font-size:0.85rem;margin-bottom:0.4rem;">Reason for deletion</label>
                                    <textarea id="delete-reason-<?php echo $expense['id']; ?>" name="delete_reason" rows="2" required style="width:100%;padding:0.5rem;border:1px solid var(--border-light);border-radius:6px;"></textarea>
                                    <div class="delete-panel-actions" style="display:flex;gap:0.5rem;margin-top:0.5rem;flex-wrap:wrap;">
                                        <button type="submit" class="btn-action btn-delete" style="flex:1;min-width:120px;">Confirm Delete</button>
                                        <button type="button" class="btn-cancel btn-sm cancel-delete-panel" data-target="delete-panel-<?php echo $expense['id']; ?>" style="flex:1;min-width:120px;">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const panelButtons = document.querySelectorAll('.show-delete-panel');
        const cancelButtons = document.querySelectorAll('.cancel-delete-panel');

        const hidePanel = (panelId) => {
            const panel = document.getElementById(panelId);
            if (panel) panel.style.display = 'none';
        };

        panelButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const panelId = button.dataset.target;
                if (!panelId) return;
                const panel = document.getElementById(panelId);
                if (panel) {
                    const isVisible = panel.style.display === 'block';
                    document.querySelectorAll('.delete-panel').forEach((item) => item.style.display = 'none');
                    panel.style.display = isVisible ? 'none' : 'block';
                }
            });
        });

        cancelButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const panelId = button.dataset.target;
                if (panelId) hidePanel(panelId);
            });
        });
    });
    </script>
<?php $stmt->close(); require_once '../includes/footer.php'; ?>