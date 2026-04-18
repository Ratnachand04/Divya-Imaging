<?php
$page_title = "Test Insights";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$defaultStart = date('Y-m-01');
$defaultEnd = date('Y-m-d');

$startDate = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date']) ? $_GET['start_date'] : $defaultStart;
$endDate = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date']) ? $_GET['end_date'] : $defaultEnd;

if (strtotime($startDate) === false) {
    $startDate = $defaultStart;
}

if (strtotime($endDate) === false) {
    $endDate = $defaultEnd;
}

if ($startDate > $endDate) {
    $startDate = $endDate;
}

$mainTests = [];
$mainTestsResult = $conn->query("SELECT DISTINCT COALESCE(NULLIF(main_test_name, ''), 'Uncategorized') AS main_test_name FROM tests ORDER BY main_test_name");

if ($mainTestsResult) {
    while ($row = $mainTestsResult->fetch_assoc()) {
        $mainTests[] = $row['main_test_name'];
    }
    $mainTestsResult->free();
}

if (!in_array('Uncategorized', $mainTests, true)) {
    $mainTests[] = 'Uncategorized';
}

$mainTests = array_values(array_unique($mainTests));

$selectedMainTest = isset($_GET['main_test']) ? trim($_GET['main_test']) : 'all';
if ($selectedMainTest !== 'all' && !in_array($selectedMainTest, $mainTests, true)) {
    $selectedMainTest = 'all';
}

$whereClauses = ["b.bill_status != 'Void'"];
$params = [];
$paramTypes = '';

$whereClauses[] = "DATE(b.created_at) >= ?";
$params[] = $startDate;
$paramTypes .= 's';

$whereClauses[] = "DATE(b.created_at) <= ?";
$params[] = $endDate;
$paramTypes .= 's';

if ($selectedMainTest !== 'all') {
    $whereClauses[] = "COALESCE(NULLIF(t.main_test_name, ''), 'Uncategorized') = ?";
    $params[] = $selectedMainTest;
    $paramTypes .= 's';
}

$whereSql = implode(' AND ', $whereClauses);

$discountExpression = "CASE WHEN COALESCE(b.gross_amount, 0) > 0 THEN b.discount * (t.price / b.gross_amount) ELSE 0 END";

