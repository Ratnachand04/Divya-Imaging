<?php
$page_title = "Manage Payments";
$required_role = "accountant";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_bill_payment_split_columns($conn);

if (isset($_POST['update_status']) && isset($_POST['bill_id'])) {
    $bill_id_to_update = (int)$_POST['bill_id'];
    $new_status = $_POST['new_status'];
    if ($new_status === 'Paid' || $new_status === 'Pending') {
        $update_stmt = $conn->prepare("UPDATE bills SET payment_status = ? WHERE id = ?");
        $update_stmt->bind_param("si", $new_status, $bill_id_to_update);
        $update_stmt->execute();
        $update_stmt->close();
    }
}

$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

$query_select = "SELECT b.id, p.uid as patient_uid, p.name as patient_name, b.net_amount, b.created_at, b.payment_status, b.payment_mode, b.cash_amount, b.card_amount, b.upi_amount, b.other_amount FROM bills b JOIN patients p ON b.patient_id = p.id";
$count_select = "SELECT COUNT(b.id) FROM bills b JOIN patients p ON b.patient_id = p.id";
$params = [];
$types = '';
$base_where = " WHERE b.bill_status != 'Void' ";

if (!empty($search_term)) {
    $where_clause = " AND (b.id LIKE ? OR p.name LIKE ?)";
    $full_where = $base_where . $where_clause;
    $search_like = "%{$search_term}%";
    $params = [$search_like, $search_like];
    $types = 'ss';
} else {
    $full_where = $base_where;
}

$final_count_query = $count_select . $full_where;
$count_stmt = $conn->prepare($final_count_query);
if (!empty($params)) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_results = $count_stmt->get_result()->fetch_row()[0];
$total_pages = max(1, (int)ceil($total_results / $limit));
$page = max(1, min($page, $total_pages));
$offset = ($page - 1) * $limit;
$count_stmt->close();

$final_query = $query_select . $full_where . " ORDER BY b.id DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($final_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$bills_result = $stmt->get_result();
require_once '../includes/header.php';
?>

    <div class="main-content page-container">
        <div class="dashboard-header">
            <div>
                <h1>Manage Bill Payments</h1>
                <p>Track patient payments and update bill statuses efficiently.</p>
            </div>
        </div>

        <form method="GET" action="manage_payments.php" class="filter-form compact-filters" style="margin-bottom: 1.5rem;">
            <div class="filter-group">
                <div class="form-group">
                    <label for="search">Search Payments</label>
                    <input type="text" id="search" name="search" placeholder="Bill No or Patient Name..." value="<?php echo htmlspecialchars($search_term); ?>" style="min-width: 300px; color: #000 !important;">
                </div>
            </div>
            <div class="filter-actions">
                <a href="manage_payments.php" class="btn-cancel">Reset</a>
                <button type="submit" class="btn-submit">Search</button>
            </div>
        </form>

        <div class="insight-card">
            <h3 style="margin-bottom: 1rem;">Payment Records</h3>
            <div class="table-responsive">
            <table class="data-table">
                <thead><tr><th>Bill No.</th><th>Patient ID</th><th>Patient Name</th><th>Date</th><th>Amount</th><th>Payment Mode</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                    <?php if ($bills_result->num_rows > 0): while($bill = $bills_result->fetch_assoc()): ?>
                    <tr>
                        <td><strong>#<?php echo $bill['id']; ?></strong></td>
                        <td><span style="font-size:0.82rem;color:#666;"><?php echo htmlspecialchars($bill['patient_uid'] ?? ''); ?></span></td>
                        <td><?php echo htmlspecialchars($bill['patient_name']); ?></td>
                        <td><?php echo date('d M Y', strtotime($bill['created_at'])); ?></td>
                        <td style="font-weight:600;">₹ <?php echo number_format($bill['net_amount'], 2); ?></td>
                        <td><span class="tag warning"><?php echo htmlspecialchars(format_payment_mode_display($bill)); ?></span></td>
                        <td><span class="status-pill <?php echo strtolower($bill['payment_status']) == 'paid' ? 'down' : 'up'; ?>"><?php echo $bill['payment_status']; ?></span></td>
                        <td>
                            <?php if ($bill['payment_status'] == 'Pending'): ?>
                            <form action="manage_payments.php" method="POST" style="display:inline;"><input type="hidden" name="bill_id" value="<?php echo $bill['id']; ?>"><input type="hidden" name="new_status" value="Paid"><button type="submit" name="update_status" class="btn-action btn-paid" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">Mark Paid</button></form>
                            <?php endif; ?>
                            <a href="../templates/print_bill.php?bill_id=<?php echo $bill['id']; ?>" class="btn-action btn-view" target="_blank" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">View</a>
                        </td>
                    </tr>
                    <?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
            <?php echo render_unified_pagination('manage_payments.php', (int)$page, (int)$total_pages, ['search' => $search_term], 'Manage Payments Pagination'); ?>
        </div>
    </div>
<?php $stmt->close(); require_once '../includes/footer.php'; ?>