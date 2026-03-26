<?php
$page_title = "Bill Summary";
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

// --- Handle All Filters ---
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$all_dates = isset($_GET['all_dates']) && $_GET['all_dates'] === '1';
if ($all_dates) {
    $start_date = isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : '2000-01-01';
    $end_date = isset($_GET['end_date']) && $_GET['end_date'] !== '' ? $_GET['end_date'] : date('Y-m-d');
}
$payment_status_filter = isset($_GET['payment_status']) && $_GET['payment_status'] !== 'all' ? $_GET['payment_status'] : 'all';
$payment_mode_filter = isset($_GET['payment_mode']) && $_GET['payment_mode'] !== 'all' ? $_GET['payment_mode'] : 'all';
// --- NEW: Get Search Term ---
$search_term = isset($_GET['search_term']) ? trim($_GET['search_term']) : '';
$receptionist_id = $_SESSION['user_id']; //

// --- Build Query Dynamically ---
$base_query = "SELECT
        b.id, p.uid as patient_uid, p.name as patient_name, b.gross_amount, b.discount, b.net_amount,
        b.created_at, b.payment_mode, b.payment_status, b.referral_type,
        rd.doctor_name as ref_physician_name
    FROM bills b
    JOIN patients p ON b.patient_id = p.id
    LEFT JOIN referral_doctors rd ON b.referral_doctor_id = rd.id"; //

$where_clauses = ["b.receptionist_id = ?", "b.bill_status != 'Void'", "DATE(b.created_at) BETWEEN ? AND ?"]; //
$params = [$receptionist_id, $start_date, $end_date]; //
$types = 'iss'; //

if ($payment_status_filter === 'pending') {
    $where_clauses[] = "b.payment_status IN ('Due','Half Paid')"; //
} elseif ($payment_status_filter !== 'all') {
    $where_clauses[] = "b.payment_status = ?"; //
    $params[] = $payment_status_filter; //
    $types .= 's'; //
}
if ($payment_mode_filter !== 'all') {
    $where_clauses[] = "b.payment_mode = ?"; //
    $params[] = $payment_mode_filter; //
    $types .= 's'; //
}
// --- NEW: Add Search Condition ---
if (!empty($search_term)) {
    $where_clauses[] = "(p.uid LIKE ? OR p.name LIKE ? OR rd.doctor_name LIKE ?)"; // Search uid, patient name OR doctor name
    $search_like = "%{$search_term}%";
    $params[] = $search_like; // Add param for uid
    $params[] = $search_like; // Add param for patient name
    $params[] = $search_like; // Add param for doctor name
    $types .= 'sss'; // Add types for the three string parameters
}

$query = $base_query . " WHERE " . implode(' AND ', $where_clauses) . " ORDER BY b.payment_mode, b.id ASC"; //

$stmt = $conn->prepare($query);
// --- UPDATED: Use spread operator for dynamic params ---
$stmt->bind_param($types, ...$params);
$stmt->execute();
$bills_result = $stmt->get_result();
$bills = $bills_result->fetch_all(MYSQLI_ASSOC); //
$stmt->close();
?>

