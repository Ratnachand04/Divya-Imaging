<?php
$page_title = "View Expenses";
$required_role = "superadmin"; //
require_once '../includes/auth_check.php'; //
require_once '../includes/db_connect.php'; //

// --- Handle Filters with Session Persistence ---
$filter_key = 'expenditure_filters';

if (isset($_GET['reset'])) {
    unset($_SESSION[$filter_key]);
    header("Location: expenditure.php");
    exit();
}

if (isset($_GET['start_date'])) {
    $_SESSION[$filter_key] = [
        'start_date' => $_GET['start_date'],
        'end_date' => $_GET['end_date'],
        'expense_type' => $_GET['expense_type'] ?? 'all',
        'search' => $_GET['search'] ?? ''
    ];
}

$start_date = $_SESSION[$filter_key]['start_date'] ?? (isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01')); //
$end_date = $_SESSION[$filter_key]['end_date'] ?? (isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t')); //
$expense_type_filter = $_SESSION[$filter_key]['expense_type'] ?? (isset($_GET['expense_type']) ? trim($_GET['expense_type']) : 'all'); //
$search_term = $_SESSION[$filter_key]['search'] ?? (isset($_GET['search']) ? trim($_GET['search']) : ''); //

$end_date_for_query = $end_date . ' 23:59:59'; //
$query_conditions = ["e.created_at BETWEEN ? AND ?"]; //
$query_params = [$start_date, $end_date_for_query]; //
$query_types = 'ss'; //

if ($expense_type_filter !== 'all' && $expense_type_filter !== '') {
    $query_conditions[] = "e.expense_type = ?";
    $query_params[] = $expense_type_filter;
    $query_types .= 's';
}

if ($search_term !== '') {
    $search_like = '%' . $search_term . '%';
    $query_conditions[] = "(e.expense_type LIKE ? OR e.status LIKE ? OR u.username LIKE ? OR CAST(e.amount AS CHAR) LIKE ? OR DATE(e.created_at) LIKE ?)";
    $query_params = array_merge($query_params, array_fill(0, 5, $search_like));
    $query_types .= 'sssss';
}

$query = "SELECT e.id, e.expense_type, e.amount, e.status, e.created_at, e.proof_path, u.username as accountant_name FROM expenses e JOIN users u ON e.accountant_id = u.id WHERE " . implode(' AND ', $query_conditions) . " ORDER BY e.created_at DESC"; //
$stmt = $conn->prepare($query); //
if ($stmt === false) { die('Failed to prepare expenses query: ' . $conn->error); }
$stmt->bind_param($query_types, ...$query_params);
$stmt->execute(); //
$expenses_result = $stmt->get_result(); //

$expense_types_result = $conn->query("SELECT DISTINCT expense_type FROM expenses ORDER BY expense_type ASC");
$expense_types = $expense_types_result ? $expense_types_result->fetch_all(MYSQLI_ASSOC) : [];
if ($expense_types_result) { $expense_types_result->free(); }

// --- Calculate totals (unchanged) ---
$total_expenses = 0; //
$paid_expenses = 0; //
$pending_expenses = 0; //
$expenses_data = $expenses_result->fetch_all(MYSQLI_ASSOC); //
foreach ($expenses_data as $expense) { //
    $total_expenses += $expense['amount']; //
    if ($expense['status'] === 'Paid') { //
        $paid_expenses += $expense['amount']; //
    } else {
        $pending_expenses += $expense['amount']; //
    }
}
require_once '../includes/header.php'; //
?>
    <div class="main-content page-container">
        <div class="dashboard-header">
            <div>
                <h1>Expense Records</h1>
                <p class="text-muted">View and analyze all operational expenses.</p>
            </div>
            <a href="log_expense.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i> Log Expense</a>
        </div>

        <form method="GET" action="expenditure.php" class="filter-form compact-filters mb-4">
            <div class="quick-filters">
                <button type="button" class="btn-quick-date" data-range="today">Today</button>
                <button type="button" class="btn-quick-date" data-range="yesterday">Yesterday</button>
                <button type="button" class="btn-quick-date" data-range="this_week">Last 7 Days</button>
                <button type="button" class="btn-quick-date" data-range="this_month">This Month</button>
                <button type="button" class="btn-quick-date" data-range="last_month">Last Month</button>
                <button type="button" class="btn-quick-date" data-range="this_year">This Year</button>
            </div>
            <div class="filter-group">
                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <div class="form-group">
                    <label for="expense_type">Expense Type</label>
                    <select id="expense_type" name="expense_type" style="min-width: 150px;">
                        <option value="all">All Types</option>
                        <?php foreach($expense_types as $type_row): $type_value = $type_row['expense_type']; ?>
                            <option value="<?php echo htmlspecialchars($type_value); ?>" <?php if($expense_type_filter === $type_value) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($type_value); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Search handled by DataTables usually, but server side filtering here -->
                <div class="form-group">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" placeholder="Type, status..." value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-submit">Filter</button>
                <a href="?reset=1" class="btn-cancel">Reset</a>
            </div>
        </form>

        <div class="filter-info-bar">
            <i class="fas fa-calendar-alt"></i>
            <span>Showing expenses from <strong><?php echo date('d M Y', strtotime($start_date)); ?></strong> to <strong><?php echo date('d M Y', strtotime($end_date)); ?></strong></span>
        </div>

        <div class="summary-cards">
            <div class="summary-card">
                <h3><i class="fas fa-wallet" style="margin-right: 8px;"></i> Total Expenses</h3>
                <p>₹ <?php echo number_format($total_expenses, 2); ?></p>
            </div>
            <div class="summary-card earnings">
                <h3><i class="fas fa-check-circle" style="margin-right: 8px;"></i> Paid Expenses</h3>
                <p>₹ <?php echo number_format($paid_expenses, 2); ?></p>
            </div>
            <div class="summary-card pending">
                <h3><i class="fas fa-clock" style="margin-right: 8px;"></i> Pending Payments</h3>
                <p>₹ <?php echo number_format($pending_expenses, 2); ?></p>
            </div>
        </div>

        <div class="table-container">
            <h3 style="margin-bottom: 1.5rem;">Expense History</h3>
            <table id="expensesTable" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Expense Type</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Logged By</th>
                        <th>Proof</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($expenses_data) > 0): foreach($expenses_data as $expense): ?>
                    <tr>
                        <td>#<?php echo $expense['id']; ?></td>
                        <td><?php echo htmlspecialchars($expense['expense_type']); ?></td>
                        <td><strong>₹ <?php echo number_format($expense['amount'], 2); ?></strong></td>
                        <td>
                            <?php 
                                $statusClass = 'status-pending';
                                if($expense['status'] == 'Paid') $statusClass = 'status-paid';
                                elseif($expense['status'] == 'Pending') $statusClass = 'status-pending';
                                else $statusClass = 'status-partial';
                            ?>
                            <span class="<?php echo $statusClass; ?>"><?php echo $expense['status']; ?></span>
                        </td>
                        <td><?php echo date('d M Y', strtotime($expense['created_at'])); ?></td>
                        <td>
                            <?php echo htmlspecialchars($expense['accountant_name']); ?>
                        </td>
                        <td>
                            <?php if (!empty($expense['proof_path'])): ?>
                            <?php
                                // --- Construct correct relative URL for proof ---
                                $proof_url = $expense['proof_path'];
                            ?>
                            <a href="<?php echo $proof_url; ?>" target="_blank" class="btn-outline" style="padding: 2px 8px; font-size: 0.8rem;">View</a>
                            <?php else: ?> <span class="text-muted">-</span> <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script>
        $(document).ready(function() {
            $('#expensesTable').DataTable({
                "paging": true,
                "ordering": true,
                "info": true,
                "searching": true,
                "pageLength": 20,
                "lengthMenu": [10, 20, 50, 100],
                "language": {
                    "search": "Search expenses:",
                    "lengthMenu": "Show _MENU_ entries"
                }
            });
        });
    </script> <?php
$stmt->close(); //
require_once '../includes/footer.php'; //
?>