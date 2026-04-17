<?php
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

try {
    ensure_patient_registration_schema($conn);
    ensure_bill_payment_split_columns($conn);

    $patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
    $bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
    $referral_doctors_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd') : '`referral_doctors` rd';
    $bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
    $tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';

    $patient_uid = strtoupper(trim($_GET['patient_uid'] ?? ''));
    $patient_id  = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

    if ($patient_uid === '' && $patient_id <= 0) {
        throw new Exception('Patient identifier is required.');
    }

    if ($patient_uid !== '') {
        if (!preg_match('/^DC\d{8}$/', $patient_uid)) {
            throw new Exception('Patient ID must be in DCYYYYNNNN format.');
        }
        $stmt_p = $conn->prepare("SELECT p.id, p.uid, p.name, p.age, p.sex, p.mobile_number, p.city FROM {$patients_source} WHERE p.uid = ? LIMIT 1");
        $stmt_p->bind_param('s', $patient_uid);
    } else {
        $stmt_p = $conn->prepare("SELECT p.id, p.uid, p.name, p.age, p.sex, p.mobile_number, p.city FROM {$patients_source} WHERE p.id = ? LIMIT 1");
        $stmt_p->bind_param('i', $patient_id);
    }

    if (!$stmt_p) {
        throw new Exception('Failed to prepare patient lookup.');
    }
    $stmt_p->execute();
    $patient = $stmt_p->get_result()->fetch_assoc();
    $stmt_p->close();

    if (!$patient) {
        throw new Exception('Patient not found.');
    }

    $pid = (int)$patient['id'];

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
         FROM {$bills_source}
         LEFT JOIN {$referral_doctors_source} ON rd.id = b.referral_doctor_id
         WHERE b.patient_id = ? AND b.bill_status != 'Void'
         ORDER BY b.created_at DESC"
    );
    if (!$stmt_bills) {
        throw new Exception('Failed to prepare bills query.');
    }
    $stmt_bills->bind_param('i', $pid);
    $stmt_bills->execute();
    $bills_result = $stmt_bills->get_result();

    $bills_map = [];
    while ($row = $bills_result->fetch_assoc()) {
        $bills_map[(int)$row['bill_id']] = $row;
    }
    $stmt_bills->close();

    if (!empty($bills_map)) {
        $bill_ids = array_keys($bills_map);
        $placeholders = implode(',', array_fill(0, count($bill_ids), '?'));
        $stmt_items = $conn->prepare(
            "SELECT bi.bill_id,
                    t.main_test_name,
                    t.sub_test_name,
                    t.price,
                    bi.report_status
               FROM {$bill_items_source}
               JOIN {$tests_source} ON t.id = bi.test_id
             WHERE bi.bill_id IN ($placeholders) AND bi.item_status = 0
             ORDER BY t.main_test_name, t.sub_test_name"
        );
        if (!$stmt_items) {
            throw new Exception('Failed to prepare items query.');
        }
        $types = str_repeat('i', count($bill_ids));
        $stmt_items->bind_param($types, ...$bill_ids);
        $stmt_items->execute();
        $items_result = $stmt_items->get_result();

        $all_scans_map = [];
        while ($item = $items_result->fetch_assoc()) {
            $bid = (int)$item['bill_id'];
            if (!isset($bills_map[$bid]['tests'])) {
                $bills_map[$bid]['tests'] = [];
            }
            $bills_map[$bid]['tests'][] = [
                'main_test_name' => $item['main_test_name'],
                'sub_test_name'  => $item['sub_test_name'],
                'price'          => $item['price'],
                'report_status'  => $item['report_status'],
            ];
            $key = $item['main_test_name'] . '|' . ($item['sub_test_name'] ?? '');
            $all_scans_map[$key] = [
                'main_test_name' => $item['main_test_name'],
                'sub_test_name'  => $item['sub_test_name'],
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
    $total_scans_count = 0;

    foreach ($bills_map as &$visit) {
        $visit['tests'] = $visit['tests'] ?? [];
        $visit['tests_count'] = count($visit['tests']);
        $visit['completed_tests'] = 0;
        foreach ($visit['tests'] as $test_row) {
            if (($test_row['report_status'] ?? '') === 'Completed') {
                $visit['completed_tests']++;
            }
        }
        $visit['pending_tests'] = max(0, $visit['tests_count'] - $visit['completed_tests']);
        $visit['payment_mode_display'] = format_payment_mode_display($visit);

        $billing_summary['gross_amount'] += (float)($visit['gross_amount'] ?? 0);
        $billing_summary['discount'] += (float)($visit['discount'] ?? 0);
        $billing_summary['net_amount'] += (float)($visit['net_amount'] ?? 0);
        $billing_summary['amount_paid'] += (float)($visit['amount_paid'] ?? 0);
        $billing_summary['balance_amount'] += (float)($visit['balance_amount'] ?? 0);
        $total_scans_count += $visit['tests_count'];
    }
    unset($visit);

    $visits = array_values($bills_map);

    echo json_encode([
        'success'            => true,
        'patient'            => $patient,
        'visit_count'        => count($visits),
        'visits'             => $visits,
        'all_scans'          => array_values($all_scans_map),
        'total_unique_scans' => $total_scans_count,
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
