<?php
$page_title = "Bill Status Report";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$referral_doctors_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd') : '`referral_doctors` rd';
$users_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'users', 'u') : '`users` u';

// --- Handle Filters ---
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$all_dates = isset($_GET['all_dates']) && $_GET['all_dates'] === '1';
if ($all_dates) {
    $start_date = isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : '2000-01-01';
    $end_date = isset($_GET['end_date']) && $_GET['end_date'] !== '' ? $_GET['end_date'] : date('Y-m-d');
}
// Default to actionable pending only (Due + Partial Paid)
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'pending';

$patient_identifier_expr = function_exists('get_patient_identifier_expression')
    ? get_patient_identifier_expression($conn, 'p')
    : 'CAST(p.id AS CHAR)';

$pending_amount_expr = "ROUND(GREATEST(b.net_amount - b.amount_paid, 0), 2)";

// --- Build Query ---
$query = "SELECT 
                        b.id,
                        {$patient_identifier_expr} as patient_uid,
                        p.name as patient_name,
                        b.net_amount,
                        b.discount,
                        b.amount_paid,
                        b.balance_amount,
                        b.payment_status,
                        b.created_at,
                        b.updated_at,
                        b.referral_type,
                        b.referral_source_other,
                        rd.doctor_name as ref_physician_name,
                        u.username as receptionist_name
          FROM {$bills_source}
          JOIN {$patients_source} ON b.patient_id = p.id
                    LEFT JOIN {$referral_doctors_source} ON rd.id = b.referral_doctor_id
          JOIN {$users_source} ON b.receptionist_id = u.id
          WHERE b.bill_status != 'Void' AND DATE(b.created_at) BETWEEN ? AND ?";

$params = [$start_date, $end_date];
$types = 'ss';

// Add status filtering based on the dropdown selection
switch ($status_filter) {
    case 'pending':
        $query .= " AND {$pending_amount_expr} > 0.01";
        break;
    case 'Partial Paid':
        $query .= " AND b.amount_paid > 0.01 AND {$pending_amount_expr} > 0.01";
        break;
    case 'Due':
        $query .= " AND b.amount_paid <= 0.01 AND {$pending_amount_expr} > 0.01";
        break;
    case 'Paid':
        $query .= " AND {$pending_amount_expr} <= 0.01";
        break;
    case 'all':
        // If 'all', we don't add any more WHERE clauses for status
        break;
}

$query .= " ORDER BY b.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

require_once '../includes/header.php';
?>