function executePreparedQuery(mysqli $conn, string $sql, string $types, array $params): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Database query failed: ' . $conn->error);
    }

    if ($types !== '' && !empty($params)) {
        $bindValues = [];
        $bindValues[] = &$types;
        foreach ($params as $key => $value) {
            $bindValues[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Database execution failed: ' . $error);
    }

    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

$aggregateSql = "SELECT COALESCE(NULLIF(t.main_test_name, ''), 'Uncategorized') AS main_test_name,
                         COUNT(bi.id) AS test_count,
                         COALESCE(SUM(t.price), 0) AS total_revenue,
                         COALESCE(SUM($discountExpression), 0) AS total_discount
                  FROM bill_items bi
                  INNER JOIN bills b ON bi.bill_id = b.id
                  INNER JOIN tests t ON bi.test_id = t.id
                  WHERE $whereSql
                  GROUP BY main_test_name
                  ORDER BY total_revenue DESC";

$aggregateRows = executePreparedQuery($conn, $aggregateSql, $paramTypes, $params);

$subtestSql = "SELECT COALESCE(NULLIF(t.main_test_name, ''), 'Uncategorized') AS main_test_name,
                      COALESCE(NULLIF(t.sub_test_name, ''), 'Unnamed Test') AS sub_test_name,
                      COUNT(bi.id) AS test_count,
                      COALESCE(SUM(t.price), 0) AS total_revenue,
                      COALESCE(SUM($discountExpression), 0) AS total_discount
               FROM bill_items bi
               INNER JOIN bills b ON bi.bill_id = b.id
               INNER JOIN tests t ON bi.test_id = t.id
               WHERE $whereSql
               GROUP BY main_test_name, sub_test_name
               ORDER BY main_test_name ASC, total_revenue DESC, sub_test_name ASC";

$subtestRows = executePreparedQuery($conn, $subtestSql, $paramTypes, $params);

$mainTestStats = [];
$overallStats = [
    'test_count' => 0,
    'total_revenue' => 0.0,
    'total_discount' => 0.0,
    'total_net' => 0.0
];

foreach ($aggregateRows as $row) {
    $mainName = $row['main_test_name'];
    $testCount = (int) ($row['test_count'] ?? 0);
    $revenue = (float) ($row['total_revenue'] ?? 0);
    $discount = (float) ($row['total_discount'] ?? 0);
    $net = max($revenue - $discount, 0);

    $mainTestStats[$mainName] = [
        'test_count' => $testCount,
        'total_revenue' => $revenue,
        'total_discount' => $discount,
        'total_net' => $net
    ];

    $overallStats['test_count'] += $testCount;
    $overallStats['total_revenue'] += $revenue;
    $overallStats['total_discount'] += $discount;
    $overallStats['total_net'] += $net;
}

$subtestBreakdown = [];
foreach ($subtestRows as $row) {
    $mainName = $row['main_test_name'];
    $revenue = (float) ($row['total_revenue'] ?? 0);
    $discount = (float) ($row['total_discount'] ?? 0);
    $subtestBreakdown[$mainName][] = [
        'sub_test_name' => $row['sub_test_name'],
        'test_count' => (int) ($row['test_count'] ?? 0),
        'total_revenue' => $revenue,
        'total_discount' => $discount,
        'total_net' => max($revenue - $discount, 0)
    ];
}

$displayMainTests = $selectedMainTest === 'all' ? $mainTests : [$selectedMainTest];

foreach ($displayMainTests as $mainName) {
    if (!isset($mainTestStats[$mainName])) {
        $mainTestStats[$mainName] = [
            'test_count' => 0,
            'total_revenue' => 0.0,
            'total_discount' => 0.0,
            'total_net' => 0.0
        ];
    }
    if (!isset($subtestBreakdown[$mainName])) {
        $subtestBreakdown[$mainName] = [];
    }
}

$initialMainTest = $selectedMainTest === 'all' ? null : $selectedMainTest;

require_once '../includes/header.php';
?>

<div class="page-container">
    <div class="dashboard-header">

        <h1>Diagnostic Test Insights</h1>
        <p>Explore performance across tests within your selected date range. Apply filters to refine the dashboards and drill into sub-test activity.</p>
    </div>

    <form class="filter-form compact-filters" method="get">
            <div class="quick-filters">
                <button type="button" class="btn-quick-date" data-range="today">Today</button>
                <button type="button" class="btn-quick-date" data-range="yesterday">Yesterday</button>
                <button type="button" class="btn-quick-date" data-range="this_week">Last 7 Days</button>
                <button type="button" class="btn-quick-date" data-range="this_month">This Month</button>
                <button type="button" class="btn-quick-date" data-range="last_month">Last Month</button>
                <button type="button" class="btn-quick-date" data-range="this_year">This Year</button>
            </div>
            <div class="filter-group">
                <div class="form-group">
                    <label for="start_date">Start date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                </div>
                <div class="form-group">
                    <label for="end_date">End date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                </div>
                <div class="form-group">
                    <label for="main_test">Main test</label>
                    <select id="main_test" name="main_test">
                        <option value="all" <?php echo $selectedMainTest === 'all' ? 'selected' : ''; ?>>All tests</option>
                        <?php foreach ($mainTests as $mainName): ?>
                            <option value="<?php echo htmlspecialchars($mainName); ?>" <?php echo $selectedMainTest === $mainName ? 'selected' : ''; ?>><?php echo htmlspecialchars($mainName); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-submit">Apply filters</button>
                <a href="view_tests.php" class="btn-reset">Reset</a>
            </div>
    </form>
    
    <div class="filter-info-bar">
        <i class="fas fa-calendar-alt"></i>
        <span>Showing insights from <strong><?php echo date('d M Y', strtotime($startDate)); ?></strong> to <strong><?php echo date('d M Y', strtotime($endDate)); ?></strong></span>
    </div>
    
    <div class="summary-cards" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); margin-top: 1.5rem;">
        <div class="summary-card summary-card-total" style="background: var(--brand-pink-soft); border-left: 5px solid var(--brand-pink);">
             <h3 style="color: var(--brand-pink);">Total (All Selected)</h3>
             <small><?php echo htmlspecialchars($startDate); ?> to <?php echo htmlspecialchars($endDate); ?></small>
             <div class="list-reset" style="margin-top: 0.5rem;">
                <p><strong>Tests:</strong> <?php echo number_format($overallStats['test_count']); ?></p>
                <p><strong>Revenue:</strong> Rs. <?php echo number_format($overallStats['total_revenue'], 2); ?></p>
                <p><strong>Net:</strong> Rs. <?php echo number_format($overallStats['total_net'], 2); ?></p>
                <p><strong>Discount:</strong> Rs. <?php echo number_format($overallStats['total_discount'], 2); ?></p>
             </div>
        </div>

        <?php foreach ($displayMainTests as $mainName):
                $stats = $mainTestStats[$mainName];
            ?>
        <button type="button"
                        class="summary-card"
                        style="text-align: left; width: 100%; border: 1px solid var(--border-light);"
                        data-main-test-card="<?php echo htmlspecialchars($mainName); ?>"
                        data-test-count="<?php echo $stats['test_count']; ?>"
                        data-test-revenue="<?php echo number_format($stats['total_revenue'], 2, '.', ''); ?>"
                        data-test-net="<?php echo number_format($stats['total_net'], 2, '.', ''); ?>"
                        data-test-discount="<?php echo number_format($stats['total_discount'], 2, '.', ''); ?>"
                        aria-pressed="false">
                    <span class="summary-card-title"><?php echo htmlspecialchars($mainName); ?></span>
                    <span class="summary-card-metric"><?php echo number_format($stats['test_count']); ?> tests</span>
                    <span class="summary-card-metric">Rs. <?php echo number_format($stats['total_revenue'], 2); ?> revenue</span>
                    <span class="summary-card-meta">Net: Rs. <?php echo number_format($stats['total_net'], 2); ?> | Discount: Rs. <?php echo number_format($stats['total_discount'], 2); ?></span>
                </button>
            <?php endforeach; ?>
            </div>


<!-- Sub-test Details Modal (Custom Implementation) -->
<div class="custom-modal-overlay" id="subtestModal">
    <div class="custom-modal">
        <div class="custom-modal-header">
            <div>
                <h5 class="custom-modal-title" data-detail-name>Test Details</h5>
                <small style="font-size: 0.8em; opacity: 0.9;" data-detail-period><?php echo htmlspecialchars($startDate); ?> to <?php echo htmlspecialchars($endDate); ?></small>
            </div>
            <button type="button" class="custom-modal-close" id="closeModalBtn">&times;</button>
        </div>
        
        <div class="custom-modal-body">
            <!-- Summary Stats Bar -->
            <div class="custom-modal-stats">
                <div class="stat-item">
                    <small>Tests</small>
                    <div data-detail-count>0</div>
                </div>
                <div class="stat-item">
                    <small>Revenue</small>
                    <div data-detail-revenue>Rs. 0.00</div>
                </div>
                <div class="stat-item">
                    <small>Net</small>
                    <div style="color: var(--success, green);" data-detail-net>Rs. 0.00</div>
                </div>
                <div class="stat-item">
                    <small>Discount</small>
                    <div style="color: var(--danger, red);" data-detail-discount>Rs. 0.00</div>
                </div>
            </div>

            <!-- Table -->
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Sub-test</th>
                            <th style="text-align: center;">Count</th>
                            <th>Revenue</th>
                            <th>Net</th>
                            <th>Discount</th>
                        </tr>
                    </thead>
                    <tbody data-detail-table-body>
                        <!-- Content -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="custom-modal-footer">
            <button type="button" class="btn-reset" id="closeModalBtnFooter" style="background: #ddd; color: #333; border: none; padding: 0.5rem 1rem; border-radius: 4px; cursor: pointer;">Close</button>
        </div>
    </div>
</div>

</div>

<script>
window.testsInsightsData = {
    mainStats: <?php echo json_encode($mainTestStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    subtests: <?php echo json_encode($subtestBreakdown, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    initialMainTest: <?php echo json_encode($initialMainTest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
};
</script>

<?php require_once '../includes/footer.php'; ?>
