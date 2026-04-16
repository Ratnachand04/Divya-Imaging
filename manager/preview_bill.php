<?php
$page_title = "Preview Bill";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_bill_payment_split_columns($conn);
ensure_package_management_schema($conn);

if (!isset($_GET['bill_id']) || !is_numeric($_GET['bill_id'])) {
    header("Location: analytics.php");
    exit();
}

$bill_id = (int)$_GET['bill_id'];

$stmt = $conn->prepare(
    "SELECT b.*, p.uid as patient_uid, p.name as patient_name, p.age, p.sex, p.address, p.city, p.mobile_number, u.username as receptionist_username, rd.doctor_name as referral_doctor_name, b.referral_source_other
     FROM bills b
     JOIN patients p ON b.patient_id = p.id
     JOIN users u ON b.receptionist_id = u.id
     LEFT JOIN referral_doctors rd ON b.referral_doctor_id = rd.id
     WHERE b.id = ?"
);
$stmt->bind_param("i", $bill_id);
$stmt->execute();
$bill_result = $stmt->get_result();

if ($bill_result->num_rows === 0) {
    die("Error: The bill you are trying to preview could not be found.");
}
$bill = $bill_result->fetch_assoc();
$stmt->close();

$bill_paid_amount = round(max(0, (float)$bill['amount_paid']), 2);
$bill_pending_amount = calculate_pending_amount((float)$bill['net_amount'], $bill_paid_amount);
$bill_payment_status = derive_payment_status_from_amounts((float)$bill['net_amount'], $bill_paid_amount, 'Due');
$payment_mode_display = format_payment_mode_display($bill);

if ($bill['referral_type'] === 'Doctor' && !empty($bill['referral_doctor_name'])) {
    $referring_display = (string)$bill['referral_doctor_name'];
} elseif ($bill['referral_type'] === 'Other' && !empty($bill['referral_source_other'])) {
    $referring_display = 'Other (' . (string)$bill['referral_source_other'] . ')';
} else {
    $referring_display = 'Self';
}

$payment_strip_class = 'is-due';
if ($bill_payment_status === 'Paid') {
    $payment_strip_class = 'is-paid';
} elseif ($bill_payment_status === 'Partial Paid') {
    $payment_strip_class = 'is-partial';
}

$preview_rows = [];

        $items_stmt = $conn->prepare(
                    "SELECT bi.id AS bill_item_id,
                            COALESCE(bi.item_type, 'test') AS item_type,
                            bi.package_id,
                            COALESCE(NULLIF(bi.package_name, ''), tp.package_name) AS package_name,
                            COALESCE(bi.package_discount, 0) AS package_discount,
                            t.main_test_name,
                            t.sub_test_name,
                            t.price,
                            COALESCE(bis.screening_amount, 0) AS screening_amount
                     FROM bill_items bi
                     LEFT JOIN tests t ON bi.test_id = t.id
                     LEFT JOIN test_packages tp ON tp.id = bi.package_id
                     LEFT JOIN bill_item_screenings bis ON bis.bill_item_id = bi.id
                     WHERE bi.bill_id = ?
                       AND bi.item_status = 0
                       AND (COALESCE(bi.item_type, 'test') = 'package' OR bi.package_id IS NULL)
                     ORDER BY bi.id ASC"
                );
