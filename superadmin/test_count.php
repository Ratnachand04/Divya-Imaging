<?php
$page_title = "Radiology";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/header.php';

$sa_active_page = 'test_count.php';

$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';

// Ensure column exists for radiologist assignment from writer workflow.
if (function_exists('table_scale_apply_alter_to_all_physical_tables')) {
    table_scale_apply_alter_to_all_physical_tables($conn, 'bill_items', "ADD COLUMN IF NOT EXISTS reporting_doctor VARCHAR(150) DEFAULT NULL");
} elseif (function_exists('schema_has_column')) {
    if (!schema_has_column($conn, 'bill_items', 'reporting_doctor')) {
        $conn->query("ALTER TABLE bill_items ADD COLUMN reporting_doctor VARCHAR(150) DEFAULT NULL");
    }
} else {
    $conn->query("ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS reporting_doctor VARCHAR(150) DEFAULT NULL");
}

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$referenceRadiologists = [
    'Dr. G. Mamatha MD (RD)',
    'Dr. G. Sri Kanth DMRD',
    'Dr. P. Madhu Babu MD',
    'Dr. Sahithi Chowdary',
    'Dr. SVN. Vamsi Krishna MD(RD)',
    'Dr. T. Koushik MD(RD)',
    'Dr. T. Rajeshwar Rao MD DMRD',
];

$radiologists = [];
foreach ($referenceRadiologists as $name) {
    $radiologists[$name] = [
        'name' => $name,
        'total_allotted' => 0,
        'completed_count' => 0,
        'pending_count' => 0,
        'top_pending' => []
    ];
}

// Monthly metrics layered on top of the base list.
$cardsSql = "
    SELECT
        bi.reporting_doctor AS radiologist_name,
        COUNT(*) AS total_allotted,
        SUM(CASE WHEN bi.report_status = 'Completed' THEN 1 ELSE 0 END) AS completed_count,
        SUM(CASE WHEN COALESCE(bi.report_status, 'Pending') = 'Pending' THEN 1 ELSE 0 END) AS pending_count
        FROM {$bill_items_source}
        JOIN {$bills_source} ON b.id = bi.bill_id
    WHERE bi.item_status = 0
      AND b.bill_status != 'Void'
      AND bi.reporting_doctor IS NOT NULL
      AND bi.reporting_doctor <> ''
      AND DATE(b.created_at) BETWEEN ? AND ?
    GROUP BY bi.reporting_doctor
    ORDER BY bi.reporting_doctor ASC
";

$cardsStmt = $conn->prepare($cardsSql);
$cardsStmt->bind_param('ss', $monthStart, $monthEnd);
$cardsStmt->execute();
$cardsResult = $cardsStmt->get_result();

while ($row = $cardsResult->fetch_assoc()) {
    $name = trim((string)($row['radiologist_name'] ?? ''));
    if ($name === '') {
        continue;
    }
    if (!isset($radiologists[$name])) {
        $radiologists[$name] = [
            'name' => $name,
            'total_allotted' => 0,
            'completed_count' => 0,
            'pending_count' => 0,
            'top_pending' => []
        ];
    }

    $radiologists[$name] = [
        'name' => $row['radiologist_name'],
        'total_allotted' => (int)$row['total_allotted'],
        'completed_count' => (int)$row['completed_count'],
        'pending_count' => (int)$row['pending_count'],
        'top_pending' => []
    ];
}
$cardsStmt->close();

if (!empty($radiologists)) {
    ksort($radiologists, SORT_NATURAL | SORT_FLAG_CASE);
}

$pendingSql = "
    SELECT
        bi.reporting_doctor AS radiologist_name,
        COALESCE(NULLIF(t.sub_test_name, ''), t.main_test_name) AS test_name,
        COUNT(*) AS pending_count
        FROM {$bill_items_source}
        JOIN {$bills_source} ON b.id = bi.bill_id
        JOIN {$tests_source} ON t.id = bi.test_id
    WHERE bi.item_status = 0
      AND b.bill_status != 'Void'
      AND COALESCE(bi.report_status, 'Pending') = 'Pending'
      AND bi.reporting_doctor IS NOT NULL
      AND bi.reporting_doctor <> ''
      AND DATE(b.created_at) BETWEEN ? AND ?
    GROUP BY bi.reporting_doctor, test_name
    ORDER BY bi.reporting_doctor ASC, pending_count DESC, test_name ASC
