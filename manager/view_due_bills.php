<?php
$page_title = "Bill Status Report";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

// --- Handle Filters ---
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$all_dates = isset($_GET['all_dates']) && $_GET['all_dates'] === '1';
if ($all_dates) {
    $start_date = isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : '2000-01-01';
    $end_date = isset($_GET['end_date']) && $_GET['end_date'] !== '' ? $_GET['end_date'] : date('Y-m-d');
}
// Default to 'pending' to show today's completed bills + all other pending
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'pending';

// --- Build Query ---
$query = "SELECT 
            b.id, p.uid as patient_uid, p.name as patient_name, b.net_amount, b.amount_paid, b.balance_amount, 
            b.payment_status, b.created_at, b.updated_at, u.username as receptionist_name
          FROM bills b
          JOIN patients p ON b.patient_id = p.id
          JOIN users u ON b.receptionist_id = u.id
          WHERE b.bill_status != 'Void' AND DATE(b.created_at) BETWEEN ? AND ?";

$params = [$start_date, $end_date];
$types = 'ss';

// Add status filtering based on the dropdown selection
switch ($status_filter) {
    case 'pending':
        // Show all Due/Half Paid bills OR bills that were paid today.
        $query .= " AND (b.payment_status IN ('Due', 'Half Paid') OR (b.payment_status = 'Paid' AND DATE(b.updated_at) = CURDATE()))";
        break;
    case 'Half Paid':
    case 'Due':
        // Filter for specific pending statuses
        $query .= " AND b.payment_status = ?";
        $params[] = $status_filter;
        $types .= 's';
        break;
    case 'all':
        // If 'all', we don't add any more WHERE clauses for status
        break;
}

$query .= " ORDER BY b.created_at DESC";

$stmt = $conn->prepare($query);
// Use a more robust way to bind params that avoids errors
if (count($params) > 2) {
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param($types, $start_date, $end_date);
}
$stmt->execute();
$bills = $stmt->get_result();

require_once '../includes/header.php';
?>

<div class="page-container">
    <div class="dashboard-header">
        <h1>Bill Status Report</h1>
        <p>Monitor pending payments and daily collections.</p>
    </div>

    <form action="view_due_bills.php" method="GET" class="filter-form compact-filters">
        <div class="filter-group">
            <label>Start Date</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>
        <div class="filter-group">
            <label>End Date</label>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>
        <div class="filter-group">
            <label>Payment Status</label>
            <select name="status">
                <option value="pending" <?php if ($status_filter == 'pending') echo 'selected'; ?>>All Pending (Incl. Today's Paid)</option>
                <option value="Half Paid" <?php if ($status_filter == 'Half Paid') echo 'selected'; ?>>Half Paid</option>
                <option value="Due" <?php if ($status_filter == 'Due') echo 'selected'; ?>>Due</option>
            </select>
        </div>
        <div class="filter-actions">
           <button type="submit" class="btn-submit">Filter</button>
        </div>
    </form>

    <div class="table-container">
    <div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Bill No</th>
                <th>Patient ID</th>
                <th>Patient Name</th>
                <th>Bill Date</th>
                <th>Net Amount</th>
                <th>Amount Paid</th>
                <th>Balance Due</th>
                <th>Status</th>
                <th>Billed By</th>
                <th>Generated At</th>
                <th>Last Updated At</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($bills->num_rows > 0): ?>
                <?php while($bill = $bills->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $bill['id']; ?></td>
                    <td><span style="font-size:0.82rem;color:#666;"><?php echo htmlspecialchars($bill['patient_uid'] ?? ''); ?></span></td>
                    <td><?php echo htmlspecialchars($bill['patient_name']); ?></td>
                    <td><?php echo date('d-m-Y', strtotime($bill['created_at'])); ?></td>
                    <td><?php echo number_format($bill['net_amount'], 2); ?></td>
                    <td><?php echo number_format($bill['amount_paid'], 2); ?></td>
                    <td><?php echo number_format($bill['balance_amount'], 2); ?></td>
                    <td><span class="status-<?php echo strtolower(str_replace(' ', '-', $bill['payment_status'])); ?>"><?php echo $bill['payment_status']; ?></span></td>
                    <td><?php echo htmlspecialchars($bill['receptionist_name']); ?></td>
                    <td><?php echo date('d-m-Y h:i A', strtotime($bill['created_at'])); ?></td>
                    <td>
                        <?php
                        // --- NEW LOGIC FOR THE FINAL COLUMN ---
                        if ($bill['payment_status'] == 'Paid') {
                            echo 'Completed at ' . date('d-m-Y h:i A', strtotime($bill['updated_at']));
                        } elseif ($bill['payment_status'] == 'Half Paid') {
                            echo date('d-m-Y h:i A', strtotime($bill['updated_at']));
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>