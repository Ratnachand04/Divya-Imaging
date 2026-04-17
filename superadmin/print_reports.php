<?php
$page_title = "Report Library";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/header.php';

$sa_active_page = 'print_reports.php';

function sa_reports_format_datetime_label(?string $value): string {
    if (empty($value)) {
        return '-';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '-';
    }
    return date('d M Y, h:i A', $ts);
}

$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';
$test_packages_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'test_packages', 'tp') : '`test_packages` tp';

$today = date('Y-m-d');
$default_start_date = date('Y-m-01');
$default_end_date = $today;

$start_date = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['start_date'])
    ? (string)$_GET['start_date']
    : $default_start_date;
$end_date = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['end_date'])
    ? (string)$_GET['end_date']
    : $default_end_date;

if ($end_date < $start_date) {
    $end_date = $start_date;
}

$search_term = trim((string)($_GET['q'] ?? ''));

$reports = [];
$error_message = '';
$patient_uid_expression = get_patient_identifier_expression($conn, 'p');

$sql = "SELECT
            bi.id AS bill_item_id,
            b.id AS bill_id,
            {$patient_uid_expression} AS patient_uid,
            COALESCE(NULLIF(p.name, ''), '-') AS patient_name,
            COALESCE(NULLIF(p.age, ''), '-') AS patient_age,
            COALESCE(NULLIF(p.sex, ''), '-') AS patient_sex,
            COALESCE(NULLIF(t.main_test_name, ''), '-') AS main_test_name,
            COALESCE(NULLIF(t.sub_test_name, ''), t.main_test_name) AS sub_test_name,
            COALESCE(NULLIF(bi.package_name, ''), tp.package_name) AS package_name,
            bi.updated_at AS uploaded_at
        FROM {$bill_items_source}
        JOIN {$bills_source} ON b.id = bi.bill_id
        JOIN {$patients_source} ON p.id = b.patient_id
        LEFT JOIN {$tests_source} ON t.id = bi.test_id
        LEFT JOIN {$test_packages_source} ON tp.id = bi.package_id
        WHERE b.bill_status != 'Void'
          AND bi.item_status = 0
          AND COALESCE(bi.report_status, 'Pending') = 'Completed'
          AND COALESCE(TRIM(bi.report_content), '') != ''
          AND DATE(b.created_at) BETWEEN ? AND ?";

$params = [$start_date, $end_date];
$types = 'ss';

if ($search_term !== '') {
    $sql .= " AND (
        {$patient_uid_expression} LIKE ?
        OR COALESCE(NULLIF(p.name, ''), '') LIKE ?
        OR CAST(b.id AS CHAR) LIKE ?
        OR COALESCE(NULLIF(t.main_test_name, ''), '') LIKE ?
        OR COALESCE(NULLIF(t.sub_test_name, ''), '') LIKE ?
    )";
    $like = '%' . $search_term . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sssss';
}

$sql .= " ORDER BY bi.updated_at DESC, bi.id DESC LIMIT 500";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $item_id = (int)$row['bill_item_id'];
        $view_url = '../templates/print_report.php?item_id=' . urlencode((string)$item_id);
        $docx_url = $view_url . '&download=1';

        $main_test = trim((string)($row['main_test_name'] ?? ''));
        $sub_test = trim((string)($row['sub_test_name'] ?? ''));
        $test_name = $main_test;
        if ($sub_test !== '' && strcasecmp($sub_test, $main_test) !== 0) {
            $test_name .= ' - ' . $sub_test;
        }

        $reports[] = [
            'bill_item_id' => $item_id,
            'bill_id' => (int)$row['bill_id'],
            'patient_uid' => (string)($row['patient_uid'] ?? ''),
            'patient_name' => (string)($row['patient_name'] ?? '-'),
            'patient_age' => (string)($row['patient_age'] ?? '-'),
            'patient_sex' => (string)($row['patient_sex'] ?? '-'),
            'test_name' => $test_name !== '' ? $test_name : '-',
            'package_name' => (string)($row['package_name'] ?? ''),
            'uploaded_at' => sa_reports_format_datetime_label((string)($row['uploaded_at'] ?? '')),
            'view_url' => $view_url,
            'docx_url' => $docx_url,
        ];
    }

    $stmt->close();
} else {
    $error_message = 'Unable to load report listing right now. Please refresh in a moment.';
}
?>