";

$pendingStmt = $conn->prepare($pendingSql);
$pendingStmt->bind_param('ss', $monthStart, $monthEnd);
$pendingStmt->execute();
$pendingResult = $pendingStmt->get_result();

while ($row = $pendingResult->fetch_assoc()) {
    $name = $row['radiologist_name'];
    if (!isset($radiologists[$name])) {
        continue;
    }
    if (count($radiologists[$name]['top_pending']) < 2) {
        $radiologists[$name]['top_pending'][] = [
            'test_name' => $row['test_name'],
            'pending_count' => (int)$row['pending_count']
        ];
    }
}
$pendingStmt->close();
?>

<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-radiology-page { display: grid; gap: 1rem; }
.sa-radiology-head h1 { margin: 0; color: #1e3a8a; font-size: 1.55rem; }
.sa-radiology-head p { margin: 0.2rem 0 0; color: #64748b; }
.sa-radiology-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 0.9rem; }
.sa-radiology-card {
    display: block;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
    text-decoration: none;
    color: #0f172a;
    transition: transform 0.18s ease, border-color 0.18s ease;
}
.sa-radiology-card:hover { transform: translateY(-2px); border-color: #93c5fd; text-decoration: none; }
.sa-radiology-card h3 { margin: 0; font-size: 1.05rem; color: #1e3a8a; }
.sa-rad-metrics { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.55rem; margin-top: 0.75rem; }
.sa-rad-metric { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.5rem 0.55rem; }
.sa-rad-metric .k { font-size: 0.72rem; color: #64748b; text-transform: uppercase; font-weight: 700; }
.sa-rad-metric .v { margin-top: 0.18rem; font-size: 0.96rem; color: #0f172a; font-weight: 700; }
.sa-pending-top { margin-top: 0.8rem; border-top: 1px dashed #cbd5e1; padding-top: 0.65rem; }
.sa-pending-top h4 { margin: 0 0 0.4rem; color: #334155; font-size: 0.85rem; }
.sa-pending-top ul { margin: 0; padding-left: 1rem; color: #475569; }
.sa-pending-top li { margin: 0.15rem 0; font-size: 0.85rem; }
.sa-radiology-empty { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 1rem; color: #475569; }
@media (max-width: 900px) { .sa-rad-metrics { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<section class="sa-radiology-page">
    <div class="sa-radiology-head">
        <h1>Radiology</h1>
        <p>Radiologist workload and report status overview.</p>
    </div>

    <?php if (count($radiologists) === 0): ?>
        <div class="sa-radiology-empty">No radiologist report activity found for selected month.</div>
    <?php else: ?>
        <div class="sa-radiology-grid">
            <?php foreach ($radiologists as $item): ?>
                <a class="sa-radiology-card" href="radiology_details.php?radiologist=<?php echo urlencode($item['name']); ?>&month=<?php echo urlencode($month); ?>">
                    <h3><?php echo htmlspecialchars($item['name']); ?></h3>

                    <div class="sa-rad-metrics">
                        <div class="sa-rad-metric">
                            <div class="k">Total Test Allotted</div>
                            <div class="v"><?php echo number_format($item['total_allotted']); ?></div>
                        </div>
                        <div class="sa-rad-metric">
                            <div class="k">Completed</div>
                            <div class="v"><?php echo number_format($item['completed_count']); ?></div>
                        </div>
                        <div class="sa-rad-metric">
                            <div class="k">Pending</div>
                            <div class="v"><?php echo number_format($item['pending_count']); ?></div>
                        </div>
                    </div>

                    <?php if ($item['pending_count'] > 0): ?>
                        <div class="sa-pending-top">
                            <h4>Top Pending Tests</h4>
                            <ul>
                                <?php if (count($item['top_pending']) === 0): ?>
                                    <li>No pending test names found.</li>
                                <?php else: ?>
                                    <?php foreach ($item['top_pending'] as $pending): ?>
                                        <li><?php echo htmlspecialchars($pending['test_name']); ?> (<?php echo number_format($pending['pending_count']); ?>)</li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>
