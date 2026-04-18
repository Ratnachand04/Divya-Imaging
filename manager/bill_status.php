<?php
// manager/bill_status.php

$page_title = "Bill Status";
$required_role = "manager";

require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_bill_payment_split_columns($conn);

// --- 1. GET AND PREPARE FILTERS & PAGINATION ---
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$payment_status = isset($_GET['payment_status']) ? $_GET['payment_status'] : 'all';

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$records_per_page = 25;
$offset = ($page - 1) * $records_per_page;

// --- 2. BUILD DYNAMIC WHERE CLAUSE ---
$where_clauses = ["b.created_at BETWEEN ? AND ?"];
$end_date_for_query = $end_date . ' 23:59:59';
$params = [$start_date, $end_date_for_query];
$types = 'ss';

if ($payment_status !== 'all') {
    $where_clauses[] = "b.payment_status = ?";
    $params[] = $payment_status;
    $types .= 's';
}
$where_sql = " WHERE " . implode(' AND ', $where_clauses);

// --- 3. GET SUMMARY COUNTS (Full Paid, Partial Paid) ---
$summary_query = "SELECT 
                    SUM(CASE WHEN payment_status = 'Paid' THEN 1 ELSE 0 END) as full_paid_count,
                    SUM(CASE WHEN payment_status = 'Partial Paid' THEN 1 ELSE 0 END) as partial_paid_count
                  FROM bills b" . $where_sql;
$stmt_summary = $conn->prepare($summary_query);
$stmt_summary->bind_param($types, ...$params);
$stmt_summary->execute();
$summary_result = $stmt_summary->get_result()->fetch_assoc();
$stmt_summary->close();

$full_paid_count = $summary_result['full_paid_count'] ?? 0;
$partial_paid_count = $summary_result['partial_paid_count'] ?? 0;

// --- 4. GET TOTAL RECORD COUNT FOR PAGINATION ---
$count_query = "SELECT COUNT(b.id) as total FROM bills b" . $where_sql;
$stmt_count = $conn->prepare($count_query);
$stmt_count->bind_param($types, ...$params);
$stmt_count->execute();
$total_records = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);
$stmt_count->close();

// --- 5. GET PAGINATED DATA FOR THE TABLE ---
$data_query = "SELECT b.id, b.invoice_number, p.uid as patient_uid, p.name as patient_name, b.net_amount, b.payment_status, b.payment_mode, b.cash_amount, b.card_amount, b.upi_amount, b.other_amount, b.created_at
               FROM bills b
               JOIN patients p ON b.patient_id = p.id" . $where_sql . "
               ORDER BY b.created_at DESC
               LIMIT ? OFFSET ?";
$data_params = $params;
$data_types = $types . 'ii';
$data_params[] = $records_per_page;
$data_params[] = $offset;

$stmt_data = $conn->prepare($data_query);
$stmt_data->bind_param($data_types, ...$data_params);
$stmt_data->execute();
$bills_data = $stmt_data->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_data->close();

require_once '../includes/header.php';
?>

<div class="page-container">
    <div class="dashboard-header">
        <h1>Bill Status Overview</h1>
        <p>Monitor the payment status of all checks.</p>
    </div>

    <form method="GET" action="bill_status.php" class="filter-form compact-filters">
        <div class="filter-group">
            <label for="start_date">Start Date</label>
            <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>
        <div class="filter-group">
             <label for="end_date">End Date</label>
            <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>
        <div class="filter-group">
            <label for="payment_status">Payment Status</label>
            <select name="payment_status" id="payment_status">
                <option value="all" <?php if($payment_status == 'all') echo 'selected'; ?>>All</option>
                <option value="Paid" <?php if($payment_status == 'Paid') echo 'selected'; ?>>Full Paid</option>
                <option value="Partial Paid" <?php if($payment_status == 'Partial Paid') echo 'selected'; ?>>Partial Paid</option>
                <option value="Due" <?php if($payment_status == 'Due') echo 'selected'; ?>>Due</option>
            </select>
        </div>
        <div class="filter-actions">
            <a href="bill_status.php" class="btn-cancel">Reset</a>
            <button type="submit" class="btn-submit">Apply Filters</button>
        </div>
    </form>

    <div class="summary-cards">
        <div class="summary-card"><h3>Fully Paid Bills</h3><p><?php echo $full_paid_count; ?></p></div>
        <div class="summary-card"><h3>Partial Paid Bills</h3><p><?php echo $partial_paid_count; ?></p></div>
    </div>

    <div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Bill No.</th>
                <th>Patient ID</th>
                <th>Patient Name</th>
                <th>Net Amount</th>
                <th>Payment Status</th>
                <th>Payment Mode</th>
                <th>Timestamp</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($bills_data)): ?>
                <?php foreach($bills_data as $bill): ?>
                <tr>
                    <td><?php echo htmlspecialchars($bill['invoice_number']); ?></td>
                    <td><span style="font-size:0.82rem;color:#666;"><?php echo htmlspecialchars($bill['patient_uid'] ?? ''); ?></span></td>
                    <td><?php echo htmlspecialchars($bill['patient_name']); ?></td>
                    <td>₹ <?php echo number_format($bill['net_amount'], 2); ?></td>
                    <td><span class="status-<?php echo strtolower(str_replace(' ', '', $bill['payment_status'])); ?>"><?php echo htmlspecialchars($bill['payment_status']); ?></span></td>
                    <td><?php echo htmlspecialchars(format_payment_mode_display($bill)); ?></td>
                    <td><?php echo date('d-m-Y H:i A', strtotime($bill['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php echo render_unified_pagination('bill_status.php', (int)$page, (int)$total_pages, $_GET, 'Bill Status Pagination'); ?>
</div>

<?php require_once '../includes/footer.php'; ?>