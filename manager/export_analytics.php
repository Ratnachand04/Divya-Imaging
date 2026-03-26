<?php
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$referral_type = isset($_GET['referral_type']) ? $_GET['referral_type'] : 'all';
$doctor_id = isset($_GET['doctor_id']) && $_GET['doctor_id'] !== 'all' ? (int)$_GET['doctor_id'] : 'all';
$receptionist_id = isset($_GET['receptionist_id']) && $_GET['receptionist_id'] !== 'all' ? (int)$_GET['receptionist_id'] : 'all';
$main_test = isset($_GET['main_test']) ? $_GET['main_test'] : 'all';
$sub_test_id = isset($_GET['sub_test']) && $_GET['sub_test'] !== 'all' ? (int)$_GET['sub_test'] : 'all';

$showReferredByColumn = true;
$showReceptionistColumn = true;
$showMainTestColumn = true;
$showSubTestColumn = true;

$end_date_for_query = $end_date . ' 23:59:59';
$types = 'ss';
$params = [$start_date, $end_date_for_query];

$filter_details = [];
$filter_details[] = ['Report Generated On', date('d-M-Y H:i')];
$filter_details[] = ['Reporting Period', date('d-M-Y', strtotime($start_date)) . ' to ' . date('d-M-Y', strtotime($end_date))];

if ($referral_type !== 'all') {
    $where_referral = ucfirst($referral_type);
    $showReferredByColumn = ($referral_type !== 'Self');
} else {
    $where_referral = 'All Referral Types';
}
$filter_details[] = ['Referral Type', $where_referral];

$doctor_label = 'All Doctors';
if ($doctor_id !== 'all') {
    $doc_stmt = $conn->prepare("SELECT doctor_name FROM referral_doctors WHERE id = ?");
    $doc_stmt->bind_param('i', $doctor_id);
    $doc_stmt->execute();
    $doctor_label = $doc_stmt->get_result()->fetch_assoc()['doctor_name'] ?? 'N/A';
    $doc_stmt->close();
    $showReferredByColumn = false;
}
$filter_details[] = ['Doctor', $doctor_label];

$receptionist_label = 'All Receptionists';
if ($receptionist_id !== 'all') {
    $rec_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $rec_stmt->bind_param('i', $receptionist_id);
    $rec_stmt->execute();
    $receptionist_label = $rec_stmt->get_result()->fetch_assoc()['username'] ?? 'N/A';
    $rec_stmt->close();
    $showReceptionistColumn = false;
}
$filter_details[] = ['Receptionist', $receptionist_label];

$test_category_label = 'All Categories';
if ($main_test !== 'all') {
    $test_category_label = $main_test;
    $showMainTestColumn = false;
}
$filter_details[] = ['Test Category', $test_category_label];

$sub_test_label = 'All Tests';
if ($sub_test_id !== 'all') {
    $sub_stmt = $conn->prepare("SELECT sub_test_name FROM tests WHERE id = ?");
    $sub_stmt->bind_param('i', $sub_test_id);
    $sub_stmt->execute();
    $sub_test_label = $sub_stmt->get_result()->fetch_assoc()['sub_test_name'] ?? 'N/A';
    $sub_stmt->close();
    $showSubTestColumn = false;
}
$filter_details[] = ['Specific Test', $sub_test_label];

if (!function_exists('calculateDoctorProfessionalCharge')) {
    function calculateDoctorProfessionalCharge(array $row): float {
        if (($row['referral_type'] ?? '') !== 'Doctor') {
            return 0.0;
        }
        $base = $row['specific_payable_amount'] ?? $row['default_payable_amount'] ?? 0;
        $base = (float) $base;
        if ($base <= 0) {
            return 0.0;
        }
        $itemDiscount = (float)($row['item_discount'] ?? 0);
        $discountBy = $row['discount_by'] ?? null;
        if ($discountBy === 'Doctor' && $itemDiscount > $base) {
            return 0.0;
        }
        return $base;
    }
}

