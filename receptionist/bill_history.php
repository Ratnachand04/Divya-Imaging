<?php
$page_title = "Bill Summary";
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_bill_payment_split_columns($conn);

$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$referral_doctors_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd') : '`referral_doctors` rd';
$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';
$bill_item_screenings_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_item_screenings', 'bis') : '`bill_item_screenings` bis';

// --- Handle All Filters ---
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$all_dates = isset($_GET['all_dates']) && $_GET['all_dates'] === '1';
if ($all_dates) {
    $start_date = isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : '2000-01-01';
    $end_date = isset($_GET['end_date']) && $_GET['end_date'] !== '' ? $_GET['end_date'] : date('Y-m-d');
}
$payment_status_filter = isset($_GET['payment_status']) && $_GET['payment_status'] !== 'all' ? $_GET['payment_status'] : 'all';
if ($payment_status_filter === 'Half Paid') {
    $payment_status_filter = 'Partial Paid';
}
$payment_mode_filter = isset($_GET['payment_mode']) && $_GET['payment_mode'] !== 'all' ? $_GET['payment_mode'] : 'all';
if ($payment_mode_filter !== 'all') {
    $payment_mode_filter = format_payment_mode_label($payment_mode_filter);
}
// --- NEW: Get Search Term ---
$search_term = isset($_GET['search_term']) ? trim($_GET['search_term']) : '';
$receptionist_id = $_SESSION['user_id']; //

// --- Build Query Dynamically ---
$base_query = "SELECT
        b.id, p.uid as patient_uid, p.name as patient_name, b.gross_amount, b.discount, b.net_amount,
    b.amount_paid, b.balance_amount, b.created_at, b.payment_mode, b.cash_amount, b.card_amount, b.upi_amount, b.other_amount, b.payment_status, b.referral_type,
        rd.doctor_name as ref_physician_name
    FROM {$bills_source}
    JOIN {$patients_source} ON b.patient_id = p.id
    LEFT JOIN {$referral_doctors_source} ON b.referral_doctor_id = rd.id"; //

$where_clauses = ["b.receptionist_id = ?", "b.bill_status != 'Void'", "DATE(b.created_at) BETWEEN ? AND ?"]; //
$params = [$receptionist_id, $start_date, $end_date]; //
$types = 'iss'; //

$pending_amount_expr = "ROUND(GREATEST(b.net_amount - b.amount_paid, 0), 2)";

