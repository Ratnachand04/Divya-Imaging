<?php
header('Content-Type: application/json');
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_package_management_schema($conn);

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

$response = [
    'kpis' => [],
    'charts' => []
];

// --- 1. KPIs Query (UPDATED to include pending_bills_count) ---
$stmt_kpis = $conn->prepare(
    "SELECT
        COUNT(DISTINCT patient_id) as total_patients,
        COUNT(id) as total_bills,
        (
            SELECT COUNT(*)
            FROM bill_items bi
            JOIN bills b2 ON bi.bill_id = b2.id
            WHERE bi.item_status = 0
                            AND COALESCE(bi.item_type, 'test') <> 'package'
              AND b2.bill_status != 'Void'
              AND DATE(b2.created_at) BETWEEN ? AND ?
        ) as tests_performed,
        SUM(net_amount) as total_revenue,
        (
            SELECT COUNT(id)
            FROM bills b3
                        WHERE b3.bill_status != 'Void'
                            AND ROUND(GREATEST(b3.net_amount - COALESCE(b3.amount_paid, 0), 0), 2) > 0.01
              AND DATE(b3.created_at) BETWEEN ? AND ?
        ) as pending_bills_count
    FROM bills
    WHERE bill_status != 'Void' AND DATE(created_at) BETWEEN ? AND ?"
);
// Bind dates for subqueries and main query
$stmt_kpis->bind_param("ssssss", $start_date, $end_date, $start_date, $end_date, $start_date, $end_date);
$stmt_kpis->execute();
$response['kpis'] = $stmt_kpis->get_result()->fetch_assoc();
$stmt_kpis->close();

$response['kpis']['total_packages'] = 0;
$response['kpis']['active_packages'] = 0;
$response['kpis']['packages_sold'] = 0;
$response['kpis']['package_revenue'] = 0;
$response['kpis']['most_sold_package'] = 'N/A';

$packages_count_result = $conn->query("SELECT COUNT(*) AS total_packages, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_packages FROM test_packages");
if ($packages_count_result instanceof mysqli_result) {
    $pkg_counts = $packages_count_result->fetch_assoc();
    $response['kpis']['total_packages'] = (int)($pkg_counts['total_packages'] ?? 0);
    $response['kpis']['active_packages'] = (int)($pkg_counts['active_packages'] ?? 0);
    $packages_count_result->free();
}

$package_sales_stmt = $conn->prepare(
    "SELECT
        COALESCE(NULLIF(bi.package_name, ''), tp.package_name, 'Package') AS package_name,
        COUNT(DISTINCT bi.id) AS package_sales_count,
        COALESCE(SUM(pkg_totals.package_total), 0) AS package_revenue
     FROM bill_items bi
     JOIN bills b ON b.id = bi.bill_id
     LEFT JOIN test_packages tp ON tp.id = bi.package_id
     LEFT JOIN (
        SELECT bill_item_id, SUM(package_test_price) AS package_total
        FROM bill_package_items
        GROUP BY bill_item_id
     ) AS pkg_totals ON pkg_totals.bill_item_id = bi.id
     WHERE bi.item_status = 0
       AND COALESCE(bi.item_type, 'test') = 'package'
       AND b.bill_status != 'Void'
       AND DATE(b.created_at) BETWEEN ? AND ?
     GROUP BY bi.package_id, package_name
     ORDER BY package_sales_count DESC"
);

$package_sales_rows = [];
if ($package_sales_stmt) {
    $package_sales_stmt->bind_param('ss', $start_date, $end_date);
    $package_sales_stmt->execute();
    $package_sales_rows = $package_sales_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $package_sales_stmt->close();
}

if (!empty($package_sales_rows)) {
    $total_package_sales = 0;
    $total_package_revenue = 0.0;
    foreach ($package_sales_rows as $pkg_row) {
        $total_package_sales += (int)($pkg_row['package_sales_count'] ?? 0);
        $total_package_revenue += (float)($pkg_row['package_revenue'] ?? 0);
    }
    $response['kpis']['packages_sold'] = $total_package_sales;
    $response['kpis']['package_revenue'] = round($total_package_revenue, 2);
    $response['kpis']['most_sold_package'] = (string)($package_sales_rows[0]['package_name'] ?? 'N/A');
}


