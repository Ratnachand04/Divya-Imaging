<?php
$page_title = "Reporting Doctors";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

// Ensure reporting doctor exists on bill_items for older database snapshots.
if (function_exists('table_scale_apply_alter_to_all_physical_tables')) {
    table_scale_apply_alter_to_all_physical_tables($conn, 'bill_items', "ADD COLUMN IF NOT EXISTS reporting_doctor VARCHAR(150) DEFAULT NULL");
} else {
    $conn->query("ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS reporting_doctor VARCHAR(150) DEFAULT NULL");
}

$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';

// -----------------------------------------------------------------------
// Shared radiologist list (used for dropdown and display)
// -----------------------------------------------------------------------
$radiologist_list = get_reporting_radiologist_list();

// -----------------------------------------------------------------------
// Filters
// -----------------------------------------------------------------------
$start_date   = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date'])
                ? $_GET['start_date'] : date('Y-m-d');
$end_date     = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date'])
                ? $_GET['end_date'] : date('Y-m-d');
$selected_doc = isset($_GET['doctor_name']) ? trim($_GET['doctor_name']) : 'all';

// Safety: if submitted doctor is not in list and not 'all', reset
if ($selected_doc !== 'all' && !in_array($selected_doc, $radiologist_list, true)) {
    $selected_doc = 'all';
}

// -----------------------------------------------------------------------
// Build Query
// -----------------------------------------------------------------------
$reports = [];
$base_sql = "SELECT
    bi.id          AS bill_item_id,
    b.id           AS bill_id,
    p.name         AS patient_name,
    p.age          AS patient_age,
    p.sex          AS patient_sex,
    t.main_test_name,
    t.sub_test_name AS test_name,
    bi.updated_at  AS uploaded_at,
    COALESCE(bi.reporting_doctor, 'Not Assigned') AS reporting_doctor
FROM {$bill_items_source}
JOIN {$bills_source} ON b.id = bi.bill_id
JOIN {$patients_source} ON p.id = b.patient_id
JOIN {$tests_source} ON t.id = bi.test_id
WHERE b.bill_status != 'Void'
  AND bi.item_status = 0
  AND COALESCE(bi.report_status, 'Pending') = 'Completed'
  AND COALESCE(TRIM(bi.report_content), '') != ''
  AND bi.reporting_doctor IS NOT NULL
  AND bi.reporting_doctor != ''
  AND DATE(bi.updated_at) BETWEEN ? AND ?";

$params = [$start_date, $end_date];
$types  = 'ss';

if ($selected_doc !== 'all') {
    $base_sql .= " AND bi.reporting_doctor = ?";
    $params[] = $selected_doc;
    $types   .= 's';
}

$base_sql .= " ORDER BY bi.updated_at DESC";

$stmt = $conn->prepare($base_sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $age = trim((string)($row['patient_age'] ?? ''));
        $sex = trim((string)($row['patient_sex'] ?? ''));
        $row['age_gender'] = trim($age . ($age !== '' && $sex !== '' ? ' / ' : '') . $sex);

        $main = trim($row['main_test_name'] ?? '');
        $sub  = trim($row['test_name'] ?? '');
        $row['display_test'] = ($main !== '' && $sub !== '' && $main !== $sub)
            ? $main . ' – ' . $sub
            : ($main ?: $sub);

        $row['view_url'] = '../templates/print_report.php?item_id=' . urlencode((string)$row['bill_item_id']);
        $reports[] = $row;
    }
    $res->free();
    $stmt->close();
}

require_once '../includes/header.php';
?>

<div class="main-content page-container">

    <!-- ── Page Header ─────────────────────────────────────────── -->
    <div class="dashboard-header">
        <div>
            <h1><i class="fas fa-user-md" style="color:var(--primary,#4f46e5);margin-right:.45rem;"></i>Reporting Doctors</h1>
            <p>View uploaded reports filtered by radiologist and date range.</p>
        </div>
    </div>

    <!-- ── Filter Form ─────────────────────────────────────────── -->
    <form method="GET" action="reporting_doctors.php" class="filter-form compact-filters rd-filter-form">

        <div class="filter-group">
            <label for="start_date">Start Date</label>
            <input type="date" id="start_date" name="start_date"
                   value="<?php echo htmlspecialchars($start_date); ?>" style="color:#000 !important;">
        </div>

        <div class="filter-group">
            <label for="end_date">End Date</label>
            <input type="date" id="end_date" name="end_date"
                   value="<?php echo htmlspecialchars($end_date); ?>" style="color:#000 !important;">
        </div>

        <div class="filter-group">
            <label for="doctor_name">Radiologist Name</label>
            <select id="doctor_name" name="doctor_name">
                <option value="all" <?php echo $selected_doc === 'all' ? 'selected' : ''; ?>>All Doctors</option>
                <?php foreach ($radiologist_list as $doc): ?>
                    <option value="<?php echo htmlspecialchars($doc); ?>"
                        <?php echo $selected_doc === $doc ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($doc); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn-submit">
                <i class="fas fa-search"></i> Submit
            </button>
            <a href="reporting_doctors.php" class="btn-cancel" style="text-decoration:none; margin-top:.35rem; display:inline-block;">
                Reset
            </a>
        </div>

    </form>

    <!-- ── Results Table ───────────────────────────────────────── -->
    <div class="table-container">
        <?php if (empty($reports)): ?>
            <div class="rd-notice rd-notice-info">
                No reports found for the selected filters. Try adjusting the date range or doctor selection.
            </div>
        <?php else: ?>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem;">
                <h2 style="margin:0;font-size:1.05rem;">
                    <?php echo $selected_doc === 'all' ? 'All Radiologists' : htmlspecialchars($selected_doc); ?>
                    <span style="font-weight:400;font-size:.9rem;color:var(--text-muted);">
                        — <?php echo count($reports); ?> report<?php echo count($reports) !== 1 ? 's' : ''; ?>
                    </span>
                </h2>
            </div>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <?php if ($selected_doc === 'all'): ?>
                            <th>Doctor Name</th>
                            <?php endif; ?>
                            <th>Patient</th>
                            <th>Bill #</th>
                            <th>Age / Sex</th>
                            <th>Test Name</th>
                            <th>Report</th>
                            <th>Uploaded At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $r): ?>
                        <tr>
                            <?php if ($selected_doc === 'all'): ?>
                            <td>
                                <span class="rd-doc-badge"><?php echo htmlspecialchars($r['reporting_doctor']); ?></span>
                            </td>
                            <?php endif; ?>
                            <td><?php echo htmlspecialchars($r['patient_name']); ?></td>
                            <td><span class="bill-id-badge">#<?php echo intval($r['bill_id']); ?></span></td>
                            <td><?php echo htmlspecialchars($r['age_gender'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($r['display_test']); ?></td>
                            <td>
                                <a href="<?php echo htmlspecialchars($r['view_url']); ?>"
                                   target="_blank"
                                   rel="noopener"
                                   class="btn-rd-view"
                                   title="View Uploaded Report">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </td>
                            <td>
                                <?php
                                    if (!empty($r['uploaded_at'])) {
                                        $ts = strtotime($r['uploaded_at']);
                                        echo $ts ? date('d M Y, h:i A', $ts) : htmlspecialchars($r['uploaded_at']);
                                    } else {
                                        echo '—';
                                    }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div><!-- /page-container -->
