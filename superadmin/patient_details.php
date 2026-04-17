<?php
$page_title = "Patient Details";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/header.php';

$sa_active_page = 'patients.php';

$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';

$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
if ($patientId <= 0) {
    header('Location: patients.php');
    exit;
}

$patientInfo = null;
$patientInfoSql = "
    SELECT
        p.id,
        COALESCE(NULLIF(p.uid, ''), '-') AS uid,
        COALESCE(NULLIF(p.name, ''), '-') AS patient_name,
        COALESCE(NULLIF(p.city, ''), '-') AS city
    FROM {$patients_source}
    WHERE p.id = ?
    LIMIT 1
";

$stmtInfo = $conn->prepare($patientInfoSql);
if ($stmtInfo) {
    $stmtInfo->bind_param('i', $patientId);
    $stmtInfo->execute();
    $patientInfo = $stmtInfo->get_result()->fetch_assoc();
    $stmtInfo->close();
}

if (!$patientInfo) {
    header('Location: patients.php');
    exit;
}

$summary = [
    'total_bills' => 0,
    'total_tests' => 0,
    'completed_reports' => 0,
    'pending_reports' => 0,
];

$summarySql = "
    SELECT
        COUNT(DISTINCT b.id) AS total_bills,
        COUNT(bi.id) AS total_tests,
        SUM(CASE WHEN COALESCE(bi.report_status, 'Pending') = 'Completed' THEN 1 ELSE 0 END) AS completed_reports,
        SUM(CASE WHEN COALESCE(bi.report_status, 'Pending') != 'Completed' THEN 1 ELSE 0 END) AS pending_reports
    FROM {$bills_source}
    LEFT JOIN {$bill_items_source}
        ON bi.bill_id = b.id
       AND bi.item_status = 0
    WHERE b.patient_id = ?
      AND b.bill_status != 'Void'
";

$stmtSummary = $conn->prepare($summarySql);
if ($stmtSummary) {
    $stmtSummary->bind_param('i', $patientId);
    $stmtSummary->execute();
    $row = $stmtSummary->get_result()->fetch_assoc();
    if ($row) {
        $summary['total_bills'] = (int)$row['total_bills'];
        $summary['total_tests'] = (int)$row['total_tests'];
        $summary['completed_reports'] = (int)$row['completed_reports'];
        $summary['pending_reports'] = (int)$row['pending_reports'];
    }
    $stmtSummary->close();
}

$billRows = [];
$billSql = "
    SELECT
        b.id AS bill_no,
        b.created_at,
        COUNT(bi.id) AS total_tests,
        SUM(CASE WHEN COALESCE(bi.report_status, 'Pending') = 'Completed' THEN 1 ELSE 0 END) AS completed_reports,
        SUM(CASE WHEN COALESCE(bi.report_status, 'Pending') != 'Completed' THEN 1 ELSE 0 END) AS pending_reports
    FROM {$bills_source}
    LEFT JOIN {$bill_items_source}
        ON bi.bill_id = b.id
       AND bi.item_status = 0
    WHERE b.patient_id = ?
      AND b.bill_status != 'Void'
    GROUP BY b.id, b.created_at
    ORDER BY b.created_at DESC, b.id DESC
";

$stmtBills = $conn->prepare($billSql);
if ($stmtBills) {
    $stmtBills->bind_param('i', $patientId);
    $stmtBills->execute();
    $resultBills = $stmtBills->get_result();
    while ($row = $resultBills->fetch_assoc()) {
        $billRows[] = [
            'bill_no' => (int)$row['bill_no'],
            'created_at' => $row['created_at'],
            'total_tests' => (int)$row['total_tests'],
            'completed_reports' => (int)$row['completed_reports'],
            'pending_reports' => (int)$row['pending_reports']
        ];
    }
    $stmtBills->close();
}

$testRows = [];
$testSql = "
    SELECT
        b.id AS bill_no,
        b.created_at,
        COALESCE(CONCAT_WS(' - ', t.main_test_name, NULLIF(t.sub_test_name, '')), 'Unknown Test') AS test_name,
        COALESCE(bi.report_status, 'Pending') AS report_status
    FROM {$bills_source}
    JOIN {$bill_items_source}
        ON bi.bill_id = b.id
       AND bi.item_status = 0
    LEFT JOIN {$tests_source}
        ON t.id = bi.test_id
    WHERE b.patient_id = ?
      AND b.bill_status != 'Void'
    ORDER BY b.created_at DESC, b.id DESC, bi.id ASC
";

$stmtTests = $conn->prepare($testSql);
if ($stmtTests) {
    $stmtTests->bind_param('i', $patientId);
    $stmtTests->execute();
    $resultTests = $stmtTests->get_result();
    while ($row = $resultTests->fetch_assoc()) {
        $testRows[] = [
            'bill_no' => (int)$row['bill_no'],
            'created_at' => $row['created_at'],
            'test_name' => $row['test_name'],
            'report_status' => $row['report_status']
        ];
    }
    $stmtTests->close();
}
?>

