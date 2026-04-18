<?php
$page_title = "Doctor Analysis";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

// --- 1. Fetch Filter Options ---

// Doctors
$doctors_list = [];
$doc_res = $conn->query("SELECT id, doctor_name FROM referral_doctors ORDER BY doctor_name");
if ($doc_res) {
    while($d = $doc_res->fetch_assoc()) { $doctors_list[] = $d; }
}

// Tests
$tests_list = [];
$test_res = $conn->query("SELECT id, main_test_name, sub_test_name FROM tests ORDER BY main_test_name, sub_test_name");
if ($test_res) {
    while($t = $test_res->fetch_assoc()) { $tests_list[] = $t; }
}

// --- 2. Handle Filter Inputs ---

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$filter_doctor = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
$filter_test = isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0;
$filter_patient = isset($_GET['patient_search']) ? trim($_GET['patient_search']) : '';

// Create DateTime objects for the selected period
$period_start = DateTime::createFromFormat('Y-m-d', $start_date);
$period_end = DateTime::createFromFormat('Y-m-d', $end_date);

// Validate dates
if (!$period_start || !$period_end || $period_start > $period_end) {
    $period_start = new DateTime('first day of this month');
    $period_end = new DateTime('today');
    $start_date = $period_start->format('Y-m-d');
    $end_date = $period_end->format('Y-m-d');
}

$end_date_time = $end_date . ' 23:59:59';

// Calculate range metadata
$interval = $period_start->diff($period_end);
$days_diff = $interval->days + 1;

// --- 3. Build Dynamic Query Parts ---

// Base joins and conditions
// We will use alias 'b' for bills, 'p' for patients
$joins = "";
if ($filter_patient) {
    $joins .= " JOIN patients p ON b.patient_id = p.id";
}

$conditions = " AND b.bill_status != 'Void' AND b.referral_type = 'Doctor'";
$params = [];
$types = "";

if ($filter_doctor) {
    $conditions .= " AND b.referral_doctor_id = ?";
    $params[] = $filter_doctor;
    $types .= "i";
}

if ($filter_test) {
    // Filter bills that contain the specific test
    $conditions .= " AND EXISTS (SELECT 1 FROM bill_items bi_check WHERE bi_check.bill_id = b.id AND bi_check.test_id = ?)";
    $params[] = $filter_test;
    $types .= "i";
}

if ($filter_patient) {
    $conditions .= " AND p.name LIKE ?";
    $params[] = "%$filter_patient%";
    $types .= "s";
}

// Helper function to execute queries with dynamic filters
function get_aggregated_stats($conn, $start, $end, $joins, $conditions, $params, $types) {
    // Prepend date params
    $full_params = array_merge([$start, $end], $params);
    $full_types = "ss" . $types;
    
    $sql = "SELECT 
                SUM(COALESCE(b.gross_amount, 0)) AS total_gross,
                SUM(COALESCE(b.discount, 0)) AS total_discount,
                SUM(b.net_amount) AS total_revenue, 
                SUM(b.amount_paid) AS total_collected, 
                SUM(b.balance_amount) AS total_outstanding, 
                COUNT(b.id) AS total_bills 
            FROM bills b 
            $joins
            WHERE b.created_at BETWEEN ? AND ? $conditions";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($full_types, ...$full_params);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res;
}

function get_professional_charges($conn, $start, $end, $joins, $conditions, $params, $types) {
    $full_params = array_merge([$start, $end], $params);
    $full_types = "ss" . $types;

    $sql = "SELECT SUM(COALESCE(dtp.payable_amount, t.default_payable_amount, 0)) AS total_professional
            FROM bills b
            JOIN bill_items bi ON bi.bill_id = b.id AND bi.item_status = 0
            LEFT JOIN tests t ON bi.test_id = t.id
            LEFT JOIN doctor_test_payables dtp ON dtp.doctor_id = b.referral_doctor_id AND dtp.test_id = bi.test_id
            $joins
            WHERE b.created_at BETWEEN ? AND ? $conditions";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($full_types, ...$full_params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (float)($result['total_professional'] ?? 0);
}

