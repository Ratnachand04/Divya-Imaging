<?php
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

try {
    ensure_patient_registration_schema($conn);
    ensure_bill_payment_split_columns($conn);

    $has_screening_table = false;
    $screening_table_check = $conn->query("SHOW TABLES LIKE 'bill_item_screenings'");
    if ($screening_table_check && $screening_table_check->num_rows > 0) {
        $has_screening_table = true;
    }
    if ($screening_table_check instanceof mysqli_result) {
        $screening_table_check->free();
    }

    $has_item_discount_column = false;
    $discount_column_check = $conn->query("SHOW COLUMNS FROM bill_items LIKE 'discount_amount'");
    if ($discount_column_check && $discount_column_check->num_rows > 0) {
        $has_item_discount_column = true;
    }
    if ($discount_column_check instanceof mysqli_result) {
        $discount_column_check->free();
    }

    $patient_uid = strtoupper(trim($_GET['patient_uid'] ?? ''));
    $patient_id  = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

    if ($patient_uid === '' && $patient_id <= 0) {
        throw new Exception('Patient identifier is required.');
    }

    if ($patient_uid !== '') {
        if (!preg_match('/^DC\d{8}$/', $patient_uid)) {
            throw new Exception('Patient ID must be in DCYYYYNNNN format.');
        }
        $stmt_p = $conn->prepare("SELECT id, uid, name, age, sex, mobile_number, city FROM patients WHERE uid = ? LIMIT 1");
        $stmt_p->bind_param('s', $patient_uid);
    } else {
        $stmt_p = $conn->prepare("SELECT id, uid, name, age, sex, mobile_number, city FROM patients WHERE id = ? LIMIT 1");
        $stmt_p->bind_param('i', $patient_id);
    }

    if (!$stmt_p) throw new Exception('Failed to prepare patient lookup.');
    $stmt_p->execute();
    $patient = $stmt_p->get_result()->fetch_assoc();
    $stmt_p->close();

    if (!$patient) throw new Exception('Patient not found.');

    $pid = (int)$patient['id'];

    // Fetch all bills with amounts, payment info, referral doctor
    $stmt_bills = $conn->prepare(
        "SELECT b.id AS bill_id,
                b.created_at AS visit_date,
                b.gross_amount,
                b.discount,
                b.net_amount,
                b.amount_paid,
                b.balance_amount,
                b.payment_mode,
                b.cash_amount,
                b.card_amount,
                b.upi_amount,
                b.other_amount,
                b.payment_status,
                b.referral_type,
                COALESCE(rd.doctor_name, '') AS referral_doctor
         FROM bills b
         LEFT JOIN referral_doctors rd ON rd.id = b.referral_doctor_id
         WHERE b.patient_id = ? AND b.bill_status != 'Void'
         ORDER BY b.created_at DESC"
    );
    if (!$stmt_bills) throw new Exception('Failed to prepare bills query.');
    $stmt_bills->bind_param('i', $pid);
    $stmt_bills->execute();
    $bills_result = $stmt_bills->get_result();

    $bills_map = [];
    while ($row = $bills_result->fetch_assoc()) {
        $row['tests'] = [];
        $row['reportable_tests_count'] = 0;
        $row['completed_tests'] = 0;
        $row['screening_count'] = 0;
        $row['computed_gross_amount'] = 0.0;
        $row['computed_discount'] = 0.0;
        $row['computed_net_amount'] = 0.0;
        $bills_map[(int)$row['bill_id']] = $row;
    }
    $stmt_bills->close();

    // Fetch all test items for those bills
    if (!empty($bills_map)) {
        $bill_ids   = array_keys($bills_map);
        $placeholders = implode(',', array_fill(0, count($bill_ids), '?'));

        $item_discount_expr = $has_item_discount_column
            ? "COALESCE(bi.discount_amount, 0) AS item_discount"
            : "0.00 AS item_discount";
        $screening_expr = $has_screening_table
            ? "COALESCE(bis.screening_amount, 0) AS screening_amount"
            : "0.00 AS screening_amount";
        $screening_join = $has_screening_table
            ? "LEFT JOIN bill_item_screenings bis ON bis.bill_item_id = bi.id"
            : "";

        $stmt_items = $conn->prepare(
            "SELECT bi.bill_id,
                    bi.id AS bill_item_id,
                    t.main_test_name,
                    t.sub_test_name,
                    t.price,
                    bi.report_status,
                    {$item_discount_expr},
                    {$screening_expr}
             FROM bill_items bi
             JOIN tests t ON t.id = bi.test_id
             {$screening_join}
             WHERE bi.bill_id IN ($placeholders) AND bi.item_status = 0
             ORDER BY bi.bill_id DESC, bi.id ASC"
        );
        if (!$stmt_items) throw new Exception('Failed to prepare items query.');
        $types = str_repeat('i', count($bill_ids));
        $stmt_items->bind_param($types, ...$bill_ids);
        $stmt_items->execute();
        $items_result = $stmt_items->get_result();

        $all_scans_map = [];
        while ($item = $items_result->fetch_assoc()) {
            $bid = (int)$item['bill_id'];

            if (!isset($bills_map[$bid])) {
                continue;
            }

            $base_price = max(0, (float)$item['price']);
            $screening_amount = max(0, (float)($item['screening_amount'] ?? 0));
            $line_gross = round($base_price + $screening_amount, 2);

            $item_discount = max(0, (float)($item['item_discount'] ?? 0));
            if ($item_discount > $line_gross) {
                $item_discount = $line_gross;
            }

            $line_net = round(max($line_gross - $item_discount, 0), 2);
            $main_test_name = (string)($item['main_test_name'] ?? '');
            $sub_test_name = (string)($item['sub_test_name'] ?? '');
            $report_status = (string)($item['report_status'] ?? 'Pending');

            $bills_map[$bid]['tests'][] = [
                'item_type'      => 'test',
                'main_test_name' => $main_test_name,
                'sub_test_name'  => $sub_test_name,
                'price'          => round($base_price, 2),
                'report_status'  => $report_status,
            ];

            $bills_map[$bid]['reportable_tests_count']++;
            if ($report_status === 'Completed') {
                $bills_map[$bid]['completed_tests']++;
            }

            if ($screening_amount > 0) {
                $screening_label = trim($main_test_name . ($sub_test_name !== '' ? ' - ' . $sub_test_name : '') . ' Screening');
                $bills_map[$bid]['tests'][] = [
                    'item_type'      => 'screening',
                    'main_test_name' => $main_test_name,
                    'sub_test_name'  => $sub_test_name,
                    'label'          => $screening_label,
                    'price'          => round($screening_amount, 2),
                    'report_status'  => 'N/A',
                ];
                $bills_map[$bid]['screening_count']++;
            }

            $bills_map[$bid]['computed_gross_amount'] += $line_gross;
            $bills_map[$bid]['computed_discount'] += $item_discount;
            $bills_map[$bid]['computed_net_amount'] += $line_net;

            $key = $main_test_name . '|' . $sub_test_name;
            $all_scans_map[$key] = [
                'main_test_name' => $main_test_name,
                'sub_test_name'  => $sub_test_name,
            ];
        }
        $stmt_items->close();
    } else {
        $all_scans_map = [];
    }

    $billing_summary = [
        'gross_amount' => 0.0,
        'discount' => 0.0,
        'net_amount' => 0.0,
        'amount_paid' => 0.0,
        'balance_amount' => 0.0,
    ];
    $total_items_count = 0;
    $total_tests_count = 0;
    $total_screenings_count = 0;

    foreach ($bills_map as &$visit) {
        $visit['tests'] = $visit['tests'] ?? [];
        $visit['tests_count'] = count($visit['tests']);

        $visit['reportable_tests_count'] = (int)($visit['reportable_tests_count'] ?? 0);
        $visit['completed_tests'] = (int)($visit['completed_tests'] ?? 0);
        if ($visit['completed_tests'] > $visit['reportable_tests_count']) {
            $visit['completed_tests'] = $visit['reportable_tests_count'];
        }

        $visit['pending_tests'] = max(0, $visit['reportable_tests_count'] - $visit['completed_tests']);

        $has_item_rows = $visit['reportable_tests_count'] > 0 || (int)($visit['screening_count'] ?? 0) > 0;
        $computed_gross = round((float)($visit['computed_gross_amount'] ?? 0), 2);
        $computed_discount = round((float)($visit['computed_discount'] ?? 0), 2);
        $stored_discount = round(max(0, (float)($visit['discount'] ?? 0)), 2);

        if ($has_item_rows) {
            $gross_amount = max(0, $computed_gross);
            $discount_candidate = max(0, $computed_discount);
            if ($discount_candidate <= 0.01 && $stored_discount > 0.01) {
                $discount_candidate = $stored_discount;
            }
            $discount_amount = min($discount_candidate, $gross_amount);
            $net_amount = round(max($gross_amount - $discount_amount, 0), 2);
        } else {
            $gross_amount = round(max(0, (float)($visit['gross_amount'] ?? 0)), 2);
            $discount_amount = round(min($stored_discount, $gross_amount), 2);
            $net_amount = round(max($gross_amount - $discount_amount, 0), 2);
        }

        $amount_paid = round(max(0, (float)($visit['amount_paid'] ?? 0)), 2);
        $pending_amount = round(max($net_amount - $amount_paid, 0), 2);

        $derived_payment_status = 'Pending';
        if ($pending_amount <= 0.01 && $amount_paid + 0.01 >= $net_amount) {
            $derived_payment_status = 'Paid';
        } elseif ($amount_paid > 0.01 && $pending_amount > 0.01) {
            $derived_payment_status = 'Partial Paid';
        }

        $visit['stored_payment_status'] = $visit['payment_status'];
        $visit['payment_status'] = $derived_payment_status;
        $visit['gross_amount'] = $gross_amount;
        $visit['discount'] = $discount_amount;
        $visit['net_amount'] = $net_amount;
        $visit['amount_paid'] = $amount_paid;
        $visit['balance_amount'] = $pending_amount;
        $visit['computed_from_items'] = $has_item_rows;

        $visit['payment_mode_display'] = format_payment_mode_display($visit);

        $billing_summary['gross_amount'] += $gross_amount;
        $billing_summary['discount'] += $discount_amount;
        $billing_summary['net_amount'] += $net_amount;
        $billing_summary['amount_paid'] += $amount_paid;
        $billing_summary['balance_amount'] += $pending_amount;

        $total_items_count += $visit['tests_count'];
        $total_tests_count += $visit['reportable_tests_count'];
        $total_screenings_count += (int)($visit['screening_count'] ?? 0);
    }
    unset($visit);

    $visits = array_values($bills_map);

    echo json_encode([
        'success'            => true,
        'patient'            => $patient,
        'visit_count'        => count($visits),
        'visits'             => $visits,
        'all_scans'          => array_values($all_scans_map),
        'total_unique_scans' => $total_items_count,
        'total_items'        => $total_items_count,
        'total_tests'        => $total_tests_count,
        'total_screenings'   => $total_screenings_count,
        'billing_summary'    => [
            'gross_amount' => round($billing_summary['gross_amount'], 2),
            'discount' => round($billing_summary['discount'], 2),
            'net_amount' => round($billing_summary['net_amount'], 2),
            'amount_paid' => round($billing_summary['amount_paid'], 2),
            'balance_amount' => round($billing_summary['balance_amount'], 2),
        ],
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
