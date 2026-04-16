<?php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$defaultStartDate = date('Y-m-01');
$defaultEndDate = date('Y-m-d');

$startDate = $_GET['start_date'] ?? $defaultStartDate;
$endDate = $_GET['end_date'] ?? $defaultEndDate;

$startObj = DateTime::createFromFormat('Y-m-d', $startDate);
if (!$startObj || $startObj->format('Y-m-d') !== $startDate) {
    $startObj = new DateTime($defaultStartDate);
}

$endObj = DateTime::createFromFormat('Y-m-d', $endDate);
if (!$endObj || $endObj->format('Y-m-d') !== $endDate) {
    $endObj = new DateTime($defaultEndDate);
}

if ($endObj < $startObj) {
    $endObj = clone $startObj;
}

$startDateSql = $conn->real_escape_string($startObj->format('Y-m-d') . ' 00:00:00');
$endDateSql = $conn->real_escape_string($endObj->format('Y-m-d') . ' 23:59:59');

$majorTests = [];

$majorSql = "
    SELECT
        t.main_test_name AS major_test_name,
        COUNT(CASE WHEN b.id IS NOT NULL THEN bi.id END) AS total_test_count,
        COALESCE(SUM(CASE WHEN b.bill_status != 'Void' AND b.created_at BETWEEN '{$startDateSql}' AND '{$endDateSql}' THEN (t.price - bi.discount_amount) ELSE 0 END), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN bi.report_status = 'Completed' AND b.bill_status != 'Void' AND b.created_at BETWEEN '{$startDateSql}' AND '{$endDateSql}' THEN 1 ELSE 0 END), 0) AS total_reports_done
    FROM tests t
    LEFT JOIN bill_items bi ON bi.test_id = t.id AND bi.item_status = 0
    LEFT JOIN bills b ON b.id = bi.bill_id AND b.bill_status != 'Void' AND b.created_at BETWEEN '{$startDateSql}' AND '{$endDateSql}'
    GROUP BY t.main_test_name
    ORDER BY t.main_test_name ASC
";

$majorResult = $conn->query($majorSql);

if (!$majorResult) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to fetch major tests']);
    exit;
}

$subSql = "
    SELECT
        t.main_test_name AS major_test_name,
        COALESCE(NULLIF(t.sub_test_name, ''), t.main_test_name) AS sub_test_name,
        COALESCE(SUM(CASE WHEN b.bill_status != 'Void' AND b.created_at BETWEEN '{$startDateSql}' AND '{$endDateSql}' THEN (t.price - bi.discount_amount) ELSE 0 END), 0) AS revenue,
        COUNT(CASE WHEN b.id IS NOT NULL THEN bi.id END) AS billed_count,
        COUNT(CASE WHEN b.id IS NOT NULL THEN bi.id END) AS performed_count,
        COALESCE(SUM(CASE WHEN bi.report_status = 'Completed' AND b.bill_status != 'Void' AND b.created_at BETWEEN '{$startDateSql}' AND '{$endDateSql}' THEN 1 ELSE 0 END), 0) AS done_count
    FROM tests t
    LEFT JOIN bill_items bi ON bi.test_id = t.id AND bi.item_status = 0
    LEFT JOIN bills b ON b.id = bi.bill_id AND b.bill_status != 'Void' AND b.created_at BETWEEN '{$startDateSql}' AND '{$endDateSql}'
    GROUP BY t.main_test_name, t.id, sub_test_name
    ORDER BY t.main_test_name ASC, sub_test_name ASC
";

$subResult = $conn->query($subSql);

if (!$subResult) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to fetch sub tests']);
    exit;
}

$subGrouped = [];
while ($sub = $subResult->fetch_assoc()) {
    $key = $sub['major_test_name'];
    if (!isset($subGrouped[$key])) {
        $subGrouped[$key] = [];
    }

    $subGrouped[$key][] = [
        'subTestName' => $sub['sub_test_name'],
        'revenue' => (float)$sub['revenue'],
        'billedCount' => (int)$sub['billed_count'],
        'performedCount' => (int)$sub['performed_count'],
        'doneCount' => (int)$sub['done_count']
    ];
}

while ($major = $majorResult->fetch_assoc()) {
    $name = $major['major_test_name'];
    $majorTests[] = [
        'majorTestName' => $name,
        'totalTestCount' => (int)$major['total_test_count'],
        'totalRevenue' => (float)$major['total_revenue'],
        'totalReportsDone' => (int)$major['total_reports_done'],
        'subTests' => $subGrouped[$name] ?? []
    ];
}

echo json_encode(['majorTests' => $majorTests]);