// --- 4. Fetch Data ---

// Current Period Stats
$current_data = get_aggregated_stats($conn, $start_date, $end_date_time, $joins, $conditions, $params, $types);

$stats = [
    'gross_revenue' => (float)($current_data['total_gross'] ?? 0),
    'discount_total' => (float)($current_data['total_discount'] ?? 0),
    'net_amount' => (float)($current_data['total_revenue'] ?? 0),
    'revenue' => (float)($current_data['total_revenue'] ?? 0),
    'collected' => (float)($current_data['total_collected'] ?? 0),
    'outstanding' => (float)($current_data['total_outstanding'] ?? 0),
    'bills' => (int)($current_data['total_bills'] ?? 0),
    'tests' => 0,
    'patients' => 0,
    'best_day' => null,
    'best_day_value' => 0,
    'professional_charges' => 0,
];

// Professional Charges (Referral payouts)
$stats['professional_charges'] = get_professional_charges($conn, $start_date, $end_date_time, $joins, $conditions, $params, $types);

// Total Tests
// Note: If filtering by test, we still count all tests in the matching bills, or just the matching tests?
// Let's count ALL tests in the matching bills to show "Volume generated by these referrals"
$sql_tests = "SELECT COUNT(bi.id) AS total_tests 
              FROM bill_items bi 
              JOIN bills b ON bi.bill_id = b.id 
              $joins
              WHERE b.created_at BETWEEN ? AND ? AND bi.item_status = 0 $conditions";
$stmt = $conn->prepare($sql_tests);
$full_params = array_merge([$start_date, $end_date_time], $params);
$full_types = "ss" . $types;
$stmt->bind_param($full_types, ...$full_params);
$stmt->execute();
$stats['tests'] = (int)($stmt->get_result()->fetch_assoc()['total_tests'] ?? 0);
$stmt->close();

// Total Patients
$sql_patients = "SELECT COUNT(DISTINCT b.patient_id) AS total_patients 
                 FROM bills b 
                 $joins
                 WHERE b.created_at BETWEEN ? AND ? $conditions";
$stmt = $conn->prepare($sql_patients);
$stmt->bind_param($full_types, ...$full_params);
$stmt->execute();
$stats['patients'] = (int)($stmt->get_result()->fetch_assoc()['total_patients'] ?? 0);
$stmt->close();

// Daily Revenue Map
$daily_revenue_map = [];
$sql_daily = "SELECT DATE(b.created_at) AS day, SUM(b.net_amount) AS revenue, SUM(b.amount_paid) AS collected 
              FROM bills b 
              $joins
              WHERE b.created_at BETWEEN ? AND ? $conditions 
              GROUP BY day ORDER BY day";
$stmt = $conn->prepare($sql_daily);
$stmt->bind_param($full_types, ...$full_params);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $daily_revenue_map[$row['day']] = [
        'revenue' => (float)$row['revenue'],
        'collected' => (float)$row['collected']
    ];
}
$stmt->close();

// Daily Tests Map
$daily_tests_map = [];
$sql_daily_tests = "SELECT DATE(b.created_at) AS day, COUNT(bi.id) AS tests 
                    FROM bills b 
                    JOIN bill_items bi ON b.id = bi.bill_id AND bi.item_status = 0
                    $joins
                    WHERE b.created_at BETWEEN ? AND ? $conditions 
                    GROUP BY day ORDER BY day";
$stmt = $conn->prepare($sql_daily_tests);
$stmt->bind_param($full_types, ...$full_params);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $daily_tests_map[$row['day']] = (int)$row['tests'];
}
$stmt->close();

// Daily Patients Map
$daily_patients_map = [];
$sql_daily_patients = "SELECT DATE(b.created_at) AS day, COUNT(DISTINCT b.patient_id) AS patients 
                       FROM bills b 
                       $joins
                       WHERE b.created_at BETWEEN ? AND ? $conditions 
                       GROUP BY day ORDER BY day";