$items_stmt->bind_param("i", $bill_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();

        $package_breakdown_map = [];
        $package_items_stmt = $conn->prepare(
            "SELECT bill_item_id, test_name, base_test_price, package_test_price
             FROM bill_package_items
             WHERE bill_id = ?
             ORDER BY id ASC"
        );
        if ($package_items_stmt) {
            $package_items_stmt->bind_param('i', $bill_id);
            $package_items_stmt->execute();
            $package_items_result = $package_items_stmt->get_result();
            while ($pkg_row = $package_items_result->fetch_assoc()) {
                $bill_item_id = (int)($pkg_row['bill_item_id'] ?? 0);
                if (!isset($package_breakdown_map[$bill_item_id])) {
                    $package_breakdown_map[$bill_item_id] = [];
                }
                $package_breakdown_map[$bill_item_id][] = $pkg_row;
            }
            $package_items_stmt->close();
        }

while($item = $items_result->fetch_assoc()) {
            $bill_item_id = (int)($item['bill_item_id'] ?? 0);
            $item_type = (string)($item['item_type'] ?? 'test');

            if ($item_type === 'package') {
                $package_name = trim((string)($item['package_name'] ?? ''));
                if ($package_name === '') {
                    $package_name = 'Package';
                }
                $package_tests = $package_breakdown_map[$bill_item_id] ?? [];
                $package_total = 0.0;
                foreach ($package_tests as $package_test) {
                    $package_total += (float)($package_test['package_test_price'] ?? 0);
                }
                $package_total = round($package_total, 2);

                $preview_rows[] = [
                    'label' => $package_name . ' (PACKAGE)',
                    'amount' => $package_total,
                    'is_screening' => false,
                ];

                foreach ($package_tests as $package_test) {
                    $test_name = trim((string)($package_test['test_name'] ?? 'Included Test'));
                    if ($test_name === '') {
                        $test_name = 'Included Test';
                    }
                    $preview_rows[] = [
                        'label' => '  - ' . $test_name,
                        'amount' => (float)($package_test['package_test_price'] ?? 0),
                        'is_screening' => true,
                    ];
                }
                continue;
            }

            $raw_name = trim(preg_replace('/\s+/', ' ', (string)$item['main_test_name'] . ' ' . (string)$item['sub_test_name']));
            if ($raw_name === '') {
                $raw_name = 'Unnamed Test';
            }
            $base_label = 'Test ' . $raw_name;

            $preview_rows[] = [
                'label' => $base_label,
                'amount' => (float)$item['price'],
                'is_screening' => false,
            ];

            if ((float)$item['screening_amount'] > 0) {
                $preview_rows[] = [
                    'label' => $raw_name . ' Screening',
                    'amount' => (float)$item['screening_amount'],
                    'is_screening' => true,
                ];
    }
}

$items_stmt->close();

require_once '../includes/header.php';
?>

<style>
    .bill-preview-page {
        max-width: 1120px;
        margin: 0 auto;
    }

    .bill-preview-page .invoice-shell {
        background: #ffffff;
        border: 1px solid #dbe4f0;
        border-radius: 18px;
        box-shadow: 0 18px 38px rgba(15, 23, 42, 0.09);
        overflow: hidden;
    }

    .bill-preview-page .invoice-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        padding: 1.1rem 1.2rem;
        background: linear-gradient(120deg, #f7fbff 0%, #eef6ff 60%, #f4f9ff 100%);
        border-bottom: 1px solid #d7e4f7;
    }

    .bill-preview-page .brand-block {
        display: flex;
        align-items: center;
        gap: 0.85rem;
    }

    .bill-preview-page .brand-logo {
        width: 56px;
        height: 56px;
        border-radius: 14px;
        object-fit: cover;
        border: 1px solid #c7d8f0;
        background: #fff;
        padding: 4px;
    }

    .bill-preview-page .brand-text h1 {
        margin: 0;
        font-size: 1.28rem;
        color: #0f2f57;
        letter-spacing: 0.01em;
    }

    .bill-preview-page .brand-text p {
        margin: 0.2rem 0 0;
        color: #49688f;
        font-size: 0.84rem;
    }

    .bill-preview-page .invoice-meta {
        display: grid;
        grid-template-columns: repeat(3, minmax(120px, 1fr));
        gap: 0.5rem;
    }

    .bill-preview-page .meta-item {
        background: #ffffff;
        border: 1px solid #d6e2f1;
        border-radius: 10px;
        padding: 0.45rem 0.6rem;
        min-width: 120px;
    }

    .bill-preview-page .meta-item span {
        display: block;
        font-size: 0.68rem;
        color: #6a7f9b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .bill-preview-page .meta-item strong {
        display: block;
        margin-top: 0.18rem;
        font-size: 0.9rem;
        color: #133864;
    }

    .bill-preview-page .invoice-content {
        padding: 1.15rem 1.2rem 1.25rem;
        display: grid;
        gap: 0.95rem;
    }

    .bill-preview-page .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.8rem;
    }

    .bill-preview-page .info-card {
        border: 1px solid #d9e4f3;
        border-radius: 12px;
        background: #fbfdff;
        padding: 0.75rem 0.85rem;
    }

    .bill-preview-page .info-card h3 {
        margin: 0 0 0.55rem;
        font-size: 0.84rem;
        color: #1d497c;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .bill-preview-page .kv-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.4rem 0.8rem;
    }

    .bill-preview-page .kv-row {
        display: flex;
        flex-direction: column;
        gap: 0.12rem;
    }

    .bill-preview-page .kv-row span {
        font-size: 0.68rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .bill-preview-page .kv-row strong {
        font-size: 0.86rem;
        color: #0f2f57;
        word-break: break-word;
    }

    .bill-preview-page .payment-strip {
        border: 1px solid #d9e4f3;
        border-left-width: 4px;
        border-radius: 12px;
        background: #f8fbff;
        padding: 0.72rem 0.85rem;
        display: grid;
        gap: 0.52rem;
    }

    .bill-preview-page .payment-strip.is-paid {
        border-left-color: #15803d;
        background: #effcf3;
    }

    .bill-preview-page .payment-strip.is-partial {
        border-left-color: #b45309;
        background: #fff9ec;
    }

    .bill-preview-page .payment-strip.is-due {
        border-left-color: #b91c1c;
        background: #fff4f4;
    }

    .bill-preview-page .payment-strip h3 {
        margin: 0;
        font-size: 0.82rem;
        color: #1b3f6a;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .bill-preview-page .payment-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(120px, 1fr));
        gap: 0.5rem;
    }

    .bill-preview-page .payment-chip {
        background: #ffffff;
        border: 1px solid #d5e2f1;
        border-radius: 10px;
        padding: 0.42rem 0.55rem;
    }

    .bill-preview-page .payment-chip span {
        display: block;
        font-size: 0.66rem;
        text-transform: uppercase;
        color: #64748b;
        letter-spacing: 0.03em;
    }

    .bill-preview-page .payment-chip strong {
        display: block;
        margin-top: 0.12rem;
        font-size: 0.86rem;
        color: #0f2f57;
    }

    .bill-preview-page .tests-summary-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 300px;
        gap: 0.9rem;
        align-items: start;
    }

    .bill-preview-page .tests-card,
    .bill-preview-page .summary-card {
        border: 1px solid #d9e4f3;
        border-radius: 12px;
        background: #ffffff;
        overflow: hidden;
    }

    .bill-preview-page .section-head {
        margin: 0;
        padding: 0.65rem 0.85rem;
        font-size: 0.84rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #1d497c;
        background: #f3f8ff;
        border-bottom: 1px solid #d9e4f3;
    }

    .bill-preview-page .preview-table {
        width: 100%;
        border-collapse: collapse;
        border: 0;
        box-shadow: none;
        border-radius: 0;
    }

    .bill-preview-page .preview-table thead {
        background: #eaf2ff;
    }

    .bill-preview-page .preview-table th,
    .bill-preview-page .preview-table td {
        padding: 0.54rem 0.66rem;
        border-bottom: 1px solid #e4edf8;
        font-size: 0.85rem;
    }

    .bill-preview-page .preview-table th {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #47648a;
    }

    .bill-preview-page .preview-table tbody tr:nth-child(even) {
        background: #f9fbff;
    }

    .bill-preview-page .preview-table tbody tr.item-screening {
        background: #f3f8ff;
    }

    .bill-preview-page .preview-table .idx-col {
        width: 56px;
        text-align: center;
        color: #64748b;
        font-weight: 600;
    }

    .bill-preview-page .preview-table .text-right {
        text-align: right;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }

    .bill-preview-page .summary-card-body {
        padding: 0.65rem 0.8rem;
        display: grid;
        gap: 0.36rem;
    }

    .bill-preview-page .summary-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px dashed #dbe7f6;
        padding: 0.35rem 0;
        font-size: 0.86rem;
        color: #2b486e;
    }

    .bill-preview-page .summary-row strong {
        font-variant-numeric: tabular-nums;
    }

    .bill-preview-page .summary-row.total {
        margin-top: 0.2rem;
        border-bottom: 0;
        border-top: 2px solid #d3e2f6;
        padding-top: 0.56rem;
        font-size: 0.96rem;
        color: #0f2f57;
        font-weight: 700;
    }

    .bill-preview-page .preview-actions-bottom {
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
        justify-content: flex-end;
    }

    .bill-preview-page .preview-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        border-radius: 10px;
        padding: 0.52rem 0.9rem;
        font-weight: 700;
        font-size: 0.84rem;
        border: 1px solid transparent;
        transition: all 0.18s ease;
    }

    .bill-preview-page .preview-btn.primary {
        background: #0f4c81;
        color: #fff;
    }

    .bill-preview-page .preview-btn.primary:hover {
        background: #0d416f;
    }

    .bill-preview-page .preview-btn.secondary {
        background: #ffffff;
        border-color: #a8bfdc;
        color: #0f4c81;
    }

    .bill-preview-page .preview-btn.secondary:hover {
        background: #f0f6ff;
    }

    .bill-preview-page .preview-btn.danger {
        background: #ffffff;
        border-color: #e3b5c0;
        color: #b62d4f;
    }

    .bill-preview-page .preview-btn.danger:hover {
        background: #fff1f4;
    }

    @media (max-width: 980px) {
        .bill-preview-page .invoice-meta {
            grid-template-columns: repeat(2, minmax(120px, 1fr));
        }

        .bill-preview-page .info-grid {
            grid-template-columns: 1fr;
        }

        .bill-preview-page .payment-grid {
            grid-template-columns: repeat(2, minmax(140px, 1fr));
        }

        .bill-preview-page .tests-summary-layout {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 640px) {
        .bill-preview-page .invoice-header {
            flex-direction: column;
            align-items: stretch;
        }

        .bill-preview-page .brand-logo {
            width: 48px;
            height: 48px;
        }

        .bill-preview-page .invoice-meta {
            grid-template-columns: 1fr;
        }

        .bill-preview-page .kv-grid {
            grid-template-columns: 1fr;
        }

        .bill-preview-page .payment-grid {
            grid-template-columns: 1fr;
        }

        .bill-preview-page .preview-actions-bottom {
            justify-content: stretch;
        }

        .bill-preview-page .preview-actions-bottom .preview-btn {
            width: 100%;
        }
    }
</style>

<div class="page-container bill-preview-page">
    <div class="invoice-shell">
        <div class="invoice-header">
            <div class="brand-block">
                <img src="<?php echo $base_url; ?>/assets/images/logo.jpg" alt="Divya Imaging Center" class="brand-logo">
                <div class="brand-text">
                    <h1>Divya Imaging Center</h1>
                    <p>Medical Diagnostics & Imaging Bill Preview</p>
                </div>
            </div>
            <div class="invoice-meta">
                <div class="meta-item">
                    <span>Bill No</span>
                    <strong>#<?php echo htmlspecialchars((string)$bill['id']); ?></strong>
                </div>
                <div class="meta-item">
                    <span>Billed On</span>
                    <strong><?php echo date('d M Y, h:i A', strtotime($bill['created_at'])); ?></strong>
                </div>
                <div class="meta-item">
                    <span>Receptionist</span>
                    <strong><?php echo htmlspecialchars((string)$bill['receptionist_username']); ?></strong>
                </div>
            </div>
        </div>

        <div class="invoice-content">
            <div class="info-grid">
                <section class="info-card">
                    <h3>Patient Details</h3>
                    <div class="kv-grid">
                        <div class="kv-row"><span>UID</span><strong><?php echo htmlspecialchars((string)$bill['patient_uid']); ?></strong></div>
                        <div class="kv-row"><span>Patient Name</span><strong><?php echo htmlspecialchars((string)$bill['patient_name']); ?></strong></div>
                        <div class="kv-row"><span>Age / Gender</span><strong><?php echo htmlspecialchars((string)$bill['age']); ?> / <?php echo htmlspecialchars((string)$bill['sex']); ?></strong></div>
                        <div class="kv-row"><span>Bill No</span><strong>#<?php echo htmlspecialchars((string)$bill['id']); ?></strong></div>
                    </div>
                </section>

                <section class="info-card">
                    <h3>Contact & Referral</h3>
                    <div class="kv-grid">
                        <div class="kv-row"><span>Address</span><strong><?php echo htmlspecialchars((string)$bill['address']); ?></strong></div>
                        <div class="kv-row"><span>City</span><strong><?php echo htmlspecialchars((string)$bill['city']); ?></strong></div>
                        <div class="kv-row"><span>Mobile</span><strong><?php echo htmlspecialchars((string)$bill['mobile_number']); ?></strong></div>
                        <div class="kv-row"><span>Referring Physician</span><strong><?php echo htmlspecialchars($referring_display); ?></strong></div>
                    </div>
                </section>
            </div>

            <section class="payment-strip <?php echo $payment_strip_class; ?>">
                <h3>Payment Summary</h3>
                <div class="payment-grid">
                    <div class="payment-chip"><span>Payment Mode</span><strong><?php echo htmlspecialchars($payment_mode_display); ?></strong></div>
                    <div class="payment-chip"><span>Status</span><strong><?php echo htmlspecialchars($bill_payment_status); ?></strong></div>
                    <div class="payment-chip"><span>Paid</span><strong>₹<?php echo number_format($bill_paid_amount, 2); ?></strong></div>
                    <div class="payment-chip"><span>Pending</span><strong>₹<?php echo number_format($bill_pending_amount, 2); ?></strong></div>
                </div>
            </section>

            <div class="tests-summary-layout">
                <section class="tests-card">
                    <h3 class="section-head">Bill Items</h3>
                    <table class="preview-table">
                        <thead>
                            <tr>
                                <th class="idx-col">#</th>
                                <th>Test / Service</th>
                                <th class="text-right">Amount (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($preview_rows)): ?>
                                <?php foreach ($preview_rows as $idx => $row): ?>
                                <tr class="<?php echo $row['is_screening'] ? 'item-screening' : ''; ?>">
                                    <td class="idx-col"><?php echo (int)$idx + 1; ?></td>
                                    <td><?php echo htmlspecialchars((string)$row['label']); ?></td>
                                    <td class="text-right"><?php echo number_format((float)$row['amount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" style="text-align:center;color:#64748b;">No bill items found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <aside class="summary-card">
                    <h3 class="section-head">Billing Summary</h3>
                    <div class="summary-card-body">
                        <div class="summary-row"><span>Gross Amount</span><strong>₹<?php echo number_format((float)$bill['gross_amount'], 2); ?></strong></div>
                        <div class="summary-row"><span>Discount</span><strong>- ₹<?php echo number_format((float)$bill['discount'], 2); ?></strong></div>
                        <div class="summary-row total"><span>Net Amount</span><strong>₹<?php echo number_format((float)$bill['net_amount'], 2); ?></strong></div>
                    </div>
                </aside>
            </div>

            <div class="preview-actions-bottom">
                <a href="../templates/print_bill.php?bill_id=<?php echo $bill_id; ?>" target="_blank" class="preview-btn primary">Confirm & Print</a>
                <a href="edit_bill.php?bill_id=<?php echo $bill_id; ?>" class="preview-btn secondary">Edit Bill</a>
                <a href="analytics.php" class="preview-btn danger">Cancel</a>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>