<style>
    .manager-due-bills-wrap {
        overflow: visible !important;
    }

    .manager-due-bills-table {
        width: 100% !important;
        min-width: 0 !important;
        table-layout: fixed !important;
    }

    .manager-due-bills-table th,
    .manager-due-bills-table td {
        overflow: visible !important;
        text-overflow: clip !important;
        white-space: normal !important;
        padding: 0.4rem 0.48rem !important;
        line-height: 1.16;
        vertical-align: middle;
    }

    .manager-due-bills-table thead th {
        font-size: 0.74rem !important;
        letter-spacing: 0.03em;
        font-weight: 700;
        white-space: nowrap !important;
    }

    .manager-due-bills-table .col-bill { width: 9%; }
    .manager-due-bills-table .col-patient { width: 27%; }
    .manager-due-bills-table .col-net,
    .manager-due-bills-table .col-discount,
    .manager-due-bills-table .col-paid,
    .manager-due-bills-table .col-pending { width: 10%; }
    .manager-due-bills-table .col-status { width: 8%; }
    .manager-due-bills-table .col-actions { width: 16%; }

    .manager-due-bills-table th.col-bill,
    .manager-due-bills-table td.col-bill,
    .manager-due-bills-table th.col-patient,
    .manager-due-bills-table td.col-patient {
        text-align: left;
        padding-left: 0.52rem !important;
        padding-right: 0.36rem !important;
    }

    .manager-due-bills-table th.col-net,
    .manager-due-bills-table th.col-discount,
    .manager-due-bills-table th.col-paid,
    .manager-due-bills-table th.col-pending,
    .manager-due-bills-table td.amount-col {
        text-align: right !important;
        font-variant-numeric: tabular-nums;
        white-space: nowrap !important;
        font-weight: 600;
        font-size: 0.79rem;
        line-height: 1.1;
    }

    .manager-due-bills-table th.col-status,
    .manager-due-bills-table td.col-status {
        text-align: center;
        padding-left: 0.34rem !important;
        padding-right: 0.34rem !important;
    }

    .manager-due-bills-table .bill-no-link {
        display: inline-block;
        font-weight: 700;
        font-size: 0.82rem;
        color: #104678;
        text-decoration: none;
    }

    .manager-due-bills-table .bill-no-link:hover {
        text-decoration: underline;
    }

    .manager-due-bills-table .bill-date {
        display: block;
        margin-top: 0.02rem;
        font-size: 0.67rem;
        line-height: 1.1;
        color: #64748b;
        white-space: nowrap;
    }

    .manager-due-bills-table .patient-name {
        display: block;
        font-weight: 600;
        font-size: 0.8rem;
        line-height: 1.14;
        color: #1e293b;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .manager-due-bills-table .patient-ref {
        display: block;
        margin-top: 0.02rem;
        font-size: 0.67rem;
        line-height: 1.1;
        color: #475569;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .manager-due-bills-table .patient-uid {
        display: block;
        margin-top: 0.02rem;
        font-size: 0.64rem;
        line-height: 1.08;
        color: #64748b;
        white-space: nowrap;
        overflow: visible;
        text-overflow: clip;
    }

    .manager-due-bills-table .patient-by {
        display: block;
        margin-top: 0.02rem;
        font-size: 0.62rem;
        line-height: 1.08;
        color: #7f8fa5;
    }

    .manager-due-bills-table .status-wrap {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 0;
        height: 100%;
    }

    .manager-due-bills-table .status-paid,
    .manager-due-bills-table .status-due,
    .manager-due-bills-table .status-partial-paid {
        padding: 0.14rem 0.42rem;
        font-size: 0.69rem;
        line-height: 1.15;
        white-space: nowrap;
    }

    .manager-due-bills-table .action-stack {
        display: flex;
        flex-direction: row;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: 0.2rem;
        min-height: 0;
        white-space: normal;
        height: 100%;
    }

    .manager-due-bills-table .action-stack .btn-action {
        white-space: nowrap;
        padding: 0.18rem 0.4rem;
        font-size: 0.67rem;
        line-height: 1.15;
        margin: 0;
    }

    .manager-due-bills-table tbody tr.bill-data-row > td {
        padding-top: 0.35rem !important;
        padding-bottom: 0.35rem !important;
    }

    @media (max-width: 768px) {
        .manager-due-bills-table th,
        .manager-due-bills-table td {
            padding: 0.34rem 0.36rem !important;
            font-size: 0.72rem;
        }

        .manager-due-bills-table .patient-name { font-size: 0.74rem; }
        .manager-due-bills-table .patient-ref { font-size: 0.64rem; }
        .manager-due-bills-table .patient-uid { font-size: 0.62rem; }
        .manager-due-bills-table .patient-by { font-size: 0.6rem; }
        .manager-due-bills-table .action-stack .btn-action { font-size: 0.63rem; padding: 0.15rem 0.34rem; }
    }
</style>