<div class="table-container">
    <h1>Bill Summary</h1>

    <form action="bill_history.php" method="GET" class="date-filter-form">
        <div class="form-group">
            <label for="start_date">Start Date</label>
            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>
        <div class="form-group">
            <label for="end_date">End Date</label>
            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>
        <div class="form-group">
            <label for="payment_status">Payment Status</label>
            <select name="payment_status" id="payment_status">
                <option value="all" <?php if($payment_status_filter == 'all') echo 'selected'; ?>>All Statuses</option>
                <option value="Paid" <?php if($payment_status_filter == 'Paid') echo 'selected'; ?>>Paid</option>
                <option value="Half Paid" <?php if($payment_status_filter == 'Half Paid') echo 'selected'; ?>>Half Paid</option>
                <option value="Due" <?php if($payment_status_filter == 'Due') echo 'selected'; ?>>Due</option>
                <option value="pending" <?php if($payment_status_filter == 'pending') echo 'selected'; ?>>Pending (Due + Half)</option>
            </select>
        </div>
        <div class="form-group">
            <label for="payment_mode">Payment Mode</label>
            <select name="payment_mode" id="payment_mode">
                <option value="all" <?php if($payment_mode_filter == 'all') echo 'selected'; ?>>All Modes</option>
                <option value="Cash" <?php if($payment_mode_filter == 'Cash') echo 'selected'; ?>>Cash</option>
                <option value="UPI" <?php if($payment_mode_filter == 'UPI') echo 'selected'; ?>>UPI</option>
                <option value="Card" <?php if($payment_mode_filter == 'Card') echo 'selected'; ?>>Card</option>
                <option value="Other" <?php if($payment_mode_filter == 'Other') echo 'selected'; ?>>Other</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="search_term">Search UID/Patient/Doctor</label>
            <input type="text" id="search_term" name="search_term" placeholder="Enter UID or name..." value="<?php echo htmlspecialchars($search_term); ?>">
        </div>
        <button type="submit" class="btn-submit">Get Report</button>
    </form>

    <div class="table-responsive">
    <table class="report-table">
        <thead>
            <tr>
                <th>Bill No.</th>
                <th>UID</th>
                <th>Patient Name</th>
                <th>Ref. Physician</th>
                <th>Gross Amt</th>
                <th>Discount</th>
                <th>Net Amount</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if (count($bills) > 0) {
                $current_group = null;
                $group_gross = 0; $group_discount = 0; $group_net = 0;
                $grand_gross = 0; $grand_discount = 0; $grand_net = 0;

                foreach ($bills as $index => $bill) {
                    // Grouping logic remains the same...
                    if ($bill['payment_mode'] !== $current_group) {
                        if ($current_group !== null) {
                            echo '<tr class="group-total-row">';
                            echo '<td colspan="4" style="text-align:right;"><strong>' . htmlspecialchars(strtoupper($current_group)) . ' Total:</strong></td>';
                            echo '<td><strong>' . number_format($group_gross, 2) . '</strong></td>';
                            echo '<td><strong>' . number_format($group_discount, 2) . '</strong></td>';
                            echo '<td><strong>' . number_format($group_net, 2) . '</strong></td>';
                            echo '<td colspan="2"></td>';
                            echo '</tr>';
                        }
                        $group_gross = 0; $group_discount = 0; $group_net = 0;
                        $current_group = $bill['payment_mode'];
                        echo '<tr><th colspan="9" class="group-header">' . htmlspecialchars(strtoupper($current_group)) . '</th></tr>';
                    }

                    // Print the bill data row...
                    echo '<tr>';
                    echo '<td>' . $bill['id'] . '</td>';
                    echo '<td>' . htmlspecialchars($bill['patient_uid']) . '</td>';
                    echo '<td>' . htmlspecialchars($bill['patient_name']) . '</td>';
                    if ($bill['referral_type'] == 'Doctor' && !empty($bill['ref_physician_name'])) {
                        $ref_physician_display = htmlspecialchars($bill['ref_physician_name']);
                    } else {
                        $ref_physician_display = htmlspecialchars($bill['referral_type']);
                    }
                    echo '<td>' . $ref_physician_display . '</td>';
                    echo '<td>' . number_format($bill['gross_amount'], 2) . '</td>';
                    echo '<td>' . number_format($bill['discount'], 2) . '</td>';
                    echo '<td>' . number_format($bill['net_amount'], 2) . '</td>';
                    echo '<td><span class="status-' . strtolower(str_replace(' ', '-', $bill['payment_status'])) . '">' . $bill['payment_status'] . '</span></td>';
                    
                    // Action buttons logic remains the same...
                    echo '<td class="actions-cell">';
                    echo '<a href="preview_bill.php?bill_id=' . $bill['id'] . '" class="btn-action btn-view" target="_blank">View</a>';
                    
                    $created_time = new DateTime($bill['created_at']);
                    $current_time = new DateTime();
                    $interval = $current_time->diff($created_time);
                    $hours_diff = $interval->h + ($interval->days * 24);
                    if ($hours_diff < 12) {
                        echo '<a href="edit_bill.php?bill_id=' . $bill['id'] . '" class="btn-action btn-edit">Edit</a>';
                    }
                    
                    if ($bill['payment_status'] == 'Due' || $bill['payment_status'] == 'Half Paid') {
                        echo '<a href="update_payment.php?bill_id=' . $bill['id'] . '" class="btn-action btn-update">Update Payment</a>';
                    }
                    echo '</td>';
                    echo '</tr>';

                    // Add to totals...
                    $group_gross += $bill['gross_amount']; $group_discount += $bill['discount']; $group_net += $bill['net_amount'];
                    $grand_gross += $bill['gross_amount']; $grand_discount += $bill['discount']; $grand_net += $bill['net_amount'];
                }

                // Print the footer for the very last group...
                echo '<tr class="group-total-row">';
                echo '<td colspan="4" style="text-align:right;"><strong>' . htmlspecialchars(strtoupper($current_group)) . ' Total:</strong></td>';
                echo '<td><strong>' . number_format($group_gross, 2) . '</strong></td>';
                echo '<td><strong>' . number_format($group_discount, 2) . '</strong></td>';
                echo '<td><strong>' . number_format($group_net, 2) . '</strong></td>';
                echo '<td colspan="2"></td>';
                echo '</tr>';

            } else {
                echo '<tr><td colspan="9" style="text-align:center;">No bills found for the selected criteria.</td></tr>';
            }
            ?>
        </tbody>
        <?php if (count($bills) > 0): ?>
        <tfoot>
            <tr class="grand-total-row">
                <th colspan="4" style="text-align:right;">Grand Total:</th>
                <th><?php echo number_format($grand_gross, 2); ?></th>
                <th><?php echo number_format($grand_discount, 2); ?></th>
                <th><?php echo number_format($grand_net, 2); ?></th>
                <th colspan="2"></th>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>