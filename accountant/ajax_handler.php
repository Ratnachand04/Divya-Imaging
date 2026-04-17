<?php
session_start();
// Authentication and database connection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'accountant') {
    http_response_code(403);
    die(json_encode(["error" => "Unauthorized access."]));
}
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_package_management_schema($conn);

$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';
$referral_doctors_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd') : '`referral_doctors` rd';
$doctor_test_payables_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'doctor_test_payables', 'dtp') : '`doctor_test_payables` dtp';
$doctor_payout_history_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'doctor_payout_history', 'dph') : '`doctor_payout_history` dph';
$bill_package_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_package_items', 'bpi') : '`bill_package_items` bpi';
$expenses_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'expenses', 'e') : '`expenses` e';
$bill_item_screenings_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_item_screenings', 'bis') : '`bill_item_screenings` bis';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';

header('Content-Type: application/json');

if (isset($_GET['action']) && $_GET['action'] == 'getAccountantDashboardData') {
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-t');
    if (strtotime($start_date) === false || strtotime($end_date) === false) {
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-d');
    }
    if (strtotime($start_date) > strtotime($end_date)) {
        $start_date = $end_date;
    }
    $end_date_for_query = $end_date . ' 23:59:59';
    $response = [];

    // --- Core metrics ---
    $metrics = [
        'total_earnings' => 0.0,
        'total_discounts' => 0.0,
        'total_payouts' => 0.0,
        'pending_payouts' => 0.0,
        'package_revenue' => 0.0,
        'package_sales_count' => 0,
        'package_discount_impact' => 0.0,
    ];

    // Total earnings (paid bills)
    $earnings_sql = "SELECT COALESCE(SUM(b.net_amount), 0) AS total_earnings FROM {$bills_source} WHERE b.payment_status = 'Paid' AND b.bill_status != 'Void' AND b.created_at BETWEEN ? AND ?";
    if ($stmt = $conn->prepare($earnings_sql)) {
        $stmt->bind_param('ss', $start_date, $end_date_for_query);
        $stmt->execute();
        $stmt->bind_result($total_earnings);
        $stmt->fetch();
        $metrics['total_earnings'] = (float) $total_earnings;
        $stmt->close();
    }

    // Total discounts (all discounts applied on non-void bills)
    $discounts_sql = "SELECT COALESCE(SUM(b.discount), 0) AS total_discounts FROM {$bills_source} WHERE b.bill_status != 'Void' AND b.created_at BETWEEN ? AND ?";
    if ($stmt = $conn->prepare($discounts_sql)) {
        $stmt->bind_param('ss', $start_date, $end_date_for_query);
        $stmt->execute();
        $stmt->bind_result($total_discounts);
        $stmt->fetch();
        $metrics['total_discounts'] = (float) $total_discounts;
        $stmt->close();
    }

    // Total payouts recorded for the period, aggregated by doctor for accurate pending calculation
    $paidPayoutsByDoctor = [];
    $payouts_sql = "SELECT dph.doctor_id, COALESCE(SUM(dph.payout_amount), 0) AS total_payout FROM {$doctor_payout_history_source} WHERE dph.paid_at BETWEEN ? AND ? GROUP BY dph.doctor_id";
    if ($stmt = $conn->prepare($payouts_sql)) {
        $stmt->bind_param('ss', $start_date, $end_date_for_query);
        $stmt->execute();
        $result = $stmt->get_result();
        $total_payout_amount = 0.0;
        while ($row = $result->fetch_assoc()) {
            $doctorId = (int) $row['doctor_id'];
            $paidAmount = (float) $row['total_payout'];
            $paidPayoutsByDoctor[$doctorId] = $paidAmount;
            $total_payout_amount += $paidAmount;
        }
        $metrics['total_payouts'] = $total_payout_amount;
        $stmt->close();
    }

    // Pending payouts per doctor using payable charges minus payouts already recorded in the same period
    $pending_sql = "
        SELECT rd.id AS doctor_id,
               SUM(COALESCE(dtp.payable_amount, t.default_payable_amount, 0)) AS total_payable
        FROM {$bills_source}
        JOIN {$bill_items_source} ON b.id = bi.bill_id
        JOIN {$tests_source} ON bi.test_id = t.id
        JOIN {$referral_doctors_source} ON b.referral_doctor_id = rd.id
        LEFT JOIN {$doctor_test_payables_source} ON rd.id = dtp.doctor_id AND bi.test_id = dtp.test_id
        WHERE b.payment_status = 'Paid'
          AND b.bill_status != 'Void'
          AND b.referral_type = 'Doctor'
          AND (b.discount = 0 OR b.discount_by = 'Center')
          AND b.created_at BETWEEN ? AND ?
        GROUP BY rd.id
    ";

    if ($stmt = $conn->prepare($pending_sql)) {
        $stmt->bind_param('ss', $start_date, $end_date_for_query);
        $stmt->execute();
        $result = $stmt->get_result();
        $pending_total = 0.0;
        while ($row = $result->fetch_assoc()) {
            $doctorId = (int) $row['doctor_id'];
            $payable = (float) $row['total_payable'];
            $alreadyPaid = $paidPayoutsByDoctor[$doctorId] ?? 0.0;
            $pending_total += max(0.0, $payable - $alreadyPaid);
        }
        $metrics['pending_payouts'] = $pending_total;
        $stmt->close();
    }

    $package_sales_sql = "
        SELECT
            COUNT(*) AS package_sales_count,
            COALESCE(SUM(COALESCE(bi.package_discount, 0)), 0) AS package_discount_impact
        FROM {$bill_items_source}
        JOIN {$bills_source} ON b.id = bi.bill_id
        WHERE COALESCE(bi.item_type, 'test') = 'package'
          AND bi.item_status = 0
          AND b.bill_status != 'Void'
          AND b.created_at BETWEEN ? AND ?
    ";
    if ($stmt = $conn->prepare($package_sales_sql)) {
        $stmt->bind_param('ss', $start_date, $end_date_for_query);
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $metrics['package_sales_count'] = (int)($row['package_sales_count'] ?? 0);
            $metrics['package_discount_impact'] = (float)($row['package_discount_impact'] ?? 0);
        }
        $stmt->close();
    }

    $package_revenue_sql = "
        SELECT COALESCE(SUM(pkg_totals.package_total), 0) AS package_revenue
        FROM (
            SELECT bpi.bill_item_id, SUM(bpi.package_test_price) AS package_total
            FROM {$bill_package_items_source}
            JOIN {$bills_source} ON b.id = bpi.bill_id
            WHERE b.bill_status != 'Void'
              AND b.created_at BETWEEN ? AND ?
            GROUP BY bpi.bill_item_id
        ) AS pkg_totals
    ";
    if ($stmt = $conn->prepare($package_revenue_sql)) {
        $stmt->bind_param('ss', $start_date, $end_date_for_query);
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $metrics['package_revenue'] = (float)($row['package_revenue'] ?? 0);
        }
        $stmt->close();
    }

    $endDateObj = new DateTime($end_date);
    $startDateObj = new DateTime($start_date);
    $trendStartObj = (clone $endDateObj)->modify('first day of this month')->modify('-5 months');
    $trend_start_date = $trendStartObj->format('Y-m-01');
    $trend_start_datetime = $trend_start_date . ' 00:00:00';
    $trend_end_datetime = $end_date_for_query;

    $period_days = max(1, $startDateObj->diff($endDateObj)->days + 1);
    $prevEndObj = (clone $startDateObj)->modify('-1 day');
    $prevStartObj = (clone $prevEndObj)->modify('-' . ($period_days - 1) . ' day');
    $prev_start_date = $prevStartObj->format('Y-m-d');
    $prev_end_date = $prevEndObj->format('Y-m-d');
    $prev_end_datetime = $prev_end_date . ' 23:59:59';

    // --- Cashflow Pulse (Today vs Yesterday) ---
    $today = date('Y-m-d');
    $today_start = $today . ' 00:00:00';
    $today_end = $today . ' 23:59:59';
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $yesterday_start = $yesterday . ' 00:00:00';
    $yesterday_end = $yesterday . ' 23:59:59';

    $cashflow = [
        'today' => ['collections' => 0.0, 'expenses' => 0.0, 'payouts' => 0.0, 'net' => 0.0],
        'yesterday' => ['collections' => 0.0, 'expenses' => 0.0, 'payouts' => 0.0, 'net' => 0.0],
    ];

    $collectionsSql = "SELECT SUM(b.amount_paid) AS collected FROM {$bills_source} WHERE b.payment_status = 'Paid' AND b.bill_status != 'Void' AND b.created_at BETWEEN ? AND ?";
    if ($stmt = $conn->prepare($collectionsSql)) {
        $stmt->bind_param('ss', $today_start, $today_end);
        if ($stmt->execute()) {
            $stmt->bind_result($collected);
            $stmt->fetch();
            $cashflow['today']['collections'] = (float) ($collected ?? 0);
        }
        $stmt->close();
    }
    if ($stmt = $conn->prepare($collectionsSql)) {
        $stmt->bind_param('ss', $yesterday_start, $yesterday_end);
        if ($stmt->execute()) {
            $stmt->bind_result($collected);
            $stmt->fetch();
            $cashflow['yesterday']['collections'] = (float) ($collected ?? 0);
        }
        $stmt->close();
    }

    $expensesSql = "SELECT SUM(e.amount) AS spent FROM {$expenses_source} WHERE e.status = 'Paid' AND e.created_at BETWEEN ? AND ?";
    if ($stmt = $conn->prepare($expensesSql)) {
        $stmt->bind_param('ss', $today_start, $today_end);
        if ($stmt->execute()) {
            $stmt->bind_result($spent);
            $stmt->fetch();
            $cashflow['today']['expenses'] = (float) ($spent ?? 0);
        }
        $stmt->close();
    }
    if ($stmt = $conn->prepare($expensesSql)) {
        $stmt->bind_param('ss', $yesterday_start, $yesterday_end);
        if ($stmt->execute()) {
            $stmt->bind_result($spent);
            $stmt->fetch();
            $cashflow['yesterday']['expenses'] = (float) ($spent ?? 0);
        }
        $stmt->close();
    }

    $payoutSql = "SELECT SUM(dph.payout_amount) AS paid FROM {$doctor_payout_history_source} WHERE dph.paid_at BETWEEN ? AND ?";
    if ($stmt = $conn->prepare($payoutSql)) {
        $stmt->bind_param('ss', $today_start, $today_end);
        if ($stmt->execute()) {
            $stmt->bind_result($paid);
            $stmt->fetch();
            $cashflow['today']['payouts'] = (float) ($paid ?? 0);
        }
        $stmt->close();
    }
    if ($stmt = $conn->prepare($payoutSql)) {
        $stmt->bind_param('ss', $yesterday_start, $yesterday_end);
        if ($stmt->execute()) {
            $stmt->bind_result($paid);
            $stmt->fetch();
            $cashflow['yesterday']['payouts'] = (float) ($paid ?? 0);
        }
        $stmt->close();
    }

    foreach (['today', 'yesterday'] as $slot) {
        $cashflow[$slot]['net'] = $cashflow[$slot]['collections'] - $cashflow[$slot]['expenses'] - $cashflow[$slot]['payouts'];
    }
    $cashflow['change'] = $cashflow['today']['net'] - $cashflow['yesterday']['net'];

    $response['metrics'] = $metrics;
    $response['cashflow_pulse'] = $cashflow;

    // --- Receivables Aging Heatmap ---
    $agingBuckets = [
        '0-7 Days' => ['label' => '0-7 Days', 'amount' => 0.0, 'count' => 0],
        '8-14 Days' => ['label' => '8-14 Days', 'amount' => 0.0, 'count' => 0],
        '15-30 Days' => ['label' => '15-30 Days', 'amount' => 0.0, 'count' => 0],
        '30+ Days' => ['label' => '30+ Days', 'amount' => 0.0, 'count' => 0],
    ];
    $agingSql = "
        SELECT bucket, COUNT(*) AS invoice_count, SUM(balance_amount) AS total_balance
        FROM (
            SELECT
                CASE
                    WHEN diff <= 7 THEN '0-7 Days'
                    WHEN diff BETWEEN 8 AND 14 THEN '8-14 Days'
                    WHEN diff BETWEEN 15 AND 30 THEN '15-30 Days'
                    ELSE '30+ Days'
                END AS bucket,
                balance_amount
            FROM (
                SELECT
                    COALESCE(balance_amount, GREATEST(net_amount - amount_paid, 0)) AS balance_amount,
                    GREATEST(DATEDIFF(?, DATE(created_at)), 0) AS diff
                                FROM {$bills_source}
                                WHERE b.bill_status != 'Void'
                                    AND b.payment_status IN ('Due', 'Partial Paid')
                                    AND DATE(b.created_at) <= ?
            ) AS aging_source
        ) AS bucketed
        GROUP BY bucket
    ";
    $response['receivables_aging'] = ['buckets' => array_values($agingBuckets), 'total_due' => 0.0];
    if ($stmt = $conn->prepare($agingSql)) {
        $stmt->bind_param('ss', $end_date, $end_date);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $bucket = $row['bucket'];
                if (isset($agingBuckets[$bucket])) {
                    $agingBuckets[$bucket]['amount'] = (float) $row['total_balance'];
                    $agingBuckets[$bucket]['count'] = (int) $row['invoice_count'];
                    $response['receivables_aging']['total_due'] += (float) $row['total_balance'];
                }
            }
        }
        $stmt->close();
    }
    $response['receivables_aging']['buckets'] = array_values($agingBuckets);

    // --- Month Sequence for Trend Visuals ---
    $monthSequence = [];
    $monthIterator = clone $trendStartObj;
    while ($monthIterator <= $endDateObj) {
        $key = $monthIterator->format('Y-m-01');
        $monthSequence[$key] = $monthIterator->format('M Y');
        $monthIterator->modify('+1 month');
    }

    // --- Collections Efficiency Trend ---
    $collectionsData = [];
    foreach ($monthSequence as $key => $label) {
        $collectionsData[$key] = ['label' => $label, 'billed' => 0.0, 'collected' => 0.0];
    }
    $collectionsSql = "
        SELECT DATE_FORMAT(created_at, '%Y-%m-01') AS period_key,
               SUM(net_amount) AS billed,
               SUM(amount_paid) AS collected
                FROM {$bills_source}
                WHERE b.bill_status != 'Void'
                    AND b.created_at BETWEEN ? AND ?
        GROUP BY period_key
        ORDER BY period_key
    ";
    if ($stmt = $conn->prepare($collectionsSql)) {
        $stmt->bind_param('ss', $trend_start_datetime, $trend_end_datetime);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $key = $row['period_key'];
                if (isset($collectionsData[$key])) {
                    $collectionsData[$key]['billed'] = (float) $row['billed'];
                    $collectionsData[$key]['collected'] = (float) $row['collected'];
                }
            }
        }
        $stmt->close();
    }
    $response['collections_trend'] = ['labels' => [], 'billed' => [], 'collected' => [], 'collection_rate' => [], 'variance' => []];
    foreach ($collectionsData as $entry) {
        $billed = $entry['billed'];
        $collected = $entry['collected'];
        $rate = ($billed > 0) ? round(($collected / $billed) * 100, 1) : null;
        $response['collections_trend']['labels'][] = $entry['label'];
        $response['collections_trend']['billed'][] = $billed;
        $response['collections_trend']['collected'][] = $collected;
        $response['collections_trend']['collection_rate'][] = $rate;
        $response['collections_trend']['variance'][] = $collected - $billed;
    }

    // --- Revenue vs Expenses Trend ---
    $revExpData = [];
    foreach ($monthSequence as $key => $label) {
        $revExpData[$key] = ['label' => $label, 'revenue' => 0.0, 'expenses' => 0.0];
    }
    $revExpSql = "
        SELECT period_key,
               SUM(revenue) AS revenue,
               SUM(expense) AS expenses
        FROM (
                 SELECT DATE_FORMAT(b.created_at, '%Y-%m-01') AS period_key,
                     b.net_amount AS revenue,
                   0 AS expense
                 FROM {$bills_source}
                 WHERE b.bill_status != 'Void'
                AND b.payment_status = 'Paid'
                AND b.created_at BETWEEN ? AND ?
            UNION ALL
                 SELECT DATE_FORMAT(e.created_at, '%Y-%m-01') AS period_key,
                   0 AS revenue,
                     e.amount AS expense
                 FROM {$expenses_source}
                 WHERE e.status = 'Paid'
                AND e.created_at BETWEEN ? AND ?
        ) AS rev_exp
        GROUP BY period_key
        ORDER BY period_key
    ";
    if ($stmt = $conn->prepare($revExpSql)) {
        $stmt->bind_param('ssss', $trend_start_datetime, $trend_end_datetime, $trend_start_datetime, $trend_end_datetime);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $key = $row['period_key'];
                if (isset($revExpData[$key])) {
                    $revExpData[$key]['revenue'] = (float) $row['revenue'];
                    $revExpData[$key]['expenses'] = (float) $row['expenses'];
                }
            }
        }
        $stmt->close();
    }
    $response['revenue_vs_expenses'] = ['labels' => [], 'revenue' => [], 'expenses' => []];
    foreach ($revExpData as $entry) {
        $response['revenue_vs_expenses']['labels'][] = $entry['label'];
        $response['revenue_vs_expenses']['revenue'][] = $entry['revenue'];
        $response['revenue_vs_expenses']['expenses'][] = $entry['expenses'];
    }

    // --- Expense Breakdown & Anomaly Detection ---
    $currentExpensesByType = [];
    $expBreakdownSql = "SELECT e.expense_type, SUM(e.amount) AS total FROM {$expenses_source} WHERE e.status = 'Paid' AND e.created_at BETWEEN ? AND ? GROUP BY e.expense_type";
    if ($stmt = $conn->prepare($expBreakdownSql)) {
        $stmt->bind_param('ss', $start_date, $end_date_for_query);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $type = $row['expense_type'];
                $amount = (float) $row['total'];
                $currentExpensesByType[$type] = $amount;
            }
        }
        $stmt->close();
    }
    $response['expense_breakdown'] = ['labels' => array_keys($currentExpensesByType), 'values' => array_values($currentExpensesByType)];

    $previousExpensesByType = [];
    if ($stmt = $conn->prepare($expBreakdownSql)) {
        $stmt->bind_param('ss', $prev_start_date, $prev_end_datetime);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $type = $row['expense_type'];
                $amount = (float) $row['total'];
                $previousExpensesByType[$type] = $amount;
            }
        }
        $stmt->close();
    }

    $anomalies = [];
    $allExpenseTypes = array_unique(array_merge(array_keys($currentExpensesByType), array_keys($previousExpensesByType)));
    foreach ($allExpenseTypes as $type) {
        $currentAmount = $currentExpensesByType[$type] ?? 0.0;
        $previousAmount = $previousExpensesByType[$type] ?? 0.0;
        $delta = $currentAmount - $previousAmount;
        $pctChange = ($previousAmount > 0) ? round(($delta / $previousAmount) * 100, 1) : null;
        $isIncrease = $delta > 0;
        $significantIncrease = ($previousAmount > 0 && $pctChange !== null && $pctChange >= 20) || ($previousAmount == 0 && $currentAmount > 0);
        if ($significantIncrease || (!$isIncrease && abs($delta) > 0)) {
            $anomalies[] = [
                'expense_type' => $type,
                'current_total' => $currentAmount,
                'previous_total' => $previousAmount,
                'change_amount' => $delta,
                'change_percent' => $pctChange,
                'direction' => $isIncrease ? 'up' : 'down'
            ];
        }
    }
    usort($anomalies, function($a, $b) {
        return abs($b['change_amount']) <=> abs($a['change_amount']);
    });
    $response['expense_anomalies'] = array_slice($anomalies, 0, 5);

    // --- Doctor Settlement Snapshot ---
    $doctorSnapshotSql = "
        SELECT
            rd.id AS doctor_id,
            rd.doctor_name,
            SUM(COALESCE(dtp.payable_amount, t.default_payable_amount, 0)) AS total_payable,
            SUM(
                CASE WHEN DATEDIFF(?, DATE(b.created_at)) > 14 THEN COALESCE(dtp.payable_amount, t.default_payable_amount, 0) ELSE 0 END
            ) AS overdue_component
        FROM {$bills_source}
        JOIN {$bill_items_source} ON b.id = bi.bill_id
        JOIN {$tests_source} ON bi.test_id = t.id
        JOIN {$referral_doctors_source} ON b.referral_doctor_id = rd.id
        LEFT JOIN {$doctor_test_payables_source} ON rd.id = dtp.doctor_id AND bi.test_id = dtp.test_id
        WHERE b.payment_status = 'Paid'
          AND b.bill_status != 'Void'
          AND b.referral_type = 'Doctor'
          AND (b.discount = 0 OR b.discount_by = 'Center')
          AND b.created_at BETWEEN ? AND ?
        GROUP BY rd.id, rd.doctor_name
        HAVING total_payable > 0
    ";
    $doctorRows = [];
    if ($stmt = $conn->prepare($doctorSnapshotSql)) {
        $stmt->bind_param('sss', $end_date, $start_date, $end_date_for_query);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $doctorRows[] = $row;
            }
        }
        $stmt->close();
    }

    $doctorSummary = ['total_payable' => 0.0, 'total_settled' => 0.0, 'total_pending' => 0.0, 'total_overdue' => 0.0];
    $doctorPendingList = [];
    foreach ($doctorRows as $row) {
        $doctorId = (int) $row['doctor_id'];
        $payable = (float) $row['total_payable'];
        $paid = $paidPayoutsByDoctor[$doctorId] ?? 0.0;
        $pending = max(0.0, $payable - $paid);
        $overdueBase = max(0.0, (float) $row['overdue_component'] - $paid);
        $overdue = min($pending, $overdueBase);

        $doctorSummary['total_payable'] += $payable;
        $doctorSummary['total_settled'] += $paid;
        $doctorSummary['total_pending'] += $pending;
        $doctorSummary['total_overdue'] += $overdue;

        $doctorPendingList[] = [
            'doctor_id' => $doctorId,
            'doctor_name' => $row['doctor_name'],
            'payable' => $payable,
            'settled' => $paid,
            'pending' => $pending,
            'overdue' => $overdue
        ];
    }
    usort($doctorPendingList, function($a, $b) {
        return $b['pending'] <=> $a['pending'];
    });
    $response['doctor_settlement'] = [
        'summary' => $doctorSummary,
        'top_pending' => array_slice($doctorPendingList, 0, 5)
    ];

    // --- Top Doctor Payouts (Chart) ---
    $payoutChartSql = "
        SELECT rd.doctor_name,
               SUM(COALESCE(dtp.payable_amount, t.default_payable_amount, 0)) AS total_payable
                FROM {$bills_source}
                JOIN {$bill_items_source} ON b.id = bi.bill_id
                JOIN {$referral_doctors_source} ON b.referral_doctor_id = rd.id
                JOIN {$tests_source} ON bi.test_id = t.id
                LEFT JOIN {$doctor_test_payables_source} ON rd.id = dtp.doctor_id AND bi.test_id = dtp.test_id
        WHERE b.referral_type = 'Doctor'
          AND b.payment_status = 'Paid'
          AND b.bill_status != 'Void'
          AND (b.discount = 0 OR b.discount_by = 'Center')
          AND b.created_at BETWEEN ? AND ?
        GROUP BY rd.id, rd.doctor_name
        HAVING total_payable > 0
        ORDER BY total_payable DESC
        LIMIT 5
    ";
    $response['doctor_payouts'] = ['labels' => [], 'values' => []];
    if ($stmt = $conn->prepare($payoutChartSql)) {
        $stmt->bind_param('ss', $start_date, $end_date_for_query);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $response['doctor_payouts']['labels'][] = $row['doctor_name'];
                $response['doctor_payouts']['values'][] = (float) $row['total_payable'];
            }
        }
        $stmt->close();
    }

    // --- Revenue by Payment Mode ---
    $paymentModeSql = "SELECT b.payment_mode, SUM(b.net_amount) AS total FROM {$bills_source} WHERE b.payment_status = 'Paid' AND b.bill_status != 'Void' AND b.created_at BETWEEN ? AND ? GROUP BY b.payment_mode";
    $response['payment_modes'] = ['labels' => [], 'values' => []];
    if ($stmt = $conn->prepare($paymentModeSql)) {
        $stmt->bind_param('ss', $start_date, $end_date_for_query);
        if ($stmt->execute()) {
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $normalized_payment_totals = normalize_payment_mode_totals($result);
            $response['payment_modes']['labels'] = array_keys($normalized_payment_totals);
            $response['payment_modes']['values'] = array_values($normalized_payment_totals);
        }
        $stmt->close();
    }

    // --- Profitability by Test Bundle ---
    $profitabilitySql = "
        SELECT
            t.main_test_name,
            COUNT(bi.id) AS tests_performed,
            SUM(t.price + COALESCE(bis.screening_amount, 0)) AS gross_total,
            SUM(COALESCE(bi.discount_amount, 0)) AS discount_total,
            SUM(
                CASE WHEN b.referral_type = 'Doctor'
                      AND (COALESCE(bi.discount_amount, 0) = 0 OR b.discount_by = 'Center')
                     THEN COALESCE(dtp.payable_amount, t.default_payable_amount, 0)
                     ELSE 0 END
            ) AS doctor_payable
        FROM {$bills_source}
        JOIN {$bill_items_source} ON b.id = bi.bill_id
        JOIN {$tests_source} ON bi.test_id = t.id
        LEFT JOIN {$bill_item_screenings_source} ON bis.bill_item_id = bi.id
        LEFT JOIN {$doctor_test_payables_source} ON dtp.doctor_id = b.referral_doctor_id AND dtp.test_id = bi.test_id
        WHERE b.bill_status != 'Void'
          AND bi.item_status = 0
          AND b.created_at BETWEEN ? AND ?
        GROUP BY t.main_test_name
        HAVING tests_performed > 0
        ORDER BY gross_total DESC
        LIMIT 8
    ";
    $profitabilityRows = [];
    if ($stmt = $conn->prepare($profitabilitySql)) {
        $stmt->bind_param('ss', $start_date, $end_date_for_query);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $gross = (float) $row['gross_total'];
                $discount = (float) $row['discount_total'];
                $doctorPayable = (float) $row['doctor_payable'];
                $net = $gross - $discount - $doctorPayable;
                $profitabilityRows[] = [
                    'main_test_name' => $row['main_test_name'],
                    'tests_performed' => (int) $row['tests_performed'],
                    'gross_total' => $gross,
                    'discount_total' => $discount,
                    'doctor_payable' => $doctorPayable,
                    'net_margin' => $net
                ];
            }
        }
        $stmt->close();
    }
    usort($profitabilityRows, function($a, $b) {
        return $b['net_margin'] <=> $a['net_margin'];
    });
    $response['test_profitability'] = array_slice($profitabilityRows, 0, 5);

    // --- Pending Expenses (Due/Processing) ---
    $pendingExpenseRows = [];
    $pendingExpenseSql = "
        SELECT e.id, e.expense_type, e.amount, e.status, e.created_at
        FROM {$expenses_source}
        WHERE e.status IS NULL OR e.status NOT IN ('Paid')
        ORDER BY e.created_at ASC
        LIMIT 6
    ";
    if ($result = $conn->query($pendingExpenseSql)) {
        while ($row = $result->fetch_assoc()) {
            $pendingExpenseRows[] = [
                'id' => (int) $row['id'],
                'expense_type' => $row['expense_type'],
                'amount' => (float) $row['amount'],
                'status' => $row['status'] ?? 'Pending',
                'created_at' => $row['created_at'],
            ];
        }
        $result->free();
    }
    $response['pending_expenses'] = $pendingExpenseRows;

    // --- Receivables Watchlist (Top Pending Bills) ---
    $receivablesWatchlist = [];
    $watchlistSql = "
        SELECT b.id AS bill_id,
               p.name AS patient_name,
               b.net_amount,
               b.amount_paid,
               COALESCE(b.balance_amount, GREATEST(b.net_amount - b.amount_paid, 0)) AS balance_due,
               DATEDIFF(?, DATE(b.created_at)) AS days_outstanding
                FROM {$bills_source}
                JOIN {$patients_source} ON b.patient_id = p.id
        WHERE b.bill_status != 'Void'
          AND b.payment_status IN ('Due','Partial Paid')
        ORDER BY balance_due DESC, b.created_at ASC
        LIMIT 6
    ";
    if ($stmt = $conn->prepare($watchlistSql)) {
        $stmt->bind_param('s', $end_date);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $receivablesWatchlist[] = [
                    'bill_id' => (int) $row['bill_id'],
                    'patient_name' => $row['patient_name'],
                    'balance_due' => (float) $row['balance_due'],
                    'days_outstanding' => max(0, (int) $row['days_outstanding'])
                ];
            }
        }
        $stmt->close();
    }
    $response['receivables_watchlist'] = $receivablesWatchlist;

    echo json_encode($response);
    exit();
} elseif (isset($_GET['action']) && $_GET['action'] == 'getDoctorPayouts') {
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-t');
    $end_date_for_query = $end_date . ' 23:59:59';
    $doctor_id = isset($_GET['doctor_id']) && $_GET['doctor_id'] !== '' ? (int) $_GET['doctor_id'] : null;

    $params = [$start_date, $end_date_for_query];
    $types = 'ss';

    $doctor_filter_sql = '';
    if ($doctor_id) {
        $doctor_filter_sql = ' AND rd.id = ?';
        $params[] = $doctor_id;
        $types .= 'i';
    }

    $sql = "
        SELECT
            dbt.doctor_id,
            dbt.doctor_name,
            COUNT(dbt.bill_id) AS total_bills,
            SUM(dbt.total_tests) AS total_tests,
            SUM(dbt.total_payable) AS total_payable,
            SUM(dbt.total_payable_after_discount) AS payable_after_discount,
            SUM(dbt.discount_absorbed) AS doctor_discount_absorbed,
            SUM(dbt.gross_amount) AS total_gross_amount,
            SUM(dbt.discount_amount) AS total_discount_amount,
            SUM(dbt.net_amount) AS total_net_amount
        FROM (
            SELECT
                rd.id AS doctor_id,
                rd.doctor_name,
                b.id AS bill_id,
                COUNT(bi.id) AS total_tests,
                SUM(COALESCE(dtp.payable_amount, t.default_payable_amount, 0)) AS total_payable,
                SUM(
                    CASE WHEN b.referral_type = 'Doctor' THEN
                        CASE
                            WHEN COALESCE(dtp.payable_amount, t.default_payable_amount, 0) > 0
                                 AND COALESCE(bi.discount_amount, 0) < COALESCE(dtp.payable_amount, t.default_payable_amount, 0)
                            THEN COALESCE(dtp.payable_amount, t.default_payable_amount, 0) - COALESCE(bi.discount_amount, 0)
                            ELSE 0
                        END
                    ELSE 0
                    END
                ) AS total_payable_after_discount,
                SUM(
                    CASE WHEN b.referral_type = 'Doctor' THEN
                        LEAST(COALESCE(bi.discount_amount, 0), COALESCE(dtp.payable_amount, t.default_payable_amount, 0))
                    ELSE 0
                    END
                ) AS discount_absorbed,
                b.gross_amount,
                b.discount AS discount_amount,
                b.net_amount
            FROM {$bills_source}
            JOIN {$bill_items_source} ON b.id = bi.bill_id
            JOIN {$tests_source} ON bi.test_id = t.id
            JOIN {$referral_doctors_source} ON b.referral_doctor_id = rd.id
            LEFT JOIN {$doctor_test_payables_source} ON rd.id = dtp.doctor_id AND bi.test_id = dtp.test_id
            WHERE b.payment_status = 'Paid'
              AND b.bill_status != 'Void'
              AND b.referral_type = 'Doctor'
              AND b.created_at BETWEEN ? AND ?
              {$doctor_filter_sql}
            GROUP BY rd.id, rd.doctor_name, b.id, b.gross_amount, b.discount, b.discount_by, b.net_amount
        ) AS dbt
        GROUP BY dbt.doctor_id, dbt.doctor_name
        HAVING total_payable > 0 OR payable_after_discount > 0
        ORDER BY dbt.doctor_name
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to prepare payouts query.']);
        exit();
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $response = [
        'filters' => [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'doctor_id' => $doctor_id,
        ],
        'payouts' => [],
    ];

    while ($row = $result->fetch_assoc()) {
        $response['payouts'][] = [
            'doctor_id' => (int) $row['doctor_id'],
            'doctor_name' => $row['doctor_name'],
            'total_bills' => (int) $row['total_bills'],
            'total_tests' => (int) $row['total_tests'],
            'total_payable' => (float) $row['total_payable'],
            'payable_after_discount' => (float) $row['payable_after_discount'],
            'discount_applied' => (float) $row['doctor_discount_absorbed'],
            'total_gross_amount' => (float) $row['total_gross_amount'],
            'total_discount_amount' => (float) $row['total_discount_amount'],
            'total_net_amount' => (float) $row['total_net_amount'],
        ];
    }

    $stmt->close();

    echo json_encode($response);
    exit();
}
?>