<div class="page-container">
    <div class="dashboard-header">
        <h1>Bill Status Report</h1>
        <p>Monitor pending payments and daily collections.</p>
    </div>

    <?php if (!empty($_GET['success'])): ?>
        <div class="success-banner"><?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="error-banner"><?php echo htmlspecialchars($_GET['error']); ?></div>
    <?php endif; ?>

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
                <option value="pending" <?php if ($status_filter == 'pending') echo 'selected'; ?>>Pending Only (Due + Partial Paid)</option>
                <option value="Due" <?php if ($status_filter == 'Due') echo 'selected'; ?>>Due Only</option>
                <option value="Partial Paid" <?php if ($status_filter == 'Partial Paid') echo 'selected'; ?>>Partial Paid Only</option>
                <option value="Paid" <?php if ($status_filter == 'Paid') echo 'selected'; ?>>Paid Only</option>
                <option value="all" <?php if ($status_filter == 'all') echo 'selected'; ?>>All Bills (Including Paid)</option>
            </select>
        </div>
        <div class="filter-actions">
           <button type="submit" class="btn-submit">Filter</button>
        </div>
    </form>

    <div class="table-container">
    <div class="table-responsive manager-due-bills-wrap">
    <table class="report-table manager-due-bills-table">
        <colgroup>
            <col style="width:9%;">
            <col style="width:27%;">
            <col style="width:10%;">
            <col style="width:10%;">
            <col style="width:10%;">
            <col style="width:10%;">
            <col style="width:8%;">
            <col style="width:16%;">
        </colgroup>
        <thead>
            <tr>
                <th class="col-bill">Bill No.</th>
                <th class="col-patient">Patient Name</th>
                <th class="col-net">Net Amount</th>
                <th class="col-discount">Discount</th>
                <th class="col-paid">Paid Amount</th>
                <th class="col-pending">Pending Amount</th>
                <th class="col-status">Status</th>
                <th class="col-actions">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($bills)): ?>
                <?php foreach($bills as $bill): ?>
                <?php
                    $paid_amount = round(max(0, (float)$bill['amount_paid']), 2);
                    $pending_amount = calculate_pending_amount((float)$bill['net_amount'], $paid_amount);
                    $derived_status = derive_payment_status_from_amounts((float)$bill['net_amount'], $paid_amount, 'Due');
                    $status_class = 'status-' . strtolower(str_replace(' ', '-', $derived_status));

                    if ($bill['referral_type'] === 'Doctor' && !empty($bill['ref_physician_name'])) {
                        $ref_display = 'Ref: ' . (string)$bill['ref_physician_name'];
                    } elseif ($bill['referral_type'] === 'Other' && !empty($bill['referral_source_other'])) {
                        $ref_display = 'Ref: Other (' . (string)$bill['referral_source_other'] . ')';
                    } else {
                        $ref_display = 'Ref: Self';
                    }

                    $bill_id_value = (int)$bill['id'];
                ?>
                <tr class="bill-data-row">
                    <td class="col-bill">
                        <a class="bill-no-link" href="preview_bill.php?bill_id=<?php echo $bill_id_value; ?>">#<?php echo $bill_id_value; ?></a>
                        <small class="bill-date"><?php echo date('d-m-Y', strtotime($bill['created_at'])); ?></small>
                    </td>
                    <td class="col-patient">
                        <span class="patient-name"><?php echo htmlspecialchars((string)$bill['patient_name']); ?></span>
                        <small class="patient-ref"><?php echo htmlspecialchars($ref_display); ?></small>
                        <small class="patient-uid"><?php echo htmlspecialchars((string)$bill['patient_uid']); ?></small>
                        <small class="patient-by">Billed by: <?php echo htmlspecialchars((string)$bill['receptionist_name']); ?></small>
                    </td>
                    <td class="amount-col">₹<?php echo number_format((float)$bill['net_amount'], 2); ?></td>
                    <td class="amount-col">₹<?php echo number_format((float)$bill['discount'], 2); ?></td>
                    <td class="amount-col">₹<?php echo number_format($paid_amount, 2); ?></td>
                    <td class="amount-col">₹<?php echo number_format($pending_amount, 2); ?></td>
                    <td class="col-status"><div class="status-wrap"><span class="<?php echo $status_class; ?>"><?php echo htmlspecialchars($derived_status); ?></span></div></td>
                    <td class="col-actions">
                        <div class="action-stack">
                            <a href="preview_bill.php?bill_id=<?php echo $bill_id_value; ?>" class="btn-action btn-view">View Bill</a>
                            <?php if ($pending_amount > 0.01): ?>
                                <a href="update_payment_manager.php?bill_id=<?php echo $bill_id_value; ?>" class="btn-action btn-update">Update Payment</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" style="text-align:center;">No bills found for selected filters.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>