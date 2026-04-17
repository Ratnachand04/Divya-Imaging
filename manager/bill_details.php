<?php
$page_title = 'Bill Details';
$required_role = 'manager';

require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_bill_payment_split_columns($conn);
ensure_payment_history_split_columns($conn);
ensure_package_management_schema($conn);

$bill_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$users_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'users', 'u') : '`users` u';
$referral_doctors_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd') : '`referral_doctors` rd';
$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';
$test_packages_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'test_packages', 'tp') : '`test_packages` tp';
$screenings_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_item_screenings', 'bis') : '`bill_item_screenings` bis';
$bill_package_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_package_items', 'bpi') : '`bill_package_items` bpi';
$payment_history_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'payment_history', 'ph') : '`payment_history` ph';

$bill_id = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;
if ($bill_id <= 0) {
    header('Location: analytics.php');
    exit;
}

$bill = null;
$bill_error = '';

$bill_sql = "SELECT
                b.id AS bill_id,
                b.invoice_number,
                b.created_at,
                b.bill_status,
                b.referral_type,
                b.referral_source_other,
                b.gross_amount,
                b.discount,
                b.discount_by,
                b.net_amount,
                b.amount_paid,
                b.balance_amount,
                b.payment_status,
                b.payment_mode,
                b.cash_amount,
                b.card_amount,
                b.upi_amount,
                b.other_amount,
                p.id AS patient_db_id,
                p.uid AS patient_uid,
                p.name AS patient_name,
                p.age AS patient_age,
                p.sex AS patient_gender,
                p.mobile_number,
                p.address,
                p.city,
                u.username AS receptionist_name,
                rd.doctor_name AS referral_doctor_name
            FROM {$bill_source}
            JOIN {$patients_source} ON p.id = b.patient_id
            LEFT JOIN {$users_source} ON u.id = b.receptionist_id
            LEFT JOIN {$referral_doctors_source} ON rd.id = b.referral_doctor_id
            WHERE b.id = ?
            LIMIT 1";

if ($stmt = $conn->prepare($bill_sql)) {
    $stmt->bind_param('i', $bill_id);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$bill) {
    $bill_error = 'Bill not found or no longer available.';
}

$has_item_discount = function_exists('schema_has_column') && schema_has_column($conn, 'bill_items', 'discount_amount');
$has_screening_table = function_exists('schema_has_table') && schema_has_table($conn, 'bill_item_screenings');

$item_discount_expr = $has_item_discount ? 'COALESCE(bi.discount_amount, 0) AS item_discount' : '0.00 AS item_discount';
$screening_expr = $has_screening_table ? 'COALESCE(bis.screening_amount, 0) AS screening_amount' : '0.00 AS screening_amount';
$screening_join = $has_screening_table ? "LEFT JOIN {$screenings_source} ON bis.bill_item_id = bi.id" : '';