if ($payment_status_filter === 'pending') {
    $where_clauses[] = "{$pending_amount_expr} > 0.01";
} elseif ($payment_status_filter === 'Paid') {
    $where_clauses[] = "{$pending_amount_expr} <= 0.01";
} elseif ($payment_status_filter === 'Partial Paid') {
    $where_clauses[] = "b.amount_paid > 0.01 AND {$pending_amount_expr} > 0.01";
} elseif ($payment_status_filter === 'Due') {
    $where_clauses[] = "b.amount_paid <= 0.01 AND {$pending_amount_expr} > 0.01";
} elseif ($payment_status_filter !== 'all') {
    $where_clauses[] = "b.payment_status = ?"; //
    $params[] = $payment_status_filter; //
    $types .= 's'; //
}
if ($payment_mode_filter !== 'all') {
    $normalized_mode_filter = strtolower(str_replace(' ', '', $payment_mode_filter));
    if ($normalized_mode_filter === 'cash+card') {
        $where_clauses[] = "REPLACE(LOWER(b.payment_mode), ' ', '') IN ('cash+card','card+cash')";
    } elseif ($normalized_mode_filter === 'upi+cash') {
        $where_clauses[] = "REPLACE(LOWER(b.payment_mode), ' ', '') IN ('upi+cash','cash+upi')";
    } elseif ($normalized_mode_filter === 'card+upi') {
        $where_clauses[] = "REPLACE(LOWER(b.payment_mode), ' ', '') IN ('card+upi','upi+card')";
    } else {
        $where_clauses[] = "REPLACE(LOWER(b.payment_mode), ' ', '') = ?";
        $params[] = $normalized_mode_filter;
        $types .= 's';
    }
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

$normalized_mode_expr = "REPLACE(LOWER(b.payment_mode), ' ', '')";
$mode_sort_expr = "CASE
    WHEN {$normalized_mode_expr} IN ('cash+card','card+cash') THEN 'cash+card'
    WHEN {$normalized_mode_expr} IN ('upi+cash','cash+upi') THEN 'upi+cash'
    WHEN {$normalized_mode_expr} IN ('card+upi','upi+card') THEN 'card+upi'
    ELSE {$normalized_mode_expr}
END";

$query = $base_query . " WHERE " . implode(' AND ', $where_clauses) . " ORDER BY {$mode_sort_expr}, b.id ASC"; //

$stmt = $conn->prepare($query);
// --- UPDATED: Use spread operator for dynamic params ---
$stmt->bind_param($types, ...$params);
$stmt->execute();
$bills_result = $stmt->get_result();
$bills = $bills_result->fetch_all(MYSQLI_ASSOC); //
$stmt->close();

$summary_date = isset($_GET['summary_date']) ? trim($_GET['summary_date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $summary_date)) {
    $summary_date = date('Y-m-d');
}

$summary_categories = ['CT', 'ECG', 'ECHO', 'LAB', 'MAMMOGRAPHY', 'MRI', 'USG', 'X-RAY'];

function bh_amount_template() {
    return [
        'revenue' => 0.0,
        'cash' => 0.0,
        'card' => 0.0,
        'upi' => 0.0,
        'pending' => 0.0,
        'discount' => 0.0,
    ];
}

function bh_sanitize_amount($value) {
    return round(max(0.0, (float)$value), 2);
}

function bh_format_inr($value) {
    return '₹' . number_format((float)$value, 2);
}

function bh_csv_amount($value) {
    return number_format((float)$value, 2, '.', '');
}

function bh_has_table(mysqli $conn, $table_name) {
    if (!function_exists('schema_has_table')) {
        return false;
    }
    return schema_has_table($conn, (string)$table_name);
}

function bh_has_column(mysqli $conn, $table_name, $column_name) {
    if (!function_exists('schema_has_column')) {
        return false;
    }
    return schema_has_column($conn, (string)$table_name, (string)$column_name);
}

function bh_normalize_category($raw_category) {
    $token = strtoupper(trim((string)$raw_category));
    $token = preg_replace('/[^A-Z]/', '', $token);

    $map = [
        'CT' => 'CT',
        'ECG' => 'ECG',
        'ECHO' => 'ECHO',
        'LAB' => 'LAB',
        'LABORATORY' => 'LAB',
        'PATHOLOGY' => 'LAB',
        'MAMMOGRAPHY' => 'MAMMOGRAPHY',
        'MAMMO' => 'MAMMOGRAPHY',
        'MRI' => 'MRI',
        'USG' => 'USG',
        'ULTRASOUND' => 'USG',
        'ULTRASONOGRAPHY' => 'USG',
        'XRAY' => 'X-RAY',
    ];

    if (isset($map[$token])) {
        return $map[$token];
    }

    if (strpos($token, 'XRAY') !== false) {
        return 'X-RAY';
    }
    if (strpos($token, 'MAMMO') !== false) {
        return 'MAMMOGRAPHY';
    }
    if (strpos($token, 'ULTRA') !== false || strpos($token, 'USG') !== false) {
        return 'USG';
    }
    if (strpos($token, 'ECHO') !== false) {
        return 'ECHO';
    }
    if (strpos($token, 'ECG') !== false) {
        return 'ECG';
    }
    if (strpos($token, 'MRI') !== false) {
        return 'MRI';
    }
    if (strpos($token, 'CT') === 0) {
        return 'CT';
    }
    if (strpos($token, 'LAB') !== false || strpos($token, 'PATH') !== false) {
        return 'LAB';
    }

    return null;
}

function bh_distribute_by_weights(array $weights, $amount) {
    $distribution = array_fill(0, count($weights), 0.0);
    $clean_amount = bh_sanitize_amount($amount);
    if ($clean_amount <= 0.0 || empty($weights)) {
        return $distribution;
    }

    $total_weight = 0.0;
    foreach ($weights as $weight) {
        $total_weight += max(0.0, (float)$weight);
    }

    if ($total_weight <= 0.0) {
        return $distribution;
    }

    $allocated = 0.0;
    $last_index = count($weights) - 1;

    foreach ($weights as $index => $weight) {
        $safe_weight = max(0.0, (float)$weight);
        if ($index === $last_index) {
            $distribution[$index] = round(max($clean_amount - $allocated, 0.0), 2);
            break;
        }

        $portion = round(($clean_amount * $safe_weight) / $total_weight, 2);
        $distribution[$index] = $portion;
        $allocated += $portion;
    }

    return $distribution;
}

$daily_summary = [];
foreach ($summary_categories as $category_name) {
    $daily_summary[$category_name] = [
        'items' => [],
        'totals' => bh_amount_template(),
    ];
}
$daily_grand_totals = bh_amount_template();

$daily_bills = [];
$daily_bill_stmt = $conn->prepare("SELECT id, net_amount, discount, amount_paid, balance_amount, cash_amount, card_amount, upi_amount, other_amount
                                                                    FROM {$bills_source}
                                                                    WHERE b.receptionist_id = ?
                                                                        AND b.bill_status != 'Void'
                                                                        AND DATE(b.created_at) = ?");
if ($daily_bill_stmt) {
    $daily_bill_stmt->bind_param('is', $receptionist_id, $summary_date);
    $daily_bill_stmt->execute();
    $daily_bill_result = $daily_bill_stmt->get_result();
    while ($row = $daily_bill_result->fetch_assoc()) {
        $row['lines'] = [];
        $daily_bills[(int)$row['id']] = $row;
    }
    $daily_bill_stmt->close();
}

$has_item_discount = bh_has_column($conn, 'bill_items', 'discount_amount');
$has_screening_table = bh_has_table($conn, 'bill_item_screenings');

if (!empty($daily_bills)) {
    $bill_ids = array_keys($daily_bills);
    $placeholders = implode(',', array_fill(0, count($bill_ids), '?'));

    $screening_join_sql = $has_screening_table
        ? "LEFT JOIN {$bill_item_screenings_source} ON bis.bill_item_id = bi.id"
        : '';
    $screening_amount_sql = $has_screening_table
        ? 'COALESCE(bis.screening_amount, 0) AS screening_amount'
        : '0.00 AS screening_amount';
    $item_discount_sql = $has_item_discount
        ? 'COALESCE(bi.discount_amount, 0) AS item_discount'
        : '0.00 AS item_discount';

    $daily_items_sql = "SELECT bi.bill_id,
                               t.main_test_name,
                               t.sub_test_name,
                               COALESCE(t.price, 0) AS base_price,
                               {$item_discount_sql},
                               {$screening_amount_sql}
                                                FROM {$bill_items_source}
                                                JOIN {$tests_source} ON t.id = bi.test_id
                        {$screening_join_sql}
                        WHERE bi.bill_id IN ({$placeholders})
                          AND bi.item_status = 0
                        ORDER BY bi.bill_id ASC, bi.id ASC";

    $daily_items_stmt = $conn->prepare($daily_items_sql);
    if ($daily_items_stmt) {
        $bind_types = str_repeat('i', count($bill_ids));
        $daily_items_stmt->bind_param($bind_types, ...$bill_ids);
        $daily_items_stmt->execute();
        $daily_items_result = $daily_items_stmt->get_result();

        while ($item = $daily_items_result->fetch_assoc()) {
            $bill_id = (int)$item['bill_id'];
            if (!isset($daily_bills[$bill_id])) {
                continue;
            }

            $category = bh_normalize_category($item['main_test_name'] ?? '');
            if ($category === null || !isset($daily_summary[$category])) {
                continue;
            }

            $sub_test_name = trim((string)($item['sub_test_name'] ?? ''));
            if ($sub_test_name === '') {
                $sub_test_name = trim((string)($item['main_test_name'] ?? 'Unnamed Test'));
            }
            if ($sub_test_name === '') {
                $sub_test_name = 'Unnamed Test';
            }

            $base_revenue = bh_sanitize_amount($item['base_price'] ?? 0);
            $item_discount = $has_item_discount ? bh_sanitize_amount($item['item_discount'] ?? 0) : 0.0;
            if ($item_discount > $base_revenue) {
                $item_discount = $base_revenue;
            }
            $base_net = round(max($base_revenue - $item_discount, 0.0), 2);

            if ($base_revenue > 0.0001 || $item_discount > 0.0001) {
                $daily_bills[$bill_id]['lines'][] = [
                    'category' => $category,
                    'name' => $sub_test_name,
                    'revenue' => $base_revenue,
                    'discount' => $item_discount,
                    'net' => $base_net,
                    'cash' => 0.0,
                    'card' => 0.0,
                    'upi' => 0.0,
                    'pending' => 0.0,
                    'is_screening' => false,
                ];
            }

            $screening_revenue = bh_sanitize_amount($item['screening_amount'] ?? 0);
            if ($screening_revenue > 0.0001) {
                $daily_bills[$bill_id]['lines'][] = [
                    'category' => $category,
                    'name' => $sub_test_name . ' Screening',
                    'revenue' => $screening_revenue,
                    'discount' => 0.0,
                    'net' => $screening_revenue,
                    'cash' => 0.0,
                    'card' => 0.0,
                    'upi' => 0.0,
                    'pending' => 0.0,
                    'is_screening' => true,
                ];
            }
        }

        $daily_items_stmt->close();
    }
}

foreach ($daily_bills as &$bill_row) {
    if (empty($bill_row['lines'])) {
        continue;
    }

    if (!$has_item_discount) {
        $bill_discount = bh_sanitize_amount($bill_row['discount'] ?? 0);
        $discount_indexes = [];
        $discount_weights = [];

        foreach ($bill_row['lines'] as $line_index => $line) {
            if (!empty($line['is_screening'])) {
                continue;
            }
            if ((float)$line['revenue'] <= 0.0) {
                continue;
            }
            $discount_indexes[] = $line_index;
            $discount_weights[] = (float)$line['revenue'];
        }

        if ($bill_discount > 0.0 && !empty($discount_weights)) {
            $max_discount = array_sum($discount_weights);
            if ($bill_discount > $max_discount) {
                $bill_discount = $max_discount;
            }

            $discount_allocations = bh_distribute_by_weights($discount_weights, $bill_discount);
            foreach ($discount_indexes as $position => $line_index) {
                $allocated_discount = bh_sanitize_amount($discount_allocations[$position] ?? 0);
                $bill_row['lines'][$line_index]['discount'] = $allocated_discount;
                $bill_row['lines'][$line_index]['net'] = round(max((float)$bill_row['lines'][$line_index]['revenue'] - $allocated_discount, 0.0), 2);
            }
        }
    }

    $line_weights = [];
    foreach ($bill_row['lines'] as $line_index => $line) {
        $net_line = round(max((float)$line['revenue'] - (float)$line['discount'], 0.0), 2);
        $bill_row['lines'][$line_index]['net'] = $net_line;
        $line_weights[] = $net_line;
    }

    $cash_total = bh_sanitize_amount($bill_row['cash_amount'] ?? 0) + bh_sanitize_amount($bill_row['other_amount'] ?? 0);
    $card_total = bh_sanitize_amount($bill_row['card_amount'] ?? 0);
    $upi_total = bh_sanitize_amount($bill_row['upi_amount'] ?? 0);
    $pending_total = bh_sanitize_amount($bill_row['balance_amount'] ?? 0);

    $cash_allocations = bh_distribute_by_weights($line_weights, $cash_total);
    $card_allocations = bh_distribute_by_weights($line_weights, $card_total);
    $upi_allocations = bh_distribute_by_weights($line_weights, $upi_total);
    $pending_allocations = bh_distribute_by_weights($line_weights, $pending_total);

    foreach ($bill_row['lines'] as $line_index => &$line) {
        $line['cash'] = bh_sanitize_amount($cash_allocations[$line_index] ?? 0);
        $line['card'] = bh_sanitize_amount($card_allocations[$line_index] ?? 0);
        $line['upi'] = bh_sanitize_amount($upi_allocations[$line_index] ?? 0);
        $line['pending'] = bh_sanitize_amount($pending_allocations[$line_index] ?? 0);

        $category = $line['category'];
        if (!isset($daily_summary[$category])) {
            continue;
        }

        $item_key = strtoupper(trim((string)$line['name']));
        if ($item_key === '') {
            $item_key = 'UNNAMED TEST';
        }

        if (!isset($daily_summary[$category]['items'][$item_key])) {
            $daily_summary[$category]['items'][$item_key] = [
                'name' => $line['name'],
                'revenue' => 0.0,
                'cash' => 0.0,
                'card' => 0.0,
                'upi' => 0.0,
                'pending' => 0.0,
                'discount' => 0.0,
            ];
        }

        foreach (['revenue', 'cash', 'card', 'upi', 'pending', 'discount'] as $metric) {
            $daily_summary[$category]['items'][$item_key][$metric] += (float)$line[$metric];
            $daily_summary[$category]['totals'][$metric] += (float)$line[$metric];
            $daily_grand_totals[$metric] += (float)$line[$metric];
        }
    }
    unset($line);
}
unset($bill_row);

foreach ($daily_summary as $category_name => &$category_data) {
    if (!empty($category_data['items'])) {
        uasort($category_data['items'], function($left, $right) {
            return strnatcasecmp((string)$left['name'], (string)$right['name']);
        });
    }

    foreach (['revenue', 'cash', 'card', 'upi', 'pending', 'discount'] as $metric) {
        $category_data['totals'][$metric] = bh_sanitize_amount($category_data['totals'][$metric]);
        foreach ($category_data['items'] as $item_key => $item_data) {
            $category_data['items'][$item_key][$metric] = bh_sanitize_amount($item_data[$metric]);
        }
    }
}
unset($category_data);

foreach (['revenue', 'cash', 'card', 'upi', 'pending', 'discount'] as $metric) {
    $daily_grand_totals[$metric] = bh_sanitize_amount($daily_grand_totals[$metric]);
}

$summary_csv_requested = isset($_GET['summary_csv']) && $_GET['summary_csv'] === '1';
if ($summary_csv_requested) {
    $date_for_filename = DateTime::createFromFormat('Y-m-d', $summary_date);
    $csv_date = $date_for_filename ? $date_for_filename->format('d-m-Y') : date('d-m-Y');
    $csv_filename = $csv_date . '_Tests_Amount_Summary.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $csv_filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Test Category', 'Sub Test Name', 'Revenue', 'Cash', 'Card', 'UPI', 'Pending', 'Discount']);

    foreach ($summary_categories as $category_name) {
        $category_totals = $daily_summary[$category_name]['totals'];
        fputcsv($output, [
            $category_name,
            '',
            bh_csv_amount($category_totals['revenue']),
            bh_csv_amount($category_totals['cash']),
            bh_csv_amount($category_totals['card']),
            bh_csv_amount($category_totals['upi']),
            bh_csv_amount($category_totals['pending']),
            bh_csv_amount($category_totals['discount']),
        ]);

        if (!empty($daily_summary[$category_name]['items'])) {
            foreach ($daily_summary[$category_name]['items'] as $item_data) {
                fputcsv($output, [
                    $category_name,
                    $item_data['name'],
                    bh_csv_amount($item_data['revenue']),
                    bh_csv_amount($item_data['cash']),
                    bh_csv_amount($item_data['card']),
                    bh_csv_amount($item_data['upi']),
                    bh_csv_amount($item_data['pending']),
                    bh_csv_amount($item_data['discount']),
                ]);
            }
        }

        fputcsv($output, [
            $category_name,
            'Category Subtotal',
            bh_csv_amount($category_totals['revenue']),
            bh_csv_amount($category_totals['cash']),
            bh_csv_amount($category_totals['card']),
            bh_csv_amount($category_totals['upi']),
            bh_csv_amount($category_totals['pending']),
            bh_csv_amount($category_totals['discount']),
        ]);
    }

    fputcsv($output, [
        'GRAND TOTAL',
        '',
        bh_csv_amount($daily_grand_totals['revenue']),
        bh_csv_amount($daily_grand_totals['cash']),
        bh_csv_amount($daily_grand_totals['card']),
        bh_csv_amount($daily_grand_totals['upi']),
        bh_csv_amount($daily_grand_totals['pending']),
        bh_csv_amount($daily_grand_totals['discount']),
    ]);

    fclose($output);
    exit;
}

require_once '../includes/header.php';
?>

<style>
    .daily-summary-card {
        margin-bottom: 1rem;
        background: #ffffff;
        border: 1px solid #dbe6f4;
        border-radius: 14px;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
        overflow: hidden;
    }

    .daily-summary-details {
        border: 0;
    }

    .daily-summary-details > summary {
        list-style: none;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        padding: 0.85rem 1rem;
        cursor: pointer;
        background: linear-gradient(90deg, #f4fbff 0%, #eef6ff 100%);
        border-bottom: 1px solid #d7e5f6;
        font-size: 1rem;
        font-weight: 800;
        color: #13406e;
    }

    .daily-summary-details > summary::-webkit-details-marker {
        display: none;
    }

    .daily-summary-date-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        border-radius: 999px;
        background: #1f5f95;
        color: #ffffff;
        padding: 0.28rem 0.6rem;
        font-size: 0.74rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .daily-summary-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
        padding: 0.75rem 1rem;
        border-bottom: 1px solid #e5edf8;
        background: #f9fbff;
    }

    .daily-summary-filter-form {
        display: flex;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 0.55rem;
    }

    .daily-summary-filter-form label {
        font-size: 0.75rem;
        font-weight: 700;
        color: #24527f;
        display: block;
        margin-bottom: 0.2rem;
    }

    .daily-summary-filter-form input[type="date"] {
        border: 1px solid #cfe0f3;
        border-radius: 8px;
        padding: 0.48rem 0.6rem;
        min-width: 170px;
        background: #ffffff;
        color: #1e3a5f;
    }

    .daily-summary-filter-btn {
        border: 1px solid #c0d6ee;
        border-radius: 8px;
        background: #2f6fa4;
        color: #ffffff;
        padding: 0.5rem 0.85rem;
        font-size: 0.8rem;
        font-weight: 700;
        cursor: pointer;
    }

    .daily-summary-filter-btn:hover {
        background: #265d89;
    }

    .daily-summary-download-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.42rem;
        text-decoration: none;
        border-radius: 8px;
        padding: 0.52rem 0.9rem;
        background: #198754;
        color: #ffffff;
        font-size: 0.82rem;
        font-weight: 800;
        border: 1px solid #157347;
        white-space: nowrap;
    }

    .daily-summary-download-btn:hover {
        background: #157347;
        color: #ffffff;
    }

    .daily-summary-table-wrap {
        width: 100%;
        overflow-x: auto;
        padding: 0.2rem 0.6rem 0.75rem;
    }

    .daily-summary-table {
        width: 100%;
        min-width: 1120px;
        border-collapse: collapse;
        table-layout: auto;
    }

    .daily-summary-table th,
    .daily-summary-table td {
        border: 1px solid #dbe5f4;
        padding: 0.5rem 0.65rem;
        font-size: 0.82rem;
        vertical-align: middle;
    }

    .daily-summary-table thead th {
        background: #edf4ff;
        color: #1f4b76;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        font-size: 0.74rem;
        font-weight: 800;
        white-space: nowrap;
    }

    .daily-summary-table .text-right {
        text-align: right;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
        font-weight: 700;
    }

    .daily-summary-table .category-cell {
        white-space: nowrap;
    }

    .daily-summary-table .category-toggle {
        border: none;
        background: transparent;
        color: #194770;
        cursor: pointer;
        font-size: 0.82rem;
        padding: 0;
        margin-right: 0.42rem;
        font-weight: 900;
    }

    .daily-summary-table .daily-category-row td {
        font-weight: 800;
        font-size: 0.84rem;
    }

    .daily-summary-table .daily-cat-ct td { background: #e8fbff; }
    .daily-summary-table .daily-cat-ecg td { background: #e9f5ff; }
    .daily-summary-table .daily-cat-echo td { background: #edf7ff; }
    .daily-summary-table .daily-cat-lab td { background: #ecfff8; }
    .daily-summary-table .daily-cat-mammography td { background: #f0f7ff; }
    .daily-summary-table .daily-cat-mri td { background: #eef3ff; }
    .daily-summary-table .daily-cat-usg td { background: #eafcff; }
    .daily-summary-table .daily-cat-xray td { background: #edf8ff; }

    .daily-summary-table .daily-subtest-row td {
        background: #ffffff;
        font-weight: 500;
    }

    .daily-summary-table .daily-subtest-row.is-alt td {
        background: #f9fbff;
    }

    .daily-summary-table .subtest-name {
        padding-left: 1.2rem;
        color: #1f3552;
    }

    .daily-summary-table .daily-subtotal-row td {
        background: #eef6ff;
        font-weight: 800;
        color: #1c456f;
    }

    .daily-summary-table .daily-grand-total-row td {
        background: #114a73;
        color: #ffffff;
        font-weight: 900;
    }

    @media (max-width: 768px) {
        .daily-summary-details > summary {
            flex-direction: column;
            align-items: flex-start;
        }

        .daily-summary-toolbar {
            padding: 0.7rem 0.75rem;
        }

        .daily-summary-filter-form {
            width: 100%;
        }

        .daily-summary-filter-form input[type="date"] {
            min-width: 155px;
        }
    }

    body.role-receptionist.app-layout .bill-history-table-wrap {
        overflow: visible !important;
    }

    body.role-receptionist.app-layout .bill-history-table {
        width: 100% !important;
        min-width: 0 !important;
        table-layout: fixed !important;
    }

    body.role-receptionist.app-layout .bill-history-table th,
    body.role-receptionist.app-layout .bill-history-table td {
        overflow: visible !important;
        text-overflow: clip !important;
        white-space: normal !important;
        padding: 0.42rem 0.48rem !important;
        line-height: 1.18;
        vertical-align: middle;
    }

    body.role-receptionist.app-layout .bill-history-table thead th {
        font-size: 0.74rem !important;
        letter-spacing: 0.03em;
        font-weight: 700;
        white-space: nowrap !important;
    }

    .bill-history-table .col-bill {
        width: 9%;
    }

    .bill-history-table .col-patient {
        width: 27%;
    }

    .bill-history-table .col-net,
    .bill-history-table .col-discount,
    .bill-history-table .col-paid,
    .bill-history-table .col-pending {
        width: 10%;
    }

    .bill-history-table .col-status {
        width: 7%;
    }

    .bill-history-table .col-actions {
        width: 17%;
    }

    .bill-history-table th.col-net,
    .bill-history-table th.col-discount,
    .bill-history-table th.col-paid,
    .bill-history-table th.col-pending {
        text-align: right !important;
    }

    .bill-history-table th.col-bill,
    .bill-history-table td.col-bill,
    .bill-history-table th.col-patient,
    .bill-history-table td.col-patient {
        text-align: left;
        padding-left: 0.52rem !important;
        padding-right: 0.36rem !important;
    }

    .bill-history-table th.col-status,
    .bill-history-table td.col-status {
        text-align: center;
        padding-left: 0.34rem !important;
        padding-right: 0.34rem !important;
    }

    .bill-history-table th.col-actions {
        text-align: left;
    }

    .bill-history-table .bill-no {
        display: block;
        font-weight: 700;
        font-size: 0.8rem;
        white-space: nowrap;
    }

    .bill-history-table .bill-date {
        display: block;
        margin-top: 0.02rem;
        font-size: 0.67rem;
        line-height: 1.1;
        color: #64748b;
        white-space: nowrap;
    }

    .bill-history-table .patient-name {
        display: block;
        font-weight: 600;
        font-size: 0.8rem;
        line-height: 1.14;
        color: #1e293b;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .bill-history-table .patient-ref {
        display: block;
        margin-top: 0.02rem;
        font-size: 0.67rem;
        line-height: 1.1;
        color: #475569;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .bill-history-table .patient-uid {
        display: block;
        margin-top: 0.02rem;
        font-size: 0.64rem;
        line-height: 1.08;
        color: #64748b;
        white-space: nowrap;
        overflow: visible;
        text-overflow: clip;
    }

    .bill-history-table .amount-col {
        text-align: right;
        font-variant-numeric: tabular-nums;
        white-space: nowrap !important;
        font-weight: 600;
        font-size: 0.79rem;
        line-height: 1.1;
    }

    .bill-history-table .status-col {
        white-space: nowrap !important;
    }

    .bill-history-table .status-wrap {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 0;
        height: 100%;
    }

    .bill-history-table .status-paid,
    .bill-history-table .status-due,
    .bill-history-table .status-partial-paid {
        padding: 0.14rem 0.42rem;
        font-size: 0.69rem;
        line-height: 1.15;
        white-space: nowrap;
    }

    .bill-history-table .col-actions {
        vertical-align: middle !important;
    }

    .bill-history-table .action-stack {
        display: flex;
        flex-direction: row;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: center;
        gap: 0.2rem;
        min-height: 0;
        white-space: normal;
        height: 100%;
    }

    .bill-history-table .action-stack .btn-action {
        white-space: nowrap;
        padding: 0.18rem 0.4rem;
        font-size: 0.67rem;
        line-height: 1.15;
        margin: 0;
    }

    .bill-history-table tbody tr.bill-data-row > td {
        padding-top: 0.35rem !important;
        padding-bottom: 0.35rem !important;
    }

    .bill-history-table .group-header {
        background: #375a9e;
        color: #fff;
        text-align: left;
        font-size: 0.76rem;
        letter-spacing: 0.03em;
        padding: 0.56rem 0.72rem !important;
    }

    .bill-history-table .summary-cell {
        padding: 0 !important;
        border-bottom: 1px solid #dbe7ff;
    }

    .bill-history-table .summary-line {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.38rem 0.55rem;
        width: 100%;
        padding: 0.74rem 0.78rem;
        text-align: left;
        line-height: 1.4;
    }

    .bill-history-table .mode-summary-line {
        background: #e9f1ff;
        border-top: 2px solid #6c8fd9;
        color: #143f7a;
    }

    .bill-history-table .grand-summary-line {
        background: #ffe1ea;
        border-top: 2px solid #d94773;
        color: #7a1436;
    }

    .bill-history-table .summary-pair {
        display: inline-flex;
        align-items: baseline;
        gap: 0.25rem;
        white-space: nowrap;
        font-size: 0.87rem;
        font-variant-numeric: tabular-nums;
    }

    .bill-history-table .summary-pair strong {
        font-weight: 800;
    }

    .bill-history-table .summary-pipe {
        white-space: nowrap;
        opacity: 0.8;
        font-weight: 700;
    }

    @media (max-width: 980px) {
        .bill-history-table .summary-line {
            gap: 0.42rem 0.8rem;
        }
    }

    @media (max-width: 768px) {
        body.role-receptionist.app-layout .bill-history-table th,
        body.role-receptionist.app-layout .bill-history-table td {
            padding: 0.34rem 0.36rem !important;
            font-size: 0.72rem;
        }

        .bill-history-table .summary-pair {
            font-size: 0.8rem;
        }

        .bill-history-table .patient-name {
            font-size: 0.74rem;
        }

        .bill-history-table .patient-ref {
            font-size: 0.64rem;
        }

        .bill-history-table .patient-uid {
            font-size: 0.62rem;
        }

        .bill-history-table .amount-col {
            font-size: 0.71rem;
        }

        .bill-history-table .action-stack .btn-action {
            font-size: 0.63rem;
            padding: 0.15rem 0.34rem;
        }
    }
</style>

<div class="table-container">
    <h1>Bill Summary</h1>

    <?php if (!empty($_GET['success'])): ?>
        <div class="success-banner"><?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="error-banner"><?php echo htmlspecialchars($_GET['error']); ?></div>
    <?php endif; ?>

    <?php
    $summary_csv_params = $_GET;
    unset($summary_csv_params['summary_csv']);
    $summary_csv_params['summary_date'] = $summary_date;
    $summary_csv_params['summary_csv'] = '1';
    $summary_csv_url = 'bill_history.php?' . http_build_query($summary_csv_params);

    $summary_filter_keep_keys = ['start_date', 'end_date', 'payment_status', 'payment_mode', 'search_term', 'all_dates'];
    $category_css_map = [
        'CT' => 'daily-cat-ct',
        'ECG' => 'daily-cat-ecg',
        'ECHO' => 'daily-cat-echo',
        'LAB' => 'daily-cat-lab',
        'MAMMOGRAPHY' => 'daily-cat-mammography',
        'MRI' => 'daily-cat-mri',
        'USG' => 'daily-cat-usg',
        'X-RAY' => 'daily-cat-xray',
    ];
    ?>

    <section class="daily-summary-card">
        <details class="daily-summary-details" open>
            <summary>
                <span>Daily Test Category Revenue Summary</span>
                <span class="daily-summary-date-pill"><?php echo htmlspecialchars(date('d-m-Y', strtotime($summary_date))); ?></span>
            </summary>

            <div class="daily-summary-toolbar">
                <form action="bill_history.php" method="GET" class="daily-summary-filter-form">
                    <?php foreach ($summary_filter_keep_keys as $keep_key): ?>
                        <?php if (isset($_GET[$keep_key])): ?>
                            <input type="hidden" name="<?php echo htmlspecialchars($keep_key); ?>" value="<?php echo htmlspecialchars((string)$_GET[$keep_key]); ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div>
                        <label for="summary_date">Select Date</label>
                        <input type="date" id="summary_date" name="summary_date" value="<?php echo htmlspecialchars($summary_date); ?>">
                    </div>
                    <button type="submit" class="daily-summary-filter-btn">Get Summary</button>
                </form>

                <a href="<?php echo htmlspecialchars($summary_csv_url); ?>" class="daily-summary-download-btn">📥 Download CSV</a>
            </div>

            <div class="table-responsive daily-summary-table-wrap">
                <table class="daily-summary-table" id="daily-summary-table">
                    <thead>
                        <tr>
                            <th>Test Category</th>
                            <th>Sub Test Name</th>
                            <th class="text-right">Revenue (Total)</th>
                            <th class="text-right">Cash</th>
                            <th class="text-right">Card</th>
                            <th class="text-right">UPI</th>
                            <th class="text-right">Pending</th>
                            <th class="text-right">Discount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary_categories as $category_name): ?>
                            <?php
                                $category_totals = $daily_summary[$category_name]['totals'];
                                $category_items = $daily_summary[$category_name]['items'];
                                $category_slug = strtolower(str_replace(['-', ' '], ['', '_'], $category_name));
                                $category_css = $category_css_map[$category_name] ?? '';
                            ?>
                            <tr class="daily-category-row <?php echo htmlspecialchars($category_css); ?>">
                                <td class="category-cell">
                                    <button type="button" class="category-toggle" data-category="<?php echo htmlspecialchars($category_slug); ?>" data-collapsed="0">▾</button>
                                    <?php echo htmlspecialchars($category_name); ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($category_name); ?> Category</strong></td>
                                <td class="text-right"><?php echo bh_format_inr($category_totals['revenue']); ?></td>
                                <td class="text-right"><?php echo bh_format_inr($category_totals['cash']); ?></td>
                                <td class="text-right"><?php echo bh_format_inr($category_totals['card']); ?></td>
                                <td class="text-right"><?php echo bh_format_inr($category_totals['upi']); ?></td>
                                <td class="text-right"><?php echo bh_format_inr($category_totals['pending']); ?></td>
                                <td class="text-right"><?php echo bh_format_inr($category_totals['discount']); ?></td>
                            </tr>

                            <?php if (!empty($category_items)): ?>
                                <?php $item_counter = 0; ?>
                                <?php foreach ($category_items as $item_data): ?>
                                    <tr class="daily-subtest-row daily-subtest-group-<?php echo htmlspecialchars($category_slug); ?> <?php echo ($item_counter % 2 === 1) ? 'is-alt' : ''; ?>">
                                        <td>&nbsp;</td>
                                        <td class="subtest-name"><?php echo htmlspecialchars((string)$item_data['name']); ?></td>
                                        <td class="text-right"><?php echo bh_format_inr($item_data['revenue']); ?></td>
                                        <td class="text-right"><?php echo bh_format_inr($item_data['cash']); ?></td>
                                        <td class="text-right"><?php echo bh_format_inr($item_data['card']); ?></td>
                                        <td class="text-right"><?php echo bh_format_inr($item_data['upi']); ?></td>
                                        <td class="text-right"><?php echo bh_format_inr($item_data['pending']); ?></td>
                                        <td class="text-right"><?php echo bh_format_inr($item_data['discount']); ?></td>
                                    </tr>
                                    <?php $item_counter++; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <tr class="daily-subtotal-row">
                                <td><strong><?php echo htmlspecialchars($category_name); ?></strong></td>
                                <td><strong>Category Subtotal</strong></td>
                                <td class="text-right"><?php echo bh_format_inr($category_totals['revenue']); ?></td>
                                <td class="text-right"><?php echo bh_format_inr($category_totals['cash']); ?></td>
                                <td class="text-right"><?php echo bh_format_inr($category_totals['card']); ?></td>
                                <td class="text-right"><?php echo bh_format_inr($category_totals['upi']); ?></td>
                                <td class="text-right"><?php echo bh_format_inr($category_totals['pending']); ?></td>
                                <td class="text-right"><?php echo bh_format_inr($category_totals['discount']); ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="daily-grand-total-row">
                            <td><strong>GRAND TOTAL</strong></td>
                            <td><strong>All Categories</strong></td>
                            <td class="text-right"><?php echo bh_format_inr($daily_grand_totals['revenue']); ?></td>
                            <td class="text-right"><?php echo bh_format_inr($daily_grand_totals['cash']); ?></td>
                            <td class="text-right"><?php echo bh_format_inr($daily_grand_totals['card']); ?></td>
                            <td class="text-right"><?php echo bh_format_inr($daily_grand_totals['upi']); ?></td>
                            <td class="text-right"><?php echo bh_format_inr($daily_grand_totals['pending']); ?></td>
                            <td class="text-right"><?php echo bh_format_inr($daily_grand_totals['discount']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    </section>

    <form action="bill_history.php" method="GET" class="date-filter-form" id="bill-review-section">
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
                <option value="Partial Paid" <?php if($payment_status_filter == 'Partial Paid') echo 'selected'; ?>>Partial Paid</option>
                <option value="Due" <?php if($payment_status_filter == 'Due') echo 'selected'; ?>>Due</option>
                <option value="pending" <?php if($payment_status_filter == 'pending') echo 'selected'; ?>>Pending (Due + Partial)</option>
            </select>
        </div>
        <div class="form-group">
            <label for="payment_mode">Payment Mode</label>
            <select name="payment_mode" id="payment_mode">
                <option value="all" <?php if($payment_mode_filter == 'all') echo 'selected'; ?>>All Modes</option>
                <option value="Cash" <?php if($payment_mode_filter == 'Cash') echo 'selected'; ?>>Cash</option>
                <option value="UPI" <?php if($payment_mode_filter == 'UPI') echo 'selected'; ?>>UPI</option>
                <option value="Card" <?php if($payment_mode_filter == 'Card') echo 'selected'; ?>>Card</option>
                <option value="Cash + Card" <?php if($payment_mode_filter == 'Cash + Card') echo 'selected'; ?>>Cash + Card</option>
                <option value="UPI + Cash" <?php if($payment_mode_filter == 'UPI + Cash') echo 'selected'; ?>>UPI + Cash</option>
                <option value="Card + UPI" <?php if($payment_mode_filter == 'Card + UPI') echo 'selected'; ?>>Card + UPI</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="search_term">Search UID/Patient/Doctor</label>
            <input type="text" id="search_term" name="search_term" placeholder="Enter UID or name..." value="<?php echo htmlspecialchars($search_term); ?>">
        </div>
        <button type="submit" class="btn-submit">Get Report</button>
    </form>

    <div class="table-responsive bill-history-table-wrap">
    <table class="report-table bill-history-table">
        <colgroup>
            <col style="width:9%;">
            <col style="width:27%;">
            <col style="width:10%;">
            <col style="width:10%;">
            <col style="width:10%;">
            <col style="width:10%;">
            <col style="width:7%;">
            <col style="width:17%;">
        </colgroup>
        <thead>
            <tr>
                <th class="col-bill">Bill No.</th>
                <th class="col-patient">Patient Name</th>
                <th class="col-net" style="text-align:right;">Net Amount</th>
                <th class="col-discount" style="text-align:right;">Discount</th>
                <th class="col-paid" style="text-align:right;">Paid Amount</th>
                <th class="col-pending" style="text-align:right;">Pending Amount</th>
                <th class="col-status">Status</th>
                <th class="col-actions">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if (count($bills) > 0) {
                $current_group = null;
                $group_gross = 0; $group_discount = 0; $group_net = 0; $group_paid = 0; $group_pending = 0;
                $grand_gross = 0; $grand_discount = 0; $grand_net = 0; $grand_paid = 0; $grand_pending = 0;

                $render_summary_row = function(string $label, float $gross, float $discount, float $net, float $paid, float $pending, bool $is_grand = false): string {
                    $headline = $is_grand ? 'Grand Total' : ($label . ' Total');
                    $line_class = $is_grand ? 'summary-line grand-summary-line' : 'summary-line mode-summary-line';
                    $pairs = [
                        ['label' => $headline, 'value' => $net],
                        ['label' => 'Gross', 'value' => $gross],
                        ['label' => 'Discount', 'value' => $discount],
                        ['label' => 'Net', 'value' => $net],
                        ['label' => 'Paid Till Now', 'value' => $paid],
                        ['label' => 'Pending', 'value' => $pending],
                    ];

                    $pair_chunks = [];
                    foreach ($pairs as $pair) {
                        $pair_chunks[] = '<span class="summary-pair"><strong>' . htmlspecialchars($pair['label']) . '</strong> = ₹' . number_format((float)$pair['value'], 2) . '</span>';
                    }

                    return '<tr class="' . ($is_grand ? 'grand-total-row' : 'group-total-row') . '"><td colspan="8" class="summary-cell"><div class="' . $line_class . '">' . implode('<span class="summary-pipe">|</span>', $pair_chunks) . '</div></td></tr>';
                };

                foreach ($bills as $index => $bill) {
                    $mode_label = format_payment_mode_display($bill, false);
                    $paid_amount = round(max(0, (float)$bill['amount_paid']), 2);
                    $pending_amount = calculate_pending_amount((float)$bill['net_amount'], $paid_amount);
                    $derived_status = derive_payment_status_from_amounts((float)$bill['net_amount'], $paid_amount, 'Due');
                    $status_class = 'status-' . strtolower(str_replace(' ', '-', $derived_status));
                    $patient_uid_raw = (string)$bill['patient_uid'];

                    if ($bill['referral_type'] === 'Doctor' && !empty($bill['ref_physician_name'])) {
                        $ref_physician_display = 'Ref: ' . (string)$bill['ref_physician_name'];
                    } elseif ($bill['referral_type'] === 'Other') {
                        $ref_physician_display = 'Ref: Other';
                    } else {
                        $ref_physician_display = 'Ref: Self';
                    }

                    if ($mode_label !== $current_group) {
                        if ($current_group !== null) {
                            echo $render_summary_row($current_group, $group_gross, $group_discount, $group_net, $group_paid, $group_pending, false);
                        }

                        $group_gross = 0; $group_discount = 0; $group_net = 0; $group_paid = 0; $group_pending = 0;
                        $current_group = $mode_label;
                        echo '<tr><th colspan="8" class="group-header">' . htmlspecialchars($current_group) . ' Bills</th></tr>';
                    }

                    echo '<tr class="bill-data-row">';

                    echo '<td class="col-bill">';
                    echo '<span class="bill-no">#' . $bill['id'] . '</span>';
                    echo '<small class="bill-date">' . date('d-m-Y', strtotime($bill['created_at'])) . '</small>';
                    echo '</td>';

                    echo '<td class="col-patient">';
                    echo '<span class="patient-name" title="' . htmlspecialchars($bill['patient_name']) . '">' . htmlspecialchars($bill['patient_name']) . '</span>';
                    echo '<small class="patient-ref" title="' . htmlspecialchars($ref_physician_display) . '">' . htmlspecialchars($ref_physician_display) . '</small>';
                    echo '<small class="patient-uid" title="' . htmlspecialchars($patient_uid_raw) . '">' . htmlspecialchars($patient_uid_raw) . '</small>';
                    echo '</td>';

                    echo '<td class="col-net amount-col">₹' . number_format($bill['net_amount'], 2) . '</td>';
                    echo '<td class="col-discount amount-col">₹' . number_format($bill['discount'], 2) . '</td>';
                    echo '<td class="col-paid amount-col">₹' . number_format($paid_amount, 2) . '</td>';
                    echo '<td class="col-pending amount-col">₹' . number_format($pending_amount, 2) . '</td>';
                    echo '<td class="col-status status-col"><div class="status-wrap"><span class="' . $status_class . '">' . htmlspecialchars($derived_status) . '</span></div></td>';

                    echo '<td class="col-actions"><div class="action-stack">';
                    echo '<a href="preview_bill.php?bill_id=' . $bill['id'] . '" class="btn-action btn-view" target="_blank">View</a>';
                    if ($pending_amount > 0.01) {
                        echo '<a href="update_payment.php?bill_id=' . $bill['id'] . '" class="btn-action btn-update">Update Payment</a>';
                    }
                    
                    $created_time = new DateTime($bill['created_at']);
                    $current_time = new DateTime();
                    $interval = $current_time->diff($created_time);
                    $hours_diff = $interval->h + ($interval->days * 24);
                    if ($hours_diff < 12) {
                        echo '<a href="edit_bill.php?bill_id=' . $bill['id'] . '" class="btn-action btn-edit">Edit Bill</a>';
                    }
                    echo '</div></td>';
                    echo '</tr>';

                    $group_gross += $bill['gross_amount']; $group_discount += $bill['discount']; $group_net += $bill['net_amount'];
                    $grand_gross += $bill['gross_amount']; $grand_discount += $bill['discount']; $grand_net += $bill['net_amount'];
                    $group_paid += $paid_amount; $group_pending += $pending_amount;
                    $grand_paid += $paid_amount; $grand_pending += $pending_amount;
                }

                echo $render_summary_row($current_group, $group_gross, $group_discount, $group_net, $group_paid, $group_pending, false);
                echo $render_summary_row('Grand', $grand_gross, $grand_discount, $grand_net, $grand_paid, $grand_pending, true);

            } else {
                echo '<tr><td colspan="8" style="text-align:center;">No bills found for the selected criteria.</td></tr>';
            }
            ?>
        </tbody>
    </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggles = document.querySelectorAll('.category-toggle');
    toggles.forEach(function(toggleBtn) {
        toggleBtn.addEventListener('click', function(event) {
            event.preventDefault();

            var categorySlug = toggleBtn.getAttribute('data-category');
            if (!categorySlug) {
                return;
            }

            var isCollapsed = toggleBtn.getAttribute('data-collapsed') === '1';
            var childRows = document.querySelectorAll('.daily-subtest-group-' + categorySlug);
            childRows.forEach(function(row) {
                row.style.display = isCollapsed ? '' : 'none';
            });

            toggleBtn.setAttribute('data-collapsed', isCollapsed ? '0' : '1');
            toggleBtn.textContent = isCollapsed ? '▾' : '▸';
        });
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>