<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-patient-details-page { display: grid; gap: 1rem; }
.sa-patient-profile {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-patient-details-head h1 { margin: 0; color: #1e3a8a; font-size: 1.5rem; }
.sa-patient-details-head p { margin: 0.25rem 0 0; color: #64748b; }
.sa-back-link {
    display: inline-flex;
    width: fit-content;
    align-items: center;
    gap: 0.4rem;
    color: #1d4ed8;
    text-decoration: none;
    font-weight: 700;
}
.sa-back-link:hover { text-decoration: underline; }
.sa-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(170px, 1fr));
    gap: 0.7rem;
}
.sa-summary-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.8rem;
}
.sa-summary-card .k {
    display: block;
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 700;
    color: #64748b;
}
.sa-summary-card .v {
    display: block;
    margin-top: 0.25rem;
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
}
.sa-table-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-table-card h2 {
    margin: 0 0 0.7rem;
    color: #1e3a8a;
    font-size: 1.03rem;
}
.sa-table-wrap { overflow-x: auto; }
.sa-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    min-width: 820px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}
.sa-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    text-align: left;
    font-size: 0.77rem;
    color: #1e3a8a;
    background: #f8fafc;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border-bottom: 1px solid #e2e8f0;
    padding: 0.62rem 0.5rem;
}
.sa-table tbody td {
    border-bottom: 1px solid #e2e8f0;
    padding: 0.66rem 0.5rem;
    color: #0f172a;
    vertical-align: middle;
}
.sa-table tbody tr:nth-child(even) { background: #fcfdff; }
.sa-col-center { text-align: center; white-space: nowrap; }
.sa-col-date { white-space: nowrap; color: #334155; }
.sa-badge {
    display: inline-flex;
    border-radius: 999px;
    padding: 0.2rem 0.6rem;
    font-size: 0.73rem;
    font-weight: 700;
}
.sa-badge.completed { background: #dcfce7; color: #166534; }
.sa-badge.pending { background: #fee2e2; color: #991b1b; }
.sa-report-inline {
    display: inline-flex;
    gap: 0.4rem;
    align-items: center;
    flex-wrap: wrap;
}
.sa-empty { margin: 0; color: #64748b; }
@media (max-width: 960px) {
    .sa-summary-grid { grid-template-columns: repeat(2, minmax(160px, 1fr)); }
}
@media (max-width: 560px) {
    .sa-summary-grid { grid-template-columns: 1fr; }
}
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<section class="sa-patient-details-page">
    <a class="sa-back-link" href="patients.php"><i class="fas fa-arrow-left"></i> Back to Patients</a>

    <article class="sa-patient-profile">
        <div class="sa-patient-details-head">
            <h1><?php echo htmlspecialchars($patientInfo['patient_name']); ?></h1>
            <p>UID: <?php echo htmlspecialchars($patientInfo['uid']); ?> | City: <?php echo htmlspecialchars($patientInfo['city']); ?></p>
        </div>
    </article>

    <div class="sa-summary-grid">
        <div class="sa-summary-card">
            <span class="k">Total Bills</span>
            <span class="v"><?php echo number_format($summary['total_bills']); ?></span>
        </div>
        <div class="sa-summary-card">
            <span class="k">No. of Tests</span>
            <span class="v"><?php echo number_format($summary['total_tests']); ?></span>
        </div>
        <div class="sa-summary-card">
            <span class="k">Completed Reports</span>
            <span class="v"><?php echo number_format($summary['completed_reports']); ?></span>
        </div>
        <div class="sa-summary-card">
            <span class="k">Pending Reports</span>
            <span class="v"><?php echo number_format($summary['pending_reports']); ?></span>
        </div>
    </div>

    <article class="sa-table-card">
        <h2>Bill and Report Summary</h2>
        <?php if (count($billRows) === 0): ?>
            <p class="sa-empty">No bills found for this patient.</p>
        <?php else: ?>
            <div class="sa-table-wrap">
                <table class="sa-table">
                    <thead>
                        <tr>
                            <th class="sa-col-center">S.No</th>
                            <th class="sa-col-center">Bill No</th>
                            <th>Bill Date</th>
                            <th class="sa-col-center">No. of Tests</th>
                            <th>Report</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($billRows as $index => $row): ?>
                            <tr>
                                <td class="sa-col-center"><?php echo $index + 1; ?></td>
                                <td class="sa-col-center"><?php echo (int)$row['bill_no']; ?></td>
                                <td class="sa-col-date"><?php echo date('d M Y h:i A', strtotime($row['created_at'])); ?></td>
                                <td class="sa-col-center"><?php echo number_format($row['total_tests']); ?></td>
                                <td>
                                    <span class="sa-report-inline">
                                        <span class="sa-badge completed">Completed: <?php echo number_format($row['completed_reports']); ?></span>
                                        <span class="sa-badge pending">Pending: <?php echo number_format($row['pending_reports']); ?></span>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <article class="sa-table-card">
        <h2>Test-wise Report Details</h2>
        <?php if (count($testRows) === 0): ?>
            <p class="sa-empty">No test items found for this patient.</p>
        <?php else: ?>
            <div class="sa-table-wrap">
                <table class="sa-table">
                    <thead>
                        <tr>
                            <th class="sa-col-center">S.No</th>
                            <th class="sa-col-center">Bill No</th>
                            <th>Bill Date</th>
                            <th>Test</th>
                            <th>Report Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($testRows as $index => $row): ?>
                            <?php $isCompleted = strcasecmp((string)$row['report_status'], 'Completed') === 0; ?>
                            <tr>
                                <td class="sa-col-center"><?php echo $index + 1; ?></td>
                                <td class="sa-col-center"><?php echo (int)$row['bill_no']; ?></td>
                                <td class="sa-col-date"><?php echo date('d M Y h:i A', strtotime($row['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($row['test_name']); ?></td>
                                <td>
                                    <span class="sa-badge <?php echo $isCompleted ? 'completed' : 'pending'; ?>">
                                        <?php echo htmlspecialchars($row['report_status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
</section>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>