$test_items = [];
$package_breakdown_map = [];
if ($bill) {
    $items_sql = "SELECT
                    bi.id AS bill_item_id,
                    bi.item_status,
                    bi.report_status,
                                        COALESCE(bi.item_type, 'test') AS item_type,
                                        bi.package_id,
                                        COALESCE(NULLIF(bi.package_name, ''), tp.package_name) AS package_name,
                                        COALESCE(bi.package_discount, 0) AS package_discount,
                    t.main_test_name,
                    t.sub_test_name,
                    t.price,
                    {$screening_expr},
                    {$item_discount_expr}
                  FROM {$bill_items_source}
                                    LEFT JOIN {$tests_source} ON t.id = bi.test_id
                                    LEFT JOIN {$test_packages_source} ON tp.id = bi.package_id
                  {$screening_join}
                  WHERE bi.bill_id = ?
                                        AND (COALESCE(bi.item_type, 'test') = 'package' OR bi.package_id IS NULL)
                  ORDER BY bi.id ASC";

    if ($items_stmt = $conn->prepare($items_sql)) {
        $items_stmt->bind_param('i', $bill_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        while ($row = $items_result->fetch_assoc()) {
            $test_items[] = $row;
        }
        $items_stmt->close();
    }

    $package_items_stmt = $conn->prepare(
           "SELECT bpi.bill_item_id, bpi.test_name, bpi.base_test_price, bpi.package_test_price
            FROM {$bill_package_items_source}
            WHERE bpi.bill_id = ?
            ORDER BY bpi.id ASC"
    );
    if ($package_items_stmt) {
        $package_items_stmt->bind_param('i', $bill_id);
        $package_items_stmt->execute();
        $package_items_result = $package_items_stmt->get_result();
        while ($pkg_item = $package_items_result->fetch_assoc()) {
            $bill_item_id = (int)($pkg_item['bill_item_id'] ?? 0);
            if (!isset($package_breakdown_map[$bill_item_id])) {
                $package_breakdown_map[$bill_item_id] = [];
            }
            $package_breakdown_map[$bill_item_id][] = $pkg_item;
        }
        $package_items_stmt->close();
    }
}

$payment_updates = [];
if ($bill) {
    $history_sql = "SELECT
                        ph.amount_paid_in_txn,
                        ph.previous_amount_paid,
                        ph.new_total_amount_paid,
                        ph.payment_mode,
                        ph.cash_amount,
                        ph.card_amount,
                        ph.upi_amount,
                        ph.other_amount,
                        ph.paid_at,
                        u.username AS updated_by
                    FROM {$payment_history_source}
                    LEFT JOIN {$users_source} ON u.id = ph.user_id
                    WHERE ph.bill_id = ?
                    ORDER BY ph.paid_at DESC";

    if ($history_stmt = $conn->prepare($history_sql)) {
        $history_stmt->bind_param('i', $bill_id);
        $history_stmt->execute();
        $history_result = $history_stmt->get_result();
        while ($row = $history_result->fetch_assoc()) {
            $payment_updates[] = $row;
        }
        $history_stmt->close();
    }
}

function money_format_inr($value) {
    return number_format((float)$value, 2);
}

function split_breakdown_from_row(array $row) {
    $parts = [];
    if ((float)($row['cash_amount'] ?? 0) > 0.0001) {
        $parts[] = 'Cash: Rs ' . money_format_inr($row['cash_amount']);
    }
    if ((float)($row['card_amount'] ?? 0) > 0.0001) {
        $parts[] = 'Card: Rs ' . money_format_inr($row['card_amount']);
    }
    if ((float)($row['upi_amount'] ?? 0) > 0.0001) {
        $parts[] = 'UPI: Rs ' . money_format_inr($row['upi_amount']);
    }
    if ((float)($row['other_amount'] ?? 0) > 0.0001) {
        $parts[] = 'Other: Rs ' . money_format_inr($row['other_amount']);
    }

    return empty($parts) ? 'None' : implode(', ', $parts);
}

function referred_by_display(array $bill_row) {
    $type = trim((string)($bill_row['referral_type'] ?? ''));
    if ($type === 'Doctor' && !empty($bill_row['referral_doctor_name'])) {
        return (string)$bill_row['referral_doctor_name'];
    }
    if ($type === 'Other' && !empty($bill_row['referral_source_other'])) {
        return 'Other (' . (string)$bill_row['referral_source_other'] . ')';
    }
    return 'Self';
}

require_once '../includes/header.php';
?>

<div class="page-container">
    <div class="dashboard-header">
        <div>
            <h1>Bill Details</h1>
            <p>Complete bill, patient, and payment update information.</p>
        </div>
        <div class="actions-container" style="margin-top:0;">
            <a class="btn-cancel" href="analytics.php">Back to Analytics</a>
            <?php if ($bill): ?>
                <a class="btn-submit" href="../templates/print_bill.php?bill_id=<?php echo (int)$bill['bill_id']; ?>" target="_blank" rel="noopener">Print Bill</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($bill_error !== ''): ?>
        <div class="error-banner"><?php echo htmlspecialchars($bill_error); ?></div>
    <?php else: ?>
        <?php
            $bill_identifier = !empty($bill['invoice_number']) ? $bill['invoice_number'] : $bill['bill_id'];
            $payment_mode_display = format_payment_mode_display($bill);
            $split_breakdown = split_breakdown_from_row($bill);
            $referred_by = referred_by_display($bill);
            $visit_date = date('d M Y h:i A', strtotime($bill['created_at']));
        ?>

        <div class="detail-section">
            <h3>Bill Overview</h3>
            <div class="detail-grid">
                <p><strong>Bill ID:</strong> <a href="../templates/print_bill.php?bill_id=<?php echo (int)$bill['bill_id']; ?>" target="_blank" rel="noopener">#<?php echo htmlspecialchars((string)$bill_identifier); ?></a></p>
                <p><strong>Patient ID:</strong> <?php echo htmlspecialchars((string)($bill['patient_uid'] ?: ('P' . $bill['patient_db_id']))); ?></p>
                <p><strong>Patient Name:</strong> <?php echo htmlspecialchars((string)$bill['patient_name']); ?></p>
                <p><strong>Age / Gender:</strong> <?php echo htmlspecialchars((string)($bill['patient_age'] ?? '-')); ?> / <?php echo htmlspecialchars((string)($bill['patient_gender'] ?? '-')); ?></p>
                <p><strong>Mobile Number:</strong> <?php echo htmlspecialchars((string)($bill['mobile_number'] ?? '-')); ?></p>
                <p><strong>Address:</strong> <?php echo htmlspecialchars(trim((string)($bill['address'] ?? '')) !== '' ? ((string)$bill['address'] . ', ' . (string)$bill['city']) : '-'); ?></p>
                <p><strong>Referral / Referred By:</strong> <?php echo htmlspecialchars($referred_by); ?></p>
                <p><strong>Receptionist:</strong> <?php echo htmlspecialchars((string)($bill['receptionist_name'] ?? '-')); ?></p>
                <p><strong>Visit Date:</strong> <?php echo htmlspecialchars($visit_date); ?></p>
                <p><strong>Bill Status:</strong> <?php echo htmlspecialchars((string)($bill['bill_status'] ?? '-')); ?></p>
                <p><strong>Payment Status:</strong> <?php echo htmlspecialchars((string)$bill['payment_status']); ?></p>
                <p><strong>Payment Mode:</strong> <?php echo htmlspecialchars($payment_mode_display); ?></p>
            </div>
        </div>

        <div class="detail-section">
            <h3>Test Details</h3>
            <div class="table-responsive">
                <table class="data-table custom-table minimal-padding">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Main Test</th>
                            <th>Sub Test</th>
                            <th>Base Price</th>
                            <th>Screening</th>
                            <th>Item Discount</th>
                            <th>Line Total</th>
                            <th>Report Status</th>
                            <th>Item Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($test_items)): ?>
                            <?php foreach ($test_items as $idx => $item): ?>
                                <?php
                                    $item_type = (string)($item['item_type'] ?? 'test');
                                    $base_price = (float)($item['price'] ?? 0);
                                    $screening = (float)($item['screening_amount'] ?? 0);
                                    $item_discount = (float)($item['item_discount'] ?? 0);
                                    $line_total = max(($base_price + $screening) - $item_discount, 0);
                                    $status_text = ((int)($item['item_status'] ?? 0) === 0) ? 'Active' : 'Hidden';
                                ?>
                                <?php if ($item_type === 'package'): ?>
                                    <?php
                                        $package_name = trim((string)($item['package_name'] ?? 'Package'));
                                        if ($package_name === '') {
                                            $package_name = 'Package';
                                        }
                                        $package_tests = $package_breakdown_map[(int)$item['bill_item_id']] ?? [];
                                        $package_base_total = 0.0;
                                        $package_price_total = 0.0;
                                        foreach ($package_tests as $pkg_test) {
                                            $package_base_total += (float)($pkg_test['base_test_price'] ?? 0);
                                            $package_price_total += (float)($pkg_test['package_test_price'] ?? 0);
                                        }
                                        $package_base_total = round($package_base_total, 2);
                                        $package_price_total = round($package_price_total, 2);
                                        $package_discount = (float)($item['package_discount'] ?? max($package_base_total - $package_price_total, 0));
                                    ?>
                                    <tr>
                                        <td><?php echo (int)$idx + 1; ?></td>
                                        <td><?php echo htmlspecialchars($package_name); ?></td>
                                        <td>PACKAGE</td>
                                        <td style="text-align:right;">Rs <?php echo money_format_inr($package_base_total); ?></td>
                                        <td style="text-align:right;">Rs 0.00</td>
                                        <td style="text-align:right;">Rs <?php echo money_format_inr($package_discount); ?></td>
                                        <td style="text-align:right;">Rs <?php echo money_format_inr($package_price_total); ?></td>
                                        <td>-</td>
                                        <td><?php echo htmlspecialchars($status_text); ?></td>
                                    </tr>
                                    <?php foreach ($package_tests as $pkg_test): ?>
                                        <tr>
                                            <td></td>
                                            <td colspan="2" style="padding-left:1.5rem;">- <?php echo htmlspecialchars((string)($pkg_test['test_name'] ?? 'Included Test')); ?></td>
                                            <td style="text-align:right;">Rs <?php echo money_format_inr($pkg_test['base_test_price'] ?? 0); ?></td>
                                            <td style="text-align:right;">Rs 0.00</td>
                                            <td style="text-align:right;">Rs <?php echo money_format_inr(max((float)($pkg_test['base_test_price'] ?? 0) - (float)($pkg_test['package_test_price'] ?? 0), 0)); ?></td>
                                            <td style="text-align:right;">Rs <?php echo money_format_inr($pkg_test['package_test_price'] ?? 0); ?></td>
                                            <td>-</td>
                                            <td>-</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td><?php echo (int)$idx + 1; ?></td>
                                        <td><?php echo htmlspecialchars((string)$item['main_test_name']); ?></td>
                                        <td><?php echo htmlspecialchars((string)($item['sub_test_name'] ?? '-')); ?></td>
                                        <td style="text-align:right;">Rs <?php echo money_format_inr($base_price); ?></td>
                                        <td style="text-align:right;">Rs <?php echo money_format_inr($screening); ?></td>
                                        <td style="text-align:right;">Rs <?php echo money_format_inr($item_discount); ?></td>
                                        <td style="text-align:right;">Rs <?php echo money_format_inr($line_total); ?></td>
                                        <td><?php echo htmlspecialchars((string)($item['report_status'] ?? 'Pending')); ?></td>
                                        <td><?php echo htmlspecialchars($status_text); ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align:center;">No tests found for this bill.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="detail-section">
            <h3>Amount Summary</h3>
            <div class="detail-grid">
                <p><strong>Gross Amount:</strong> Rs <?php echo money_format_inr($bill['gross_amount']); ?></p>
                <p><strong>Discount:</strong> Rs <?php echo money_format_inr($bill['discount']); ?> (<?php echo htmlspecialchars((string)($bill['discount_by'] ?? '-')); ?>)</p>
                <p><strong>Net Amount:</strong> Rs <?php echo money_format_inr($bill['net_amount']); ?></p>
                <p><strong>Paid Amount:</strong> Rs <?php echo money_format_inr($bill['amount_paid']); ?></p>
                <p><strong>Pending Amount:</strong> Rs <?php echo money_format_inr($bill['balance_amount']); ?></p>
                <p><strong>Split Payment Details:</strong> <?php echo htmlspecialchars($split_breakdown); ?></p>
            </div>
        </div>

        <div class="detail-section">
            <h3>Payment Update History</h3>
            <div class="table-responsive">
                <table class="data-table custom-table minimal-padding">
                    <thead>
                        <tr>
                            <th>Updated At</th>
                            <th>Updated By</th>
                            <th>Mode</th>
                            <th>Txn Amount</th>
                            <th>Previous Paid</th>
                            <th>New Total Paid</th>
                            <th>Split Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($payment_updates)): ?>
                            <?php foreach ($payment_updates as $history): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('d M Y h:i A', strtotime((string)$history['paid_at']))); ?></td>
                                    <td><?php echo htmlspecialchars((string)($history['updated_by'] ?? '-')); ?></td>
                                    <td><?php echo htmlspecialchars(format_payment_mode_display($history)); ?></td>
                                    <td style="text-align:right;">Rs <?php echo money_format_inr($history['amount_paid_in_txn']); ?></td>
                                    <td style="text-align:right;">Rs <?php echo money_format_inr($history['previous_amount_paid']); ?></td>
                                    <td style="text-align:right;">Rs <?php echo money_format_inr($history['new_total_amount_paid']); ?></td>
                                    <td><?php echo htmlspecialchars(split_breakdown_from_row($history)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align:center;">No payment updates recorded for this bill yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