<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-reports-page { display: grid; gap: 1rem; }
.sa-reports-hero {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-reports-hero h1 { margin: 0; color: #1e3a8a; font-size: 1.45rem; }
.sa-reports-hero p { margin: 0.3rem 0 0; color: #64748b; }
.sa-filter-form {
    display: grid;
    gap: 0.7rem;
    grid-template-columns: repeat(4, minmax(160px, 1fr)) auto;
    align-items: end;
    margin-top: 0.95rem;
}
.sa-filter-field { display: grid; gap: 0.3rem; }
.sa-filter-field label { font-size: 0.8rem; color: #64748b; font-weight: 700; }
.sa-filter-field input {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 0.4rem 0.52rem;
    color: #0f172a;
    background: #fff;
}
.sa-filter-actions { display: inline-flex; gap: 0.45rem; }
.sa-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 0.44rem 0.72rem;
    font-size: 0.82rem;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    white-space: nowrap;
}
.sa-btn:focus-visible {
    outline: 2px solid rgba(29, 78, 216, 0.45);
    outline-offset: 1px;
}
.sa-btn-primary {
    background: #1d4ed8;
    color: #fff;
}
.sa-btn-primary:hover { background: #1e40af; text-decoration: none; color: #fff; }
.sa-btn-ghost {
    border-color: #cbd5e1;
    color: #1e3a8a;
    background: #fff;
}
.sa-btn-ghost:hover { background: #eff6ff; text-decoration: none; color: #1e3a8a; }
.sa-table-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-table-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.65rem;
    margin-bottom: 0.75rem;
}
.sa-table-head h2 { margin: 0; color: #1e3a8a; font-size: 1.03rem; }
.sa-meta { color: #64748b; font-size: 0.84rem; }
.sa-empty,
.sa-alert {
    margin: 0;
    color: #64748b;
}
.sa-alert {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #991b1b;
    border-radius: 10px;
    padding: 0.7rem 0.8rem;
}
.sa-table-wrap { overflow-x: auto; }
.sa-table {
    width: 100%;
    min-width: 1120px;
    border-collapse: separate;
    border-spacing: 0;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}
.sa-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    text-align: left;
    font-size: 0.78rem;
    color: #1e3a8a;
    background: #f8fafc;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border-bottom: 1px solid #e2e8f0;
    padding: 0.65rem 0.52rem;
}
.sa-table tbody td {
    border-bottom: 1px solid #e2e8f0;
    padding: 0.66rem 0.52rem;
    color: #0f172a;
    vertical-align: middle;
}
.sa-table tbody tr:nth-child(even) { background: #fcfdff; }
.sa-col-center { text-align: center; white-space: nowrap; }
.sa-col-id { color: #334155; white-space: nowrap; }
.sa-patient-meta {
    color: #475569;
    font-size: 0.82rem;
    margin-top: 0.1rem;
}
.sa-package-tag {
    display: inline-flex;
    margin-top: 0.2rem;
    font-size: 0.72rem;
    color: #0f766e;
    background: #ccfbf1;
    border: 1px solid #99f6e4;
    border-radius: 999px;
    padding: 0.12rem 0.48rem;
    font-weight: 700;
}
.sa-action-group {
    display: inline-flex;
    gap: 0.4rem;
    flex-wrap: wrap;
}
.sa-action-view {
    background: #eff6ff;
    color: #1d4ed8;
    border-color: #bfdbfe;
}
.sa-action-view:hover { background: #dbeafe; color: #1e40af; text-decoration: none; }
.sa-action-docx {
    background: #fff7ed;
    color: #9a3412;
    border-color: #fed7aa;
}
.sa-action-docx:hover { background: #ffedd5; color: #7c2d12; text-decoration: none; }
@media (max-width: 1080px) {
    .sa-filter-form {
        grid-template-columns: repeat(2, minmax(150px, 1fr));
    }
}
@media (max-width: 620px) {
    .sa-filter-form {
        grid-template-columns: 1fr;
    }
    .sa-filter-actions {
        width: 100%;
    }
    .sa-filter-actions .sa-btn {
        flex: 1;
    }
}
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<section class="sa-reports-page">
    <article class="sa-reports-hero">
        <h1>Report Library</h1>
        <p>Superadmin listing for uploaded reports with direct print and DOCX download actions.</p>

        <form method="GET" class="sa-filter-form" autocomplete="off">
            <div class="sa-filter-field">
                <label for="sa-report-start">Start Date</label>
                <input type="date" id="sa-report-start" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div class="sa-filter-field">
                <label for="sa-report-end">End Date</label>
                <input type="date" id="sa-report-end" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <div class="sa-filter-field" style="grid-column: span 2;">
                <label for="sa-report-search">Search</label>
                <input type="text" id="sa-report-search" name="q" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="UID, bill no, patient or test">
            </div>
            <div class="sa-filter-actions">
                <button type="submit" class="sa-btn sa-btn-primary">Apply</button>
                <a href="print_reports.php" class="sa-btn sa-btn-ghost">Reset</a>
            </div>
        </form>
    </article>

    <?php if ($error_message !== ''): ?>
        <p class="sa-alert"><?php echo htmlspecialchars($error_message); ?></p>
    <?php endif; ?>

    <article class="sa-table-card">
        <div class="sa-table-head">
            <h2>Uploaded Reports</h2>
            <div class="sa-meta"><?php echo number_format(count($reports)); ?> records</div>
        </div>

        <?php if (empty($reports)): ?>
            <p class="sa-empty">No uploaded reports matched the selected filters.</p>
        <?php else: ?>
            <div class="sa-table-wrap">
                <table class="sa-table">
                    <thead>
                        <tr>
                            <th class="sa-col-center">S.No</th>
                            <th class="sa-col-center">Bill</th>
                            <th>Patient</th>
                            <th>Test</th>
                            <th>Uploaded</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $index => $report): ?>
                            <tr>
                                <td class="sa-col-center"><?php echo $index + 1; ?></td>
                                <td class="sa-col-center sa-col-id">#<?php echo (int)$report['bill_id']; ?></td>
                                <td>
                                    <div><?php echo htmlspecialchars($report['patient_name']); ?></div>
                                    <div class="sa-patient-meta">
                                        UID: <?php echo htmlspecialchars($report['patient_uid']); ?> | <?php echo htmlspecialchars($report['patient_age']); ?> / <?php echo htmlspecialchars($report['patient_sex']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($report['test_name']); ?></div>
                                    <?php if ($report['package_name'] !== ''): ?>
                                        <span class="sa-package-tag">Package: <?php echo htmlspecialchars($report['package_name']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="sa-col-id"><?php echo htmlspecialchars($report['uploaded_at']); ?></td>
                                <td>
                                    <div class="sa-action-group">
                                        <a href="<?php echo htmlspecialchars($report['view_url']); ?>" target="_blank" rel="noopener" class="sa-btn sa-action-view">View Report</a>
                                        <a href="<?php echo htmlspecialchars($report['docx_url']); ?>" target="_blank" rel="noopener" class="sa-btn sa-action-docx">Print/Download DOCX</a>
                                    </div>
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