$base_query_from = "
    FROM bills b
    JOIN patients p ON b.patient_id = p.id
    JOIN users u ON b.receptionist_id = u.id
    JOIN bill_items bi ON b.id = bi.bill_id AND bi.item_status = 0
    JOIN tests t ON bi.test_id = t.id
    LEFT JOIN bill_item_screenings bis ON bis.bill_item_id = bi.id
    LEFT JOIN referral_doctors rd ON b.referral_doctor_id = rd.id
    LEFT JOIN doctor_test_payables dtp ON rd.id = dtp.doctor_id AND bi.test_id = dtp.test_id
";

$where_clauses = ["b.created_at BETWEEN ? AND ?", "b.bill_status != 'Void'"];

if ($referral_type !== 'all') { $where_clauses[] = "b.referral_type = ?"; $params[] = $referral_type; $types .= 's'; }
if ($doctor_id !== 'all') { $where_clauses[] = "b.referral_doctor_id = ?"; $params[] = $doctor_id; $types .= 'i'; }
if ($receptionist_id !== 'all') { $where_clauses[] = "b.receptionist_id = ?"; $params[] = $receptionist_id; $types .= 'i'; }
if ($main_test !== 'all') { $where_clauses[] = "t.main_test_name = ?"; $params[] = $main_test; $types .= 's'; }
if ($sub_test_id !== 'all') { $where_clauses[] = "t.id = ?"; $params[] = $sub_test_id; $types .= 'i'; }

$where_sql = ' WHERE ' . implode(' AND ', $where_clauses);

$data_query = "SELECT
        b.id AS bill_id,
        p.name AS patient_name,
        b.created_at,
        u.username AS receptionist_name,
        b.referral_type,
        b.referral_source_other,
        rd.doctor_name,
        t.main_test_name,
        t.sub_test_name,
        t.price AS test_price,
        COALESCE(bis.screening_amount, 0) AS screening_amount,
        COALESCE(bi.discount_amount, 0) AS item_discount,
        b.discount_by,
        t.default_payable_amount,
        dtp.payable_amount AS specific_payable_amount
    " . $base_query_from . $where_sql . " ORDER BY b.id DESC, bi.id ASC";

$stmt = $conn->prepare($data_query);
if ($stmt === false) {
    die('Error preparing analytics export query: ' . $conn->error);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$filename = 'diagnostic_analytics_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Detailed Analytics Export']);
fputcsv($output, []);
foreach ($filter_details as $detailRow) {
    fputcsv($output, $detailRow);
}
fputcsv($output, []);

$headerRow = ['S.No.', 'Bill ID', 'Patient'];
if ($showReceptionistColumn) { $headerRow[] = 'Receptionist'; }
if ($showReferredByColumn) { $headerRow[] = 'Referred By'; }
if ($showMainTestColumn) { $headerRow[] = 'Main Test'; }
if ($showSubTestColumn) { $headerRow[] = 'Sub Test'; }
$headerRow[] = 'Item Discount (₹)';
$headerRow[] = 'Test Total (₹)';
$headerRow[] = 'Doctor Payable (₹)';
$headerRow[] = 'Date';
fputcsv($output, $headerRow);

$serial = 1;
while ($row = $result->fetch_assoc()) {
    $referred_by = 'Self';
    if ($row['referral_type'] === 'Doctor' && !empty($row['doctor_name'])) {
        $referred_by = $row['doctor_name'];
    } elseif ($row['referral_type'] === 'Other') {
        $referred_by = 'Other (' . $row['referral_source_other'] . ')';
    }

    $test_total = (float)$row['test_price'] + (float)$row['screening_amount'];
    $item_discount = (float)$row['item_discount'];
    $doctor_payable = calculateDoctorProfessionalCharge($row);

    $csvRow = [$serial++, $row['bill_id'], $row['patient_name']];
    if ($showReceptionistColumn) { $csvRow[] = $row['receptionist_name']; }
    if ($showReferredByColumn) { $csvRow[] = $referred_by; }
    if ($showMainTestColumn) { $csvRow[] = $row['main_test_name']; }
    if ($showSubTestColumn) { $csvRow[] = $row['sub_test_name']; }
    $csvRow[] = number_format($item_discount, 2, '.', '');
    $csvRow[] = number_format($test_total, 2, '.', '');
    $csvRow[] = number_format($doctor_payable, 2, '.', '');
    $csvRow[] = date('d-m-Y', strtotime($row['created_at']));
    fputcsv($output, $csvRow);
}

fclose($output);
$stmt->close();
exit;
?>