$stmt = $conn->prepare($sql_daily_patients);
$stmt->bind_param($full_types, ...$full_params);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $daily_patients_map[$row['day']] = (int)$row['patients'];
}
$stmt->close();

// Build Chart Data
$daily_series = [];
$chart_labels = [];
$chart_revenue = [];
$cursor = clone $period_start;
while ($cursor <= $period_end) {
    $day_key = $cursor->format('Y-m-d');
    $label = $cursor->format('d M');
    
    $rev = $daily_revenue_map[$day_key]['revenue'] ?? 0;
    $col = $daily_revenue_map[$day_key]['collected'] ?? 0;
    $tst = $daily_tests_map[$day_key] ?? 0;
    $pat = $daily_patients_map[$day_key] ?? 0;

    if ($rev > $stats['best_day_value']) {
        $stats['best_day_value'] = $rev;
        $stats['best_day'] = $cursor->format('d M Y');
    }

    $daily_series[] = [
        'date_label' => $cursor->format('d M Y'),
        'revenue' => $rev,
        'collected' => $col,
        'tests' => $tst,
        'patients' => $pat
    ];
    
    $chart_labels[] = $label;
    $chart_revenue[] = $rev;
    
    $cursor->modify('+1 day');
}

$daily_activity = array_values(array_filter($daily_series, function($r) {
    return $r['revenue'] > 0 || $r['tests'] > 0;
}));

// Doctor / Test leaderboard data
$doctor_table_mode = $filter_doctor ? 'tests' : 'doctors';
$top_table_rows = [];
$doctor_test_breakdowns = [];

$professional_case_expr = "CASE 
        WHEN COALESCE(dtp.payable_amount, t.default_payable_amount, 0) <= 0 THEN 0 
        WHEN b.discount_by = 'Doctor' AND COALESCE(bi.discount_amount, 0) > COALESCE(dtp.payable_amount, t.default_payable_amount, 0) THEN 0 
        ELSE COALESCE(dtp.payable_amount, t.default_payable_amount, 0) 
    END";

