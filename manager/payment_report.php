<?php
$page_title = "Detailed Payment Report";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_payment_history_split_columns($conn);

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

$stmt = $conn->prepare(
    "SELECT 
        ph.*, p.name as patient_name, b.net_amount, u.username
    FROM payment_history ph
    JOIN bills b ON ph.bill_id = b.id
    JOIN patients p ON b.patient_id = p.id
    JOIN users u ON ph.user_id = u.id
    WHERE DATE(ph.paid_at) BETWEEN ? AND ?
    ORDER BY ph.paid_at DESC"
);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$payments = $stmt->get_result();

require_once '../includes/header.php';
?>

<div class="page-container">
    <div class="dashboard-header">
        <h1>Detailed Payment Report</h1>
        <p>This report shows all individual payment transactions within the selected date range.</p>
    </div>

    <form action="payment_report.php" method="GET" class="filter-form compact-filters" style="margin-bottom: 2rem;">
        <div class="filter-group">
            <label>Start Date</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>
        <div class="filter-group">
            <label>End Date</label>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
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
                <th>Patient Name</th>
                <th>Previously Paid</th>
                <th>Amount Just Paid</th>
                <th>New Total Paid</th>
                <th>Payment Mode</th>
                <th>Updated By</th>
                <th>Transaction Time</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($payments->num_rows > 0): ?>
                <?php while($p = $payments->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $p['bill_id']; ?></td>
                    <td><?php echo htmlspecialchars($p['patient_name']); ?></td>
                    <td><?php echo number_format($p['previous_amount_paid'], 2); ?></td>
                    <td style="font-weight: bold; color: #27ae60;"><?php echo number_format($p['amount_paid_in_txn'], 2); ?></td>
                    <td><?php echo number_format($p['new_total_amount_paid'], 2); ?></td>
                    <td><?php echo htmlspecialchars(format_payment_mode_display($p)); ?></td>
                    <td><?php echo htmlspecialchars($p['username']); ?></td>
                    <td><?php echo date('d-m-Y h:i A', strtotime($p['paid_at'])); ?></td>
                </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
    </div>
<?php require_once '../includes/footer.php'; ?>