// --- (The rest of the queries for your charts remain unchanged) ---

// --- 2. Top 5 Test Categories Chart ---
$stmt_test_cat = $conn->prepare(
        "SELECT t.main_test_name, COUNT(bi.id) as count
         FROM bill_items bi
         JOIN tests t ON bi.test_id = t.id
         JOIN bills b ON bi.bill_id = b.id
         WHERE b.bill_status != 'Void'
             AND bi.item_status = 0
             AND DATE(b.created_at) BETWEEN ? AND ?
         GROUP BY t.main_test_name
         ORDER BY count DESC
         LIMIT 5"
);
$stmt_test_cat->bind_param("ss", $start_date, $end_date);
$stmt_test_cat->execute();
$result = $stmt_test_cat->get_result()->fetch_all(MYSQLI_ASSOC);
$response['charts']['top_test_categories'] = [ 'labels' => array_column($result, 'main_test_name'), 'data' => array_column($result, 'count') ];
$stmt_test_cat->close();

// --- 3. Referral Sources Chart ---
$stmt_referral = $conn->prepare(
        "SELECT referral_type, COUNT(id) as count
         FROM bills
         WHERE bill_status != 'Void'
             AND DATE(created_at) BETWEEN ? AND ?
         GROUP BY referral_type
         ORDER BY count DESC"
);
$stmt_referral->bind_param("ss", $start_date, $end_date);
$stmt_referral->execute();
$result = $stmt_referral->get_result()->fetch_all(MYSQLI_ASSOC);
$response['charts']['referral_sources'] = [ 'labels' => array_column($result, 'referral_type'), 'data' => array_column($result, 'count') ];
$stmt_referral->close();

// --- 4. Top 5 Referring Doctors Chart ---
$stmt_doctors = $conn->prepare(
        "SELECT rd.id, rd.doctor_name, COUNT(DISTINCT b.patient_id) as patient_count
         FROM bills b
         JOIN referral_doctors rd ON b.referral_doctor_id = rd.id
         WHERE b.bill_status != 'Void'
             AND b.referral_type = 'Doctor'
             AND DATE(b.created_at) BETWEEN ? AND ?
         GROUP BY rd.id, rd.doctor_name
         ORDER BY patient_count DESC
         LIMIT 5"
);
$stmt_doctors->bind_param("ss", $start_date, $end_date);
$stmt_doctors->execute();
$result = $stmt_doctors->get_result()->fetch_all(MYSQLI_ASSOC);
$response['charts']['top_doctors'] = [ 'labels' => array_column($result, 'doctor_name'), 'data' => array_column($result, 'patient_count'), 'ids' => array_column($result, 'id') ];
$stmt_doctors->close();

// --- 5. Revenue by Payment Method Chart ---
$stmt_payment = $conn->prepare(
        "SELECT payment_mode, SUM(net_amount) as total
         FROM bills
         WHERE bill_status != 'Void'
             AND DATE(created_at) BETWEEN ? AND ?
         GROUP BY payment_mode
         ORDER BY total DESC"
);
$stmt_payment->bind_param("ss", $start_date, $end_date);
$stmt_payment->execute();
$result = $stmt_payment->get_result()->fetch_all(MYSQLI_ASSOC);
$normalized_payment_totals = normalize_payment_mode_totals($result);
$response['charts']['payment_modes'] = [
    'labels' => array_keys($normalized_payment_totals),
    'data' => array_values($normalized_payment_totals)
];
$stmt_payment->close();

$top_package_rows = array_slice($package_sales_rows, 0, 5);
$response['charts']['package_sales'] = [
    'labels' => array_map(function($row) { return (string)($row['package_name'] ?? 'Package'); }, $top_package_rows),
    'data' => array_map(function($row) { return (int)($row['package_sales_count'] ?? 0); }, $top_package_rows)
];

echo json_encode($response);
?>