if ($doctor_table_mode === 'tests') {
    $sql_tests_overview = "SELECT 
            COALESCE(t.main_test_name, 'Uncategorised') AS main_test_name,
            COUNT(bi.id) AS referrals,
            SUM(COALESCE(t.price, 0) + COALESCE(bis.screening_amount, 0)) AS gross_amount,
            SUM((COALESCE(t.price, 0) + COALESCE(bis.screening_amount, 0)) - COALESCE(bi.discount_amount, 0)) AS net_amount,
            SUM(COALESCE(bi.discount_amount, 0)) AS discount_amount,
            SUM($professional_case_expr) AS professional_charges
        FROM bills b
        JOIN bill_items bi ON bi.bill_id = b.id AND bi.item_status = 0
        JOIN tests t ON bi.test_id = t.id
        LEFT JOIN bill_item_screenings bis ON bis.bill_item_id = bi.id
        LEFT JOIN doctor_test_payables dtp ON dtp.doctor_id = b.referral_doctor_id AND dtp.test_id = bi.test_id
        $joins
        WHERE b.created_at BETWEEN ? AND ? $conditions
        GROUP BY COALESCE(t.main_test_name, 'Uncategorised')
        ORDER BY gross_amount DESC";

    $stmt = $conn->prepare($sql_tests_overview);
    $stmt->bind_param($full_types, ...$full_params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $top_table_rows[] = [
            'test_name' => $row['main_test_name'] ?? 'Uncategorised',
            'referrals' => (int)($row['referrals'] ?? 0),
            'gross' => (float)($row['gross_amount'] ?? 0),
            'net' => (float)($row['net_amount'] ?? 0),
            'discount' => (float)($row['discount_amount'] ?? 0),
            'professional' => (float)($row['professional_charges'] ?? 0),
        ];
    }
    $stmt->close();
} else {
    $sql_doctor_overview = "SELECT 
            b.referral_doctor_id AS doctor_id,
            COALESCE(rd.doctor_name, 'Unknown Doctor') AS doctor_name,
            SUM(COALESCE(t.price, 0) + COALESCE(bis.screening_amount, 0)) AS gross_amount,
            SUM((COALESCE(t.price, 0) + COALESCE(bis.screening_amount, 0)) - COALESCE(bi.discount_amount, 0)) AS net_amount,
            SUM(COALESCE(bi.discount_amount, 0)) AS discount_amount,
            SUM($professional_case_expr) AS professional_charges
        FROM bills b
        JOIN bill_items bi ON bi.bill_id = b.id AND bi.item_status = 0
        JOIN tests t ON bi.test_id = t.id
        LEFT JOIN bill_item_screenings bis ON bis.bill_item_id = bi.id
        LEFT JOIN doctor_test_payables dtp ON dtp.doctor_id = b.referral_doctor_id AND dtp.test_id = bi.test_id
        LEFT JOIN referral_doctors rd ON rd.id = b.referral_doctor_id
        $joins
        WHERE b.created_at BETWEEN ? AND ? $conditions AND b.referral_doctor_id IS NOT NULL
        GROUP BY doctor_id, doctor_name
        ORDER BY gross_amount DESC";

    $stmt = $conn->prepare($sql_doctor_overview);
    $stmt->bind_param($full_types, ...$full_params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $top_table_rows[] = [
            'doctor_id' => (int)($row['doctor_id'] ?? 0),
            'doctor_name' => $row['doctor_name'] ?? 'Unknown Doctor',
            'gross' => (float)($row['gross_amount'] ?? 0),
            'net' => (float)($row['net_amount'] ?? 0),
            'discount' => (float)($row['discount_amount'] ?? 0),
            'professional' => (float)($row['professional_charges'] ?? 0),
        ];
    }
    $stmt->close();

    $sql_doctor_tests = "SELECT 
            b.referral_doctor_id AS doctor_id,
            COALESCE(t.main_test_name, 'Uncategorised') AS main_test_name,
            SUM((COALESCE(t.price, 0) + COALESCE(bis.screening_amount, 0)) - COALESCE(bi.discount_amount, 0)) AS revenue
        FROM bills b
        JOIN bill_items bi ON bi.bill_id = b.id AND bi.item_status = 0
        JOIN tests t ON bi.test_id = t.id
        LEFT JOIN bill_item_screenings bis ON bis.bill_item_id = bi.id
        $joins
        WHERE b.created_at BETWEEN ? AND ? $conditions AND b.referral_doctor_id IS NOT NULL
        GROUP BY doctor_id, main_test_name
        ORDER BY doctor_id ASC";

    $stmt = $conn->prepare($sql_doctor_tests);
    $stmt->bind_param($full_types, ...$full_params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $docId = (int)($row['doctor_id'] ?? 0);
        if ($docId === 0) {
            continue;
        }
        if (!isset($doctor_test_breakdowns[$docId])) {
            $doctor_test_breakdowns[$docId] = [];
        }
        $doctor_test_breakdowns[$docId][] = [
            'name' => $row['main_test_name'] ?? 'Uncategorised',
            'revenue' => (float)($row['revenue'] ?? 0)
        ];
    }
    $stmt->close();

    foreach ($doctor_test_breakdowns as $docId => $tests_list) {
        usort($tests_list, function ($a, $b) {
            return $b['revenue'] <=> $a['revenue'];
        });
        $doctor_test_breakdowns[$docId] = $tests_list;
    }
}

// Derived Metrics
$days_in_period = max(1, $days_diff);
$stats['avg_daily_revenue'] = $stats['revenue'] / $days_in_period;
$stats['avg_ticket_size'] = $stats['bills'] > 0 ? $stats['revenue'] / $stats['bills'] : 0;
$stats['tests_per_patient'] = $stats['patients'] > 0 ? $stats['tests'] / $stats['patients'] : 0;

$chart_payload_json = json_encode([
    'labels' => $chart_labels,
    'revenue' => $chart_revenue
], JSON_NUMERIC_CHECK);

