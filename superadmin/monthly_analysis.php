<?php
$page_title = "Monthly Analysis";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

// Calculate start and end date for current month
$start_date = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$period_start = DateTime::createFromFormat('Y-m-d', $start_date) ?: new DateTime('first day of this month');
$period_end = (clone $period_start)->modify('last day of this month');
$end_date = $period_end->format('Y-m-d');
$end_date_time = $end_date . ' 23:59:59';

// Prepare previous month window
$previous_start = (clone $period_start)->modify('first day of previous month');
$previous_end = (clone $previous_start)->modify('last day of this month');
$previous_end_time = $previous_end->format('Y-m-d') . ' 23:59:59';

// Fetch stats and datasets
$stats = [
    'revenue' => 0,
    'gross_revenue' => 0,
    'collected' => 0,
    'outstanding' => 0,
    'bills' => 0,
    'tests' => 0,
    'patients' => 0,
    'previous_revenue' => 0,
    'avg_daily_revenue' => 0,
    'avg_ticket_size' => 0,
    'collection_rate' => 0,
    'tests_per_patient' => 0,
    'avg_daily_tests' => 0,
    'revenue_change_pct' => null,
    'best_day' => null,
    'best_day_value' => 0,
];

$days_in_period = (int)$period_end->diff($period_start)->format('%a') + 1;

// Revenue, collections, outstanding, bill count
$stmt = $conn->prepare("SELECT SUM(net_amount) AS total_revenue, SUM(gross_amount) AS total_gross, SUM(amount_paid) AS total_collected, SUM(balance_amount) AS total_outstanding, COUNT(*) AS total_bills FROM bills WHERE created_at BETWEEN ? AND ? AND bill_status != 'Void'");
$stmt->bind_param('ss', $start_date, $end_date_time);
$stmt->execute();
$revenue_row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stats['revenue'] = (float)($revenue_row['total_revenue'] ?? 0);
$stats['gross_revenue'] = (float)($revenue_row['total_gross'] ?? 0);
$stats['collected'] = (float)($revenue_row['total_collected'] ?? 0);
$stats['outstanding'] = (float)($revenue_row['total_outstanding'] ?? 0);
$stats['bills'] = (int)($revenue_row['total_bills'] ?? 0);

// Total tests
$stmt = $conn->prepare("SELECT COUNT(bi.id) AS total_tests FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE b.created_at BETWEEN ? AND ? AND b.bill_status != 'Void' AND bi.item_status = 0");
$stmt->bind_param('ss', $start_date, $end_date_time);
$stmt->execute();
$stats['tests'] = (int)($stmt->get_result()->fetch_assoc()['total_tests'] ?? 0);
$stmt->close();

// Total patients
$stmt = $conn->prepare("SELECT COUNT(DISTINCT patient_id) AS total_patients FROM bills WHERE created_at BETWEEN ? AND ? AND bill_status != 'Void'");
$stmt->bind_param('ss', $start_date, $end_date_time);
$stmt->execute();
$stats['patients'] = (int)($stmt->get_result()->fetch_assoc()['total_patients'] ?? 0);
$stmt->close();

// Previous month revenue for comparison
$stmt = $conn->prepare("SELECT SUM(net_amount) AS total_revenue FROM bills WHERE created_at BETWEEN ? AND ? AND bill_status != 'Void'");
$prev_start_str = $previous_start->format('Y-m-d');
$stmt->bind_param('ss', $prev_start_str, $previous_end_time);
$stmt->execute();
$stats['previous_revenue'] = (float)($stmt->get_result()->fetch_assoc()['total_revenue'] ?? 0);
$stmt->close();

// Daily revenue and collections
$daily_revenue_map = [];
$stmt = $conn->prepare("SELECT DATE(created_at) AS day, SUM(net_amount) AS revenue, SUM(amount_paid) AS collected, SUM(balance_amount) AS outstanding FROM bills WHERE created_at BETWEEN ? AND ? AND bill_status != 'Void' GROUP BY day ORDER BY day");
$stmt->bind_param('ss', $start_date, $end_date_time);
$stmt->execute();
$daily_revenue_result = $stmt->get_result();
while ($row = $daily_revenue_result->fetch_assoc()) {
    $daily_revenue_map[$row['day']] = [
        'revenue' => (float)$row['revenue'],
        'collected' => (float)($row['collected'] ?? 0),
        'outstanding' => (float)($row['outstanding'] ?? 0),
    ];
}
$stmt->close();

// Daily test counts
$daily_tests_map = [];
$stmt = $conn->prepare("SELECT DATE(b.created_at) AS day, COUNT(bi.id) AS tests FROM bills b JOIN bill_items bi ON b.id = bi.bill_id AND bi.item_status = 0 WHERE b.created_at BETWEEN ? AND ? AND b.bill_status != 'Void' GROUP BY day ORDER BY day");
$stmt->bind_param('ss', $start_date, $end_date_time);
$stmt->execute();
$daily_tests_result = $stmt->get_result();
while ($row = $daily_tests_result->fetch_assoc()) {
    $daily_tests_map[$row['day']] = (int)$row['tests'];
}
$stmt->close();

// Daily patient counts
$daily_patients_map = [];
$stmt = $conn->prepare("SELECT DATE(created_at) AS day, COUNT(DISTINCT patient_id) AS patients FROM bills WHERE created_at BETWEEN ? AND ? AND bill_status != 'Void' GROUP BY day ORDER BY day");
$stmt->bind_param('ss', $start_date, $end_date_time);
$stmt->execute();
$daily_patients_result = $stmt->get_result();
while ($row = $daily_patients_result->fetch_assoc()) {
    $daily_patients_map[$row['day']] = (int)$row['patients'];
}
$stmt->close();

// Build daily series for charts and tables
$daily_series = [];
$chart_labels = [];
$chart_dates = [];
$chart_revenue = [];
$chart_collected = [];
$chart_tests = [];
$chart_patients = [];

$cursor = clone $period_start;
while ($cursor <= $period_end) {
    $day_key = $cursor->format('Y-m-d');
    $label = $cursor->format('d M');
    $chart_labels[] = $label;
    $chart_dates[] = $day_key;

    $day_revenue = $daily_revenue_map[$day_key]['revenue'] ?? 0.0;
    $day_collected = $daily_revenue_map[$day_key]['collected'] ?? 0.0;
    $day_tests = $daily_tests_map[$day_key] ?? 0;
    $day_patients = $daily_patients_map[$day_key] ?? 0;

    if ($day_revenue > $stats['best_day_value']) {
        $stats['best_day_value'] = $day_revenue;
        $stats['best_day'] = $cursor->format('d M Y');
    }

    $daily_series[] = [
        'raw_date' => $day_key,
        'date_label' => $cursor->format('d M Y'),
        'revenue' => $day_revenue,
        'collected' => $day_collected,
        'tests' => $day_tests,
        'patients' => $day_patients,
        'avg_ticket' => $day_patients > 0 ? ($day_revenue / $day_patients) : 0,
    ];

    $chart_revenue[] = round($day_revenue, 2);
    $chart_collected[] = round($day_collected, 2);
    $chart_tests[] = $day_tests;
    $chart_patients[] = $day_patients;

    $cursor->modify('+1 day');
}

$daily_activity = array_values(array_filter($daily_series, static function ($row) {
    return $row['revenue'] > 0 || $row['tests'] > 0 || $row['patients'] > 0;
}));

// Payment mode distribution
$payment_modes = [];
$stmt = $conn->prepare("SELECT COALESCE(NULLIF(payment_mode, ''), 'Unknown') AS mode, COUNT(*) AS bill_count, SUM(net_amount) AS revenue FROM bills WHERE created_at BETWEEN ? AND ? AND bill_status != 'Void' GROUP BY mode ORDER BY revenue DESC");
$stmt->bind_param('ss', $start_date, $end_date_time);
$stmt->execute();
$payment_result = $stmt->get_result();
while ($row = $payment_result->fetch_assoc()) {
    $payment_modes[] = [
        'mode' => $row['mode'],
        'bill_count' => (int)$row['bill_count'],
        'revenue' => (float)($row['revenue'] ?? 0),
    ];
}
$stmt->close();

// Top tests
$top_tests = [];
$stmt = $conn->prepare("SELECT COALESCE(CONCAT_WS(' - ', t.main_test_name, NULLIF(t.sub_test_name, '')), 'Uncategorized Test') AS test_label, COUNT(bi.id) AS test_count FROM bill_items bi JOIN bills b ON b.id = bi.bill_id AND b.bill_status != 'Void' LEFT JOIN tests t ON bi.test_id = t.id WHERE b.created_at BETWEEN ? AND ? AND bi.item_status = 0 GROUP BY test_label ORDER BY test_count DESC LIMIT 5");
$stmt->bind_param('ss', $start_date, $end_date_time);
$stmt->execute();
$tests_result = $stmt->get_result();
while ($row = $tests_result->fetch_assoc()) {
    $top_tests[] = [
        'label' => $row['test_label'],
        'count' => (int)$row['test_count'],
    ];
}
$stmt->close();

// Top referring doctors
$top_doctors = [];
$stmt = $conn->prepare("SELECT rd.doctor_name, COUNT(DISTINCT b.id) AS bill_count, SUM(b.net_amount) AS revenue FROM bills b JOIN referral_doctors rd ON rd.id = b.referral_doctor_id WHERE b.created_at BETWEEN ? AND ? AND b.bill_status != 'Void' AND b.referral_type = 'Doctor' GROUP BY rd.id, rd.doctor_name ORDER BY revenue DESC LIMIT 5");
$stmt->bind_param('ss', $start_date, $end_date_time);
$stmt->execute();
$doctors_result = $stmt->get_result();
while ($row = $doctors_result->fetch_assoc()) {
    $top_doctors[] = [
        'name' => $row['doctor_name'],
        'bill_count' => (int)$row['bill_count'],
        'revenue' => (float)($row['revenue'] ?? 0),
    ];
}
$stmt->close();

$center_discount_total = 0.0;
$doctor_discount_total = 0.0;
$doctor_discount_count = 0;
$discount_stmt = $conn->prepare("SELECT
        SUM(CASE WHEN b.discount_by = 'Doctor' THEN b.discount ELSE 0 END) AS doctor_discounts,
        SUM(CASE WHEN b.discount_by != 'Doctor' THEN b.discount ELSE 0 END) AS center_discounts,
        COUNT(DISTINCT CASE WHEN b.discount_by = 'Doctor' AND b.discount > 0 THEN b.referral_doctor_id END) AS doctors_providing_discount
    FROM bills b
    WHERE b.created_at BETWEEN ? AND ?
      AND b.bill_status != 'Void'");
if ($discount_stmt) {
    $discount_stmt->bind_param('ss', $start_date, $end_date_time);
    $discount_stmt->execute();
    $discount_row = $discount_stmt->get_result()->fetch_assoc() ?: [];
    $center_discount_total = (float)($discount_row['center_discounts'] ?? 0);
    $doctor_discount_total = (float)($discount_row['doctor_discounts'] ?? 0);
    $doctor_discount_count = (int)($discount_row['doctors_providing_discount'] ?? 0);
    $discount_stmt->close();
}

$doctor_financial_map = [];
$doctor_professional_rows = [];
$professional_charges_total = 0.0;

$doctor_fin_stmt = $conn->prepare("SELECT rd.id AS doctor_id, rd.doctor_name,
        SUM(b.net_amount) AS revenue,
        SUM(CASE WHEN b.discount_by = 'Doctor' THEN b.discount ELSE 0 END) AS doctor_discounts
    FROM bills b
    JOIN referral_doctors rd ON rd.id = b.referral_doctor_id
    WHERE b.created_at BETWEEN ? AND ?
      AND b.bill_status != 'Void'
      AND b.referral_type = 'Doctor'
    GROUP BY rd.id, rd.doctor_name");
if ($doctor_fin_stmt) {
    $doctor_fin_stmt->bind_param('ss', $start_date, $end_date_time);
    $doctor_fin_stmt->execute();
    $fin_result = $doctor_fin_stmt->get_result();
    while ($row = $fin_result->fetch_assoc()) {
        $doctor_id = (int)$row['doctor_id'];
        $doctor_financial_map[$doctor_id] = [
            'doctor_id' => $doctor_id,
            'doctor_name' => $row['doctor_name'] ?? 'Unknown Doctor',
            'revenue' => (float)($row['revenue'] ?? 0),
            'discounts' => (float)($row['doctor_discounts'] ?? 0),
            'professional_charges' => 0.0,
        ];
    }
    $doctor_fin_stmt->close();
}

$professional_stmt = $conn->prepare("SELECT rd.id AS doctor_id, rd.doctor_name,
        SUM(
            CASE
                WHEN COALESCE(dtp.payable_amount, t.default_payable_amount, 0) > 0
                     AND COALESCE(bi.discount_amount, 0) < COALESCE(dtp.payable_amount, t.default_payable_amount, 0)
                THEN COALESCE(dtp.payable_amount, t.default_payable_amount, 0) - COALESCE(bi.discount_amount, 0)
                ELSE 0
            END
        ) AS professional_charge
    FROM bills b
    JOIN bill_items bi ON b.id = bi.bill_id AND bi.item_status = 0
    JOIN tests t ON bi.test_id = t.id
    LEFT JOIN doctor_test_payables dtp ON b.referral_doctor_id = dtp.doctor_id AND bi.test_id = dtp.test_id
    LEFT JOIN referral_doctors rd ON b.referral_doctor_id = rd.id
    WHERE b.created_at BETWEEN ? AND ?
      AND b.bill_status != 'Void'
      AND b.referral_type = 'Doctor'
    GROUP BY rd.id, rd.doctor_name");
if ($professional_stmt) {
    $professional_stmt->bind_param('ss', $start_date, $end_date_time);
    $professional_stmt->execute();
    $pro_result = $professional_stmt->get_result();
    while ($row = $pro_result->fetch_assoc()) {
        $doctor_id = (int)($row['doctor_id'] ?? 0);
        $charge = (float)($row['professional_charge'] ?? 0);
        if ($doctor_id === 0) { continue; }
        if (!isset($doctor_financial_map[$doctor_id])) {
            $doctor_financial_map[$doctor_id] = [
                'doctor_id' => $doctor_id,
                'doctor_name' => $row['doctor_name'] ?? 'Unknown Doctor',
                'revenue' => 0.0,
                'discounts' => 0.0,
                'professional_charges' => 0.0,
            ];
        }
        $doctor_financial_map[$doctor_id]['professional_charges'] = $charge;
        $professional_charges_total += $charge;
    }
    $professional_stmt->close();
}

if (!empty($doctor_financial_map)) {
    $doctor_professional_rows = array_values($doctor_financial_map);
    usort($doctor_professional_rows, static function ($a, $b) {
        return $b['professional_charges'] <=> $a['professional_charges'];
    });
}

$expense_breakdown_rows = [];
$total_monthly_expenses = 0.0;
$expense_stmt = $conn->prepare("SELECT COALESCE(NULLIF(expense_type, ''), 'Uncategorized') AS expense_type,
        SUM(amount) AS total_amount
    FROM expenses
    WHERE created_at BETWEEN ? AND ?
    GROUP BY expense_type
    ORDER BY total_amount DESC");
if ($expense_stmt) {
    $expense_stmt->bind_param('ss', $start_date, $end_date_time);
    $expense_stmt->execute();
    $expense_result = $expense_stmt->get_result();
    while ($row = $expense_result->fetch_assoc()) {
        $amount = (float)($row['total_amount'] ?? 0);
        $expense_breakdown_rows[] = [
            'expense_type' => $row['expense_type'] ?? 'Uncategorized',
            'total_amount' => $amount,
        ];
        $total_monthly_expenses += $amount;
    }
    $expense_stmt->close();
}

$profit_summary = [
    'gross_revenue' => $stats['gross_revenue'],
    'net_revenue' => $stats['revenue'],
    'expenses' => $total_monthly_expenses,
    'professional_charges' => $professional_charges_total,
];
$profit_summary['profit_loss'] = $profit_summary['net_revenue'] - $profit_summary['expenses'];
$profit_summary['actual_left'] = $profit_summary['net_revenue'] - $profit_summary['expenses'] - $profit_summary['professional_charges'];
$profit_loss_value = $profit_summary['profit_loss'];
$profit_loss_class = $profit_loss_value >= 0 ? 'positive' : 'negative';

// Derived metrics
$stats['avg_daily_revenue'] = $days_in_period > 0 ? ($stats['revenue'] / $days_in_period) : 0;
$stats['avg_ticket_size'] = ($stats['bills'] > 0) ? ($stats['revenue'] / $stats['bills']) : 0;
$stats['collection_rate'] = $stats['revenue'] > 0 ? (($stats['collected'] / $stats['revenue']) * 100) : 0;
$stats['tests_per_patient'] = $stats['patients'] > 0 ? ($stats['tests'] / $stats['patients']) : 0;
$stats['avg_daily_tests'] = $days_in_period > 0 ? ($stats['tests'] / $days_in_period) : 0;
if ($stats['previous_revenue'] > 0) {
    $stats['revenue_change_pct'] = (($stats['revenue'] - $stats['previous_revenue']) / $stats['previous_revenue']) * 100;
}

// Encode datasets for charts
$chart_payload = [
    'labels' => $chart_labels,
    'dates' => $chart_dates,
    'revenue' => $chart_revenue,
    'collected' => $chart_collected,
    'tests' => $chart_tests,
    'patients' => $chart_patients,
];

$payment_chart_payload = [
    'labels' => array_map(static function ($row) { return $row['mode']; }, $payment_modes),
    'revenue' => array_map(static function ($row) { return round($row['revenue'], 2); }, $payment_modes),
];

$doctor_professional_json = json_encode($doctor_professional_rows, JSON_NUMERIC_CHECK);
$expense_breakdown_json = json_encode($expense_breakdown_rows, JSON_NUMERIC_CHECK);
$profit_summary_json = json_encode($profit_summary, JSON_NUMERIC_CHECK);

$period_label = $period_start->format('F Y');
$previous_period_label = $previous_start->format('F Y');
$best_day_label = $stats['best_day'] ?? '—';
$chart_payload_json = json_encode($chart_payload, JSON_NUMERIC_CHECK);
$payment_chart_payload_json = json_encode($payment_chart_payload, JSON_NUMERIC_CHECK);
$tests_total = max($stats['tests'], 1);
$revenue_total = max($stats['revenue'], 0.01);

$has_payment_mix = !empty($payment_modes);
$has_top_tests = !empty($top_tests);
$has_top_doctors = !empty($top_doctors);
$has_daily_activity = !empty($daily_activity);

$revenue_delta = $stats['revenue_change_pct'];
$delta_class = $revenue_delta === null ? 'neutral' : ($revenue_delta >= 0 ? 'positive' : 'negative');
$delta_text = $revenue_delta === null ? 'No baseline' : (sprintf('%+0.1f%%', $revenue_delta));

?>

<style>
.monthly-analysis-bg {
    width: 100%;
}
.monthly-analysis-container {
    display: flex;
    flex-direction: column;
    gap: clamp(1rem, 2.5vw, 2rem);
    width: 100%;
    max-width: none;
    padding: clamp(1rem, 1.6vw, 1.5rem);
    box-sizing: border-box;
    flex: 1 1 auto;
    background: var(--bg-primary, #ffffff);
    border-radius: 16px;
    border: 1px solid var(--border-light);
    box-shadow: var(--shadow-sm);
}
.monthly-analysis-container .analysis-wrapper {
    display: flex;
    flex-direction: column;
    gap: clamp(1rem, 2vw, 1.75rem);
}
.monthly-analysis-container .insights-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: clamp(0.85rem, 2vw, 1.25rem);
}
.monthly-analysis-container .chart-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: clamp(0.85rem, 2vw, 1.25rem);
}
.monthly-analysis-container .chart-card,
.monthly-analysis-container .table-card,
.monthly-analysis-container .filter-card,
.monthly-analysis-container .header-card {
    width: 100%;
}
.insight-card.interactive {
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.insight-card.interactive:hover {
    transform: translateY(-4px);
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
}
.insight-card .value.positive {
    color: #0f9d58;
}
.insight-card .value.negative {
    color: #d93025;
}
@media (max-width: 640px) {
    .monthly-analysis-container {
        gap: 1rem;
        padding: 1rem;
    }
    .monthly-analysis-container .filter-card .filter-group {
        flex-direction: column;
    }
    .monthly-analysis-container .filter-card .form-group {
        width: 100%;
    }
    .monthly-analysis-container .chart-grid,
    .monthly-analysis-container .insights-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 420px) {
    .monthly-analysis-container {
        padding: 0.75rem;
    }
    .monthly-analysis-container .header-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
    }
}
</style>

<div class="monthly-analysis-bg">
    <div class="monthly-analysis-container main-content analysis-page-layout">
    <div class="header-card">
        <div>
            <h1>Monthly Analysis</h1>
            <p class="muted" style="margin-top: 4px;">Deep dive for <?php echo htmlspecialchars($period_label); ?></p>
        </div>
        <a href="dashboard.php" class="btn-back btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <div class="filter-card">
        <form method="GET" action="monthly_analysis.php" class="filter-form compact-filters" style="margin-bottom: 0;">
            <div class="filter-group">
                <div class="form-group flex-grow-1">
                    <label>Year</label>
                    <select name="year" style="min-width: 120px;">
                        <?php for($y = date('Y'); $y >= 2020; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group flex-grow-1">
                    <label>Month</label>
                    <select name="month" style="min-width: 150px;">
                        <?php for($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo ($selected_month == $m) ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-submit">View Analysis</button>
            </div>
        </form>
    </div>

    <div class="analysis-wrapper">

        <div class="insights-grid">
            <div class="insight-card highlight">
                <div class="meta-row">
                    <h3>Total Revenue</h3>
                    <span class="delta <?php echo $delta_class; ?>"><?php echo htmlspecialchars($delta_text); ?></span>
                </div>
                <p class="value">₹ <?php echo number_format($stats['revenue'], 2); ?></p>
                <p class="muted">Compared to <?php echo htmlspecialchars($previous_period_label); ?></p>
            </div>
            <div class="insight-card">
                <h3>Amount Collected</h3>
                <p class="value">₹ <?php echo number_format($stats['collected'], 2); ?></p>
                <div class="meta-row">
                    <span>Collection Rate</span>
                    <span class="delta <?php echo ($stats['collection_rate'] >= 95) ? 'positive' : 'neutral'; ?>"><?php echo number_format($stats['collection_rate'], 1); ?>%</span>
                </div>
            </div>
            <div class="insight-card">
                <h3>Outstanding Balance</h3>
                <p class="value">₹ <?php echo number_format($stats['outstanding'], 2); ?></p>
                <p class="muted">Strongest day: <?php echo htmlspecialchars($best_day_label); ?></p>
            </div>
            <div class="insight-card">
                <h3>Total Tests</h3>
                <p class="value"><?php echo number_format($stats['tests']); ?></p>
                <div class="meta-row">
                    <span>Avg. Daily</span>
                    <span class="pill"><?php echo number_format($stats['avg_daily_tests'], 1); ?> / day</span>
                </div>
            </div>
            <div class="insight-card">
                <h3>Total Patients</h3>
                <p class="value"><?php echo number_format($stats['patients']); ?></p>
                <div class="meta-row">
                    <span>Tests per Patient</span>
                    <span class="pill"><?php echo number_format($stats['tests_per_patient'], 2); ?></span>
                </div>
            </div>
            <div class="insight-card">
                <h3>Average Ticket Size</h3>
                <p class="value">₹ <?php echo number_format($stats['avg_ticket_size'], 2); ?></p>
                <p class="muted">Across <?php echo number_format($stats['bills']); ?> bills</p>
            </div>
            <div class="insight-card">
                <h3>Total Discounts by Center</h3>
                <p class="value">₹ <?php echo number_format($center_discount_total, 2); ?></p>
                <p class="muted">Promotions absorbed by the diagnostic center</p>
            </div>
            <div class="insight-card">
                <div class="meta-row">
                    <h3>Total Discounts by Doctors</h3>
                    <span class="pill"><?php echo number_format($doctor_discount_count); ?> doctors</span>
                </div>
                <p class="value">₹ <?php echo number_format($doctor_discount_total, 2); ?></p>
                <p class="muted">Doctor-funded concessions this month</p>
            </div>
            <div class="insight-card interactive" id="professionalChargesTile">
                <h3>Total Professional Charges</h3>
                <p class="value">₹ <?php echo number_format($professional_charges_total, 2); ?></p>
                <p class="muted">Click to view doctor-wise payouts</p>
            </div>
            <div class="insight-card interactive" id="totalExpensesTile">
                <h3>Total Expenses</h3>
                <p class="value">₹ <?php echo number_format($total_monthly_expenses, 2); ?></p>
                <p class="muted">Click for expense-type breakdown</p>
            </div>
            <div class="insight-card interactive" id="profitLossTile">
                <h3>Profit / Loss</h3>
                <p class="value <?php echo $profit_loss_class; ?>">₹ <?php echo number_format($profit_loss_value, 2); ?></p>
                <p class="muted">Click to review revenue vs. expenses</p>
            </div>
        </div>

        <div class="chart-grid">
            <div class="chart-card">
                <div class="section-header">
                    <h3>Revenue vs Collections Trend</h3>
                    <span><?php echo htmlspecialchars($period_label); ?></span>
                </div>
                <div class="chart-container">
                    <canvas id="revenueTrendChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="section-header">
                    <h3>Payment Mix</h3>
                    <span>Share of revenue</span>
                </div>
                <?php if ($has_payment_mix): ?>
                    <div class="chart-container">
                        <canvas id="paymentMixChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No payment data recorded for this period.</div>
                <?php endif; ?>
            </div>
            <div class="chart-card" style="grid-column: 1 / -1;">
                <div class="section-header">
                    <h3>Daily Tests vs Patients</h3>
                    <span>Operational throughput</span>
                </div>
                <div class="chart-container">
                    <canvas id="testsTrendChart"></canvas>
                </div>
            </div>
        </div>

        <div class="table-card">
            <div class="section-header">
                <h3>Daily Performance Snapshot</h3>
                <span>Revenue and footfall by day</span>
            </div>
            <?php if ($has_daily_activity): ?>
                <div class="table-responsive">
                    <table class="data-table" id="dailyPerformanceTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Revenue (₹)</th>
                                <th>Collected (₹)</th>
                                <th>Patients</th>
                                <th>Tests</th>
                                <th>Avg. Ticket (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($daily_activity as $row): ?>
                                <tr class="clickable-row" data-date="<?php echo htmlspecialchars($row['raw_date']); ?>">
                                    <td data-order="<?php echo htmlspecialchars($row['raw_date']); ?>"><?php echo htmlspecialchars($row['date_label']); ?></td>
                                    <td data-order="<?php echo $row['revenue']; ?>"><?php echo number_format($row['revenue'], 2); ?></td>
                                    <td data-order="<?php echo $row['collected']; ?>"><?php echo number_format($row['collected'], 2); ?></td>
                                    <td data-order="<?php echo $row['patients']; ?>"><?php echo number_format($row['patients']); ?></td>
                                    <td data-order="<?php echo $row['tests']; ?>"><?php echo number_format($row['tests']); ?></td>
                                    <td data-order="<?php echo $row['avg_ticket']; ?>"><?php echo number_format($row['avg_ticket'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No billable activity recorded for this month.</div>
            <?php endif; ?>
        </div>

        <div class="chart-grid">
            <div class="table-card">
                <h3>Top Tests</h3>
                <?php if ($has_top_tests): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Test</th>
                                    <th>Orders</th>
                                    <th>Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_tests as $test): ?>
                                    <?php $test_share = ($stats['tests'] > 0) ? (($test['count'] / $stats['tests']) * 100) : 0; ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($test['label']); ?></td>
                                        <td><?php echo number_format($test['count']); ?></td>
                                        <td><?php echo number_format($test_share, 1); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No tests were billed this month.</div>
                <?php endif; ?>
            </div>
            <div class="table-card">
                <h3>Top Referring Doctors</h3>
                <?php if ($has_top_doctors): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Doctor</th>
                                    <th>Bills</th>
                                    <th>Revenue (₹)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_doctors as $doctor): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($doctor['name']); ?></td>
                                        <td><?php echo number_format($doctor['bill_count']); ?></td>
                                        <td><?php echo number_format($doctor['revenue'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No doctor referrals recorded for this month.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const revenuePayload = <?php echo $chart_payload_json; ?>;
    const paymentPayload = <?php echo $payment_chart_payload_json; ?>;
    const doctorProfessionalData = <?php echo $doctor_professional_json; ?>;
    const expenseBreakdownData = <?php echo $expense_breakdown_json; ?>;
    const profitSummary = <?php echo $profit_summary_json; ?>;
    const formatCurrency = (value = 0) => new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(Number(value) || 0);

    // Function to fetch and show details
    window.fetchAndShowDetails = function(date) {
        Swal.fire({
            title: 'Loading details...',
            didOpen: () => {
                Swal.showLoading();
            }
        });

        fetch(`ajax_daily_details.php?date=${date}`)
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message);
                }

                let html = `
                    <div style="text-align: left; font-size: 0.9rem;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 15px; background: #f8f9fa; padding: 10px; border-radius: 8px;">
                            <div><strong>Revenue:</strong><br>₹${data.summary.revenue.toLocaleString()}</div>
                            <div><strong>Patients:</strong><br>${data.summary.patients}</div>
                            <div><strong>Tests:</strong><br>${data.summary.tests}</div>
                        </div>
                        
                        <h5 style="margin: 10px 0 5px; color: #4e73df;">Top Tests</h5>
                        <ul style="margin-bottom: 15px; padding-left: 20px;">
                            ${data.tests.length ? data.tests.map(t => `<li>${t.name} (${t.count})</li>`).join('') : '<li>No tests recorded</li>'}
                        </ul>

                        <h5 style="margin: 10px 0 5px; color: #4e73df;">Recent Bills</h5>
                        <div style="max-height: 200px; overflow-y: auto; border: 1px solid #e3e6f0; border-radius: 4px;">
                            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                                <thead style="background: #f8f9fa; position: sticky; top: 0;">
                                    <tr>
                                        <th style="padding: 5px; text-align: left;">Bill #</th>
                                        <th style="padding: 5px; text-align: left;">Patient</th>
                                        <th style="padding: 5px; text-align: right;">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.bills.length ? data.bills.map(b => `
                                        <tr style="border-bottom: 1px solid #eee;">
                                            <td style="padding: 5px;">${b.id}</td>
                                            <td style="padding: 5px;">${b.patient}<br><small style="color: #888;">${b.doctor}</small></td>
                                            <td style="padding: 5px; text-align: right;">₹${b.amount.toLocaleString()}</td>
                                        </tr>
                                    `).join('') : '<tr><td colspan="3" style="padding: 10px; text-align: center;">No bills found</td></tr>'}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;

                Swal.fire({
                    title: `Details for ${data.date}`,
                    html: html,
                    width: '600px',
                    showCloseButton: true,
                    confirmButtonText: 'Close'
                });
            })
            .catch(error => {
                Swal.fire('Error', error.message || 'Failed to load details', 'error');
            });
    };

    const showProfessionalChargesModal = () => {
        const hasData = Array.isArray(doctorProfessionalData) && doctorProfessionalData.length > 0;
        const rows = hasData
            ? doctorProfessionalData.map((row) => `
                <tr>
                    <td>${row.doctor_name || 'Unknown Doctor'}</td>
                    <td>${formatCurrency(row.revenue || 0)}</td>
                    <td>${formatCurrency(row.discounts || 0)}</td>
                    <td>${formatCurrency(row.professional_charges || 0)}</td>
                </tr>
            `).join('')
            : '<tr><td colspan="4" style="text-align:center;padding:12px;">No doctor payouts recorded for this month.</td></tr>';

        Swal.fire({
            title: 'Doctor Professional Charges',
            html: `
                <div style="max-height:420px; overflow:auto; text-align:left;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                        <thead>
                            <tr style="background:#f5f6fb;">
                                <th style="padding:8px; text-align:left;">Doctor</th>
                                <th style="padding:8px; text-align:right;">Revenue</th>
                                <th style="padding:8px; text-align:right;">Discounts</th>
                                <th style="padding:8px; text-align:right;">Professional Charges</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rows}
                        </tbody>
                    </table>
                </div>
            `,
            width: '720px',
            showCloseButton: true,
            confirmButtonText: 'Close'
        });
    };

    const showExpenseBreakdownModal = () => {
        const hasData = Array.isArray(expenseBreakdownData) && expenseBreakdownData.length > 0;
        const rows = hasData
            ? expenseBreakdownData.map((row) => `
                <tr>
                    <td>${row.expense_type || 'Uncategorized'}</td>
                    <td style="text-align:right;">${formatCurrency(row.total_amount || 0)}</td>
                </tr>
            `).join('')
            : '<tr><td colspan="2" style="text-align:center;padding:12px;">No expenses recorded for this month.</td></tr>';

        Swal.fire({
            title: 'Monthly Expense Breakdown',
            html: `
                <div style="max-height:360px; overflow:auto; text-align:left;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                        <thead>
                            <tr style="background:#f5f6fb;">
                                <th style="padding:8px; text-align:left;">Expense Type</th>
                                <th style="padding:8px; text-align:right;">Total Expense Paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rows}
                        </tbody>
                    </table>
                </div>
            `,
            width: '600px',
            showCloseButton: true,
            confirmButtonText: 'Close'
        });
    };

    const showProfitSummaryModal = () => {
        if (!profitSummary) {
            Swal.fire('Info', 'No profit summary is available for this period.', 'info');
            return;
        }
        const html = `
            <div style="text-align:left; font-size:0.95rem; line-height:1.6;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
                    <div><strong>Gross Revenue:</strong><br>${formatCurrency(profitSummary.gross_revenue || 0)}</div>
                    <div><strong>Net Revenue:</strong><br>${formatCurrency(profitSummary.net_revenue || 0)}</div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
                    <div><strong>Expenses:</strong><br>${formatCurrency(profitSummary.expenses || 0)}</div>
                    <div><strong>Professional Charges:</strong><br>${formatCurrency(profitSummary.professional_charges || 0)}</div>
                </div>
                <div style="padding:12px; background:#f5f6fb; border-radius:10px; margin-bottom:10px;">
                    <strong>Profit / Loss (Net Revenue - Expenses):</strong>
                    <div style="font-size:1.1rem; font-weight:600; color:${(profitSummary.profit_loss || 0) >= 0 ? '#0f9d58' : '#d93025'};">
                        ${formatCurrency(profitSummary.profit_loss || 0)}
                    </div>
                </div>
                <div style="padding:12px; background:#eefbf3; border-radius:10px;">
                    <strong>Actual Left (Net Revenue - Expenses - Professional Charges):</strong>
                    <div style="font-size:1.1rem; font-weight:600; color:${(profitSummary.actual_left || 0) >= 0 ? '#0f9d58' : '#d93025'};">
                        ${formatCurrency(profitSummary.actual_left || 0)}
                    </div>
                </div>
            </div>
        `;

        Swal.fire({
            title: 'Profit & Loss Breakdown',
            html,
            width: '600px',
            showCloseButton: true,
            confirmButtonText: 'Close'
        });
    };

    const attachTileHandler = (id, handler) => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('click', handler);
        }
    };

    attachTileHandler('professionalChargesTile', showProfessionalChargesModal);
    attachTileHandler('totalExpensesTile', showExpenseBreakdownModal);
    attachTileHandler('profitLossTile', showProfitSummaryModal);

    // Attach click to table rows
    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', function() {
            const date = this.getAttribute('data-date');
            if (date) fetchAndShowDetails(date);
        });
    });

    if (typeof Chart !== 'undefined') {
        const revenueCanvas = document.getElementById('revenueTrendChart');
        if (revenueCanvas) {
            new Chart(revenueCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: revenuePayload.labels,
                    datasets: [
                        {
                            label: 'Revenue',
                            data: revenuePayload.revenue,
                            borderColor: '#4e73df',
                            backgroundColor: 'rgba(78, 115, 223, 0.12)',
                            borderWidth: 3,
                            tension: 0.35,
                            fill: true,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        },
                        {
                            label: 'Collected',
                            data: revenuePayload.collected,
                            borderColor: '#1cc88a',
                            backgroundColor: 'rgba(28, 200, 138, 0.15)',
                            borderWidth: 2,
                            tension: 0.35,
                            fill: true,
                            pointRadius: 3,
                            pointHoverRadius: 5
                        }
                    ]
                },
                options: {
                    plugins: {
                        legend: { display: true },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: value => '₹ ' + value.toLocaleString()
                            }
                        }
                    },
                    onClick: (e, activeEls) => {
                        if (activeEls.length > 0) {
                            const index = activeEls[0].index;
                            const date = revenuePayload.dates[index];
                            if (date) fetchAndShowDetails(date);
                        }
                    },
                    onHover: (event, chartElement) => {
                        event.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
                    }
                }
            });
        }

        const paymentCanvas = document.getElementById('paymentMixChart');
        if (paymentCanvas && paymentPayload.labels.length > 0) {
            new Chart(paymentCanvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: paymentPayload.labels,
                    datasets: [{
                        data: paymentPayload.revenue,
                        backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796'],
                        borderWidth: 0
                    }]
                },
                options: {
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: context => {
                                    const total = paymentPayload.revenue.reduce((acc, val) => acc + val, 0) || 1;
                                    const value = context.parsed;
                                    const share = (value / total) * 100;
                                    return `${context.label}: ₹ ${value.toLocaleString(undefined, { minimumFractionDigits: 2 })} (${share.toFixed(1)}%)`;
                                }
                            }
                        }
                    },
                    cutout: '62%'
                }
            });
        }

        const testsCanvas = document.getElementById('testsTrendChart');
        if (testsCanvas) {
            new Chart(testsCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: revenuePayload.labels,
                    datasets: [
                        {
                            type: 'bar',
                            label: 'Tests',
                            data: revenuePayload.tests,
                            backgroundColor: 'rgba(54, 185, 204, 0.6)',
                            borderRadius: 6,
                            maxBarThickness: 26
                        },
                        {
                            type: 'line',
                            label: 'Patients',
                            data: revenuePayload.patients,
                            borderColor: '#f6c23e',
                            backgroundColor: 'rgba(246, 194, 62, 0.2)',
                            borderWidth: 3,
                            tension: 0.25,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Tests' }
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            grid: { drawOnChartArea: false },
                            title: { display: true, text: 'Patients' }
                        }
                    },
                    onClick: (e, activeEls) => {
                        if (activeEls.length > 0) {
                            const index = activeEls[0].index;
                            const date = revenuePayload.dates[index];
                            if (date) fetchAndShowDetails(date);
                        }
                    },
                    onHover: (event, chartElement) => {
                        event.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
                    }
                }
            });
        }
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