$period_label = $period_start->format('d M Y') . ' - ' . $period_end->format('d M Y');

?>

<div class="main-content page-container analysis-page-layout">
    <div class="header-card">
        <div class="dashboard-header-text">
            <h1>Doctor Analysis</h1>
            <p class="muted" style="margin-top: 4px;">Deep dive into doctor referrals for <?php echo htmlspecialchars($period_label); ?></p>
        </div>
        <a href="dashboard.php" class="btn-back btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <div class="filter-card">
        <form method="GET" action="deep_analysis.php" class="filter-form compact-filters" style="margin-bottom: 0;">
            <div class="filter-group">
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?php echo $end_date; ?>">
                </div>
                
                <div class="form-group">
                    <label>Doctor</label>
                    <select name="doctor_id" style="min-width: 150px;">
                        <option value="">All Doctors</option>
                        <?php foreach ($doctors_list as $doc): ?>
                            <option value="<?php echo $doc['id']; ?>" <?php if($filter_doctor == $doc['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($doc['doctor_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Includes Test</label>
                    <select name="test_id" style="min-width: 150px;">
                        <option value="">All Tests</option>
                        <?php foreach ($tests_list as $test): ?>
                            <option value="<?php echo $test['id']; ?>" <?php if($filter_test == $test['id']) echo 'selected'; ?>>
                                <?php
                                    $test_label = trim($test['main_test_name'] . ($test['sub_test_name'] ? ' - ' . $test['sub_test_name'] : ''));
                                    echo htmlspecialchars($test_label ?: 'Unnamed Test');
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Patient Name</label>
                    <input type="text" name="patient_search" value="<?php echo htmlspecialchars($filter_patient); ?>" placeholder="Search...">
                </div>
            </div>
            <div class="filter-actions">
                <a href="deep_analysis.php" class="btn-cancel btn-outline">Reset</a>
                <button type="submit" class="btn-submit">Apply Filters</button>
            </div>
        </form>
    </div>

    <div class="analysis-wrapper">

        <div class="insights-grid">
            <div class="insight-card highlight">
                <h3>Total Revenue (Gross)</h3>
                <p class="value">₹<?php echo number_format($stats['gross_revenue']); ?></p>
                <div class="meta-row">
                    <span>Before discounts</span>
                </div>
            </div>
            <div class="insight-card">
                <h3>Net Amount</h3>
                <p class="value">₹<?php echo number_format($stats['net_amount']); ?></p>
                <div class="meta-row">
                    <span>After discounts</span>
                </div>
            </div>
            <div class="insight-card">
                <h3>Professional Charges</h3>
                <p class="value">₹<?php echo number_format($stats['professional_charges']); ?></p>
                <div class="meta-row">
                    <span>Referral payouts</span>
                </div>
            </div>
            <div class="insight-card">
                <h3>Total Discounts</h3>
                <p class="value">₹<?php echo number_format($stats['discount_total']); ?></p>
                <div class="meta-row">
                    <span>Concessions granted</span>
                </div>
            </div>
            <div class="insight-card">
                <h3>Total Referrals</h3>
                <p class="value"><?php echo number_format($stats['bills']); ?></p>
                <div class="meta-row">
                    <span>Avg Ticket: ₹<?php echo number_format($stats['avg_ticket_size']); ?></span>
                </div>
            </div>
            <div class="insight-card">
                <h3>Total Tests</h3>
                <p class="value"><?php echo number_format($stats['tests']); ?></p>
                <div class="meta-row">
                    <span><?php echo number_format($stats['tests_per_patient'], 1); ?> tests/patient</span>
                </div>
            </div>
            <div class="insight-card">
                <h3>Best Day</h3>
                <p class="value" style="font-size: 1.4rem;"><?php echo $stats['best_day'] ?? '—'; ?></p>
                <div class="meta-row">
                    <span>₹<?php echo number_format($stats['best_day_value']); ?> revenue</span>
                </div>
            </div>
        </div>

        <div class="chart-grid">
            <div class="chart-card" style="grid-column: 1 / -1;">
                <h3>Daily Revenue Trend</h3>
                <div class="chart-scroll">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>

        <div class="chart-grid">
            <div class="table-card" style="grid-column: 1 / -1;">
                <div class="section-header">
                    <h3><?php echo $doctor_table_mode === 'tests' ? 'Doctor Test Performance' : 'Top Referring Doctors'; ?></h3>
                    <span>
                        <?php echo $doctor_table_mode === 'tests' ? 'Breakdown by main test for selected doctor' : 'Revenue contribution across doctors'; ?>
                    </span>
                </div>
                <?php if (!empty($top_table_rows)): ?>
                <div class="table-controls">
                    <label for="topDoctorsPageSize">Rows per page</label>
                    <select id="topDoctorsPageSize">
                        <?php foreach ([10, 20, 30, 50, 100] as $option): ?>
                            <option value="<?php echo $option; ?>" <?php echo $option === 10 ? 'selected' : ''; ?>><?php echo $option; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="table-scroll">
                    <table class="table" id="topDoctorsTable">
                        <thead>
                            <?php if ($doctor_table_mode === 'tests'): ?>
                            <tr>
                                <th>Test Name</th>
                                <th style="text-align: right;">Referrals</th>
                                <th style="text-align: right;">Gross</th>
                                <th style="text-align: right;">Net</th>
                                <th style="text-align: right;">Discounts</th>
                                <th style="text-align: right;">Professional Charges</th>
                            </tr>
                            <?php else: ?>
                            <tr>
                                <th>Doctor</th>
                                <th style="text-align: right;">Gross</th>
                                <th style="text-align: right;">Net</th>
                                <th style="text-align: right;">Discount</th>
                                <th>Main Test Mix</th>
                                <th style="text-align: right;">Professional Charges</th>
                            </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php if ($doctor_table_mode === 'tests'): ?>
                                <?php foreach ($top_table_rows as $row): ?>
                                    <tr>
                                        <td style="font-weight: 500; color: #2d3748;"><?php echo htmlspecialchars($row['test_name']); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($row['referrals']); ?></td>
                                        <td style="text-align: right; font-weight: 600;">₹<?php echo number_format($row['gross']); ?></td>
                                        <td style="text-align: right;">₹<?php echo number_format($row['net']); ?></td>
                                        <td style="text-align: right;">₹<?php echo number_format($row['discount']); ?></td>
                                        <td style="text-align: right;">₹<?php echo number_format($row['professional']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php foreach ($top_table_rows as $row): 
                                    $docId = $row['doctor_id'];
                                    $tests = $doctor_test_breakdowns[$docId] ?? [];
                                ?>
                                    <tr>
                                        <td style="font-weight: 500; color: #2d3748;"><?php echo htmlspecialchars($row['doctor_name']); ?></td>
                                        <td style="text-align: right; font-weight: 600;">₹<?php echo number_format($row['gross']); ?></td>
                                        <td style="text-align: right;">₹<?php echo number_format($row['net']); ?></td>
                                        <td style="text-align: right;">₹<?php echo number_format($row['discount']); ?></td>
                                        <td>
                                            <?php if (!empty($tests)): ?>
                                                <div class="test-mix-stack">
                                                    <?php foreach ($tests as $test): ?>
                                                        <span class="pill" style="margin: 2px 4px 2px 0;">
                                                            <strong><?php echo htmlspecialchars($test['name']); ?>:</strong>
                                                            ₹<?php echo number_format($test['revenue']); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="muted">No data</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">₹<?php echo number_format($row['professional']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="table-pagination" id="topDoctorsPagination">
                    <button type="button" data-action="first">First</button>
                    <button type="button" data-action="prev">Previous</button>
                    <span class="page-status">Page <span id="topDoctorsCurrentPage">1</span> of <span id="topDoctorsTotalPages">1</span></span>
                    <button type="button" data-action="next">Next</button>
                    <button type="button" data-action="last">Last</button>
                </div>
                <?php else: ?>
                <div class="empty-state">No data matches your filters.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-card">
            <h3>Daily Activity Log</h3>
            <?php if (!empty($daily_activity)): ?>
            <div class="table-scroll scroll-y" style="max-height: 400px;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th style="text-align: right;">Revenue</th>
                            <th style="text-align: right;">Referrals</th>
                            <th style="text-align: right;">Tests</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($daily_activity) as $day): ?>
                        <tr>
                            <td><?php echo $day['date_label']; ?></td>
                            <td style="text-align: right;">₹<?php echo number_format($day['revenue']); ?></td>
                            <td style="text-align: right;"><?php echo number_format($day['patients']); ?></td>
                            <td style="text-align: right;"><?php echo number_format($day['tests']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">No activity recorded for this period.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const chartData = <?php echo $chart_payload_json; ?>;
    
    // Revenue Chart
    const ctxRevenue = document.getElementById('revenueChart').getContext('2d');
    new Chart(ctxRevenue, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                {
                    label: 'Revenue (₹)',
                    data: chartData.revenue,
                    borderColor: '#4e73df',
                    backgroundColor: 'rgba(78, 115, 223, 0.05)',
                    borderWidth: 2,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    fill: true,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            return 'Revenue: ₹' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { borderDash: [2, 4], color: '#e2e8f0' },
                    ticks: { callback: function(value) { return '₹' + value.toLocaleString(); } }
                },
                x: {
                    grid: { display: false }
                }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            }
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const table = document.getElementById('topDoctorsTable');
        const pageSizeSelect = document.getElementById('topDoctorsPageSize');
        const pagination = document.getElementById('topDoctorsPagination');
        if (!table || !pageSizeSelect || !pagination) return;

        const rows = Array.from(table.querySelectorAll('tbody tr'));
        if (!rows.length) {
            pagination.style.display = 'none';
            pageSizeSelect.parentElement.style.display = 'none';
            return;
        }

        const currentPageLabel = document.getElementById('topDoctorsCurrentPage');
        const totalPagesLabel = document.getElementById('topDoctorsTotalPages');
        let pageSize = parseInt(pageSizeSelect.value, 10) || 10;
        let currentPage = 1;

        const totalPages = () => Math.max(1, Math.ceil(rows.length / pageSize));

        const renderRows = () => {
            const start = (currentPage - 1) * pageSize;
            const end = start + pageSize;
            rows.forEach((row, index) => {
                row.style.display = (index >= start && index < end) ? '' : 'none';
            });
        };

        const updatePaginationState = () => {
            const pages = totalPages();
            currentPage = Math.min(Math.max(currentPage, 1), pages);
            currentPageLabel.textContent = currentPage;
            totalPagesLabel.textContent = pages;
            pagination.querySelectorAll('button').forEach(btn => {
                const action = btn.dataset.action;
                let disabled = false;
                if (action === 'first' || action === 'prev') {
                    disabled = currentPage === 1;
                } else if (action === 'next' || action === 'last') {
                    disabled = currentPage === pages;
                }
                btn.disabled = disabled;
            });
        };

        const goToPage = (page) => {
            currentPage = page;
            renderRows();
            updatePaginationState();
        };

        pageSizeSelect.addEventListener('change', () => {
            pageSize = parseInt(pageSizeSelect.value, 10) || rows.length;
            currentPage = 1;
            renderRows();
            updatePaginationState();
        });

        pagination.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                const action = btn.dataset.action;
                const pages = totalPages();
                switch (action) {
                    case 'first':
                        goToPage(1);
                        break;
                    case 'prev':
                        goToPage(Math.max(1, currentPage - 1));
                        break;
                    case 'next':
                        goToPage(Math.min(pages, currentPage + 1));
                        break;
                    case 'last':
                        goToPage(pages);
                        break;
                }
            });
        });

        renderRows();
        updatePaginationState();
    });
</script>

<?php require_once '../includes/footer.php'; ?>