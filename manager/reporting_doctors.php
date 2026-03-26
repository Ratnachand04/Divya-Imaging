<?php
$page_title = "Reporting Doctors";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

// -----------------------------------------------------------------------
// Ensure writer_final_reports table has the reporting_doctor column
// -----------------------------------------------------------------------
$tableCheck = $conn->query("SHOW TABLES LIKE 'writer_final_reports'");
$tableExists = ($tableCheck instanceof mysqli_result && $tableCheck->num_rows > 0);

if ($tableExists) {
    $colCheck = $conn->query("SHOW COLUMNS FROM writer_final_reports LIKE 'reporting_doctor'");
    if ($colCheck instanceof mysqli_result && $colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE writer_final_reports ADD COLUMN reporting_doctor VARCHAR(150) DEFAULT NULL AFTER uploaded_by");
    }
}

// -----------------------------------------------------------------------
// Fixed radiologist list (used for dropdown and display)
// -----------------------------------------------------------------------
$radiologist_list = [
    'Dr. G. Mamatha MD (RD)',
    'Dr. G. Sri Kanth DMRD',
    'Dr. P. Madhu Babu MD',
    'Dr. Sahithi Chowdary',
    'Dr. SVN. Vamsi Krishna MD(RD)',
    'Dr. T. Koushik MD(RD)',
    'Dr. T. Rajeshwar Rao MD DMRD',
];

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

if ($tableExists) {
    // ── Source 1: uploaded files (writer_final_reports) ────────────────
    $base_sql = "SELECT
        wfr.bill_item_id,
        b.id          AS bill_id,
        p.name        AS patient_name,
        p.age         AS patient_age,
        p.sex         AS patient_sex,
        t.main_test_name,
        t.sub_test_name AS test_name,
        wfr.file_path,
        wfr.uploaded_at,
        COALESCE(wfr.reporting_doctor, 'Not Assigned') AS reporting_doctor,
        'upload'      AS source
    FROM writer_final_reports wfr
    JOIN bill_items bi ON bi.id    = wfr.bill_item_id
    JOIN bills      b  ON b.id     = bi.bill_id
    JOIN patients   p  ON p.id     = b.patient_id
    JOIN tests      t  ON t.id     = bi.test_id
    WHERE b.bill_status != 'Void'
      AND bi.item_status = 0
      AND DATE(wfr.uploaded_at) BETWEEN ? AND ?";

    $params = [$start_date, $end_date];
    $types  = 'ss';

    if ($selected_doc !== 'all') {
        $base_sql .= " AND wfr.reporting_doctor = ?";
        $params[] = $selected_doc;
        $types   .= 's';
    }

    // ── Source 2: TinyMCE-written reports (bill_items.reporting_doctor) ─
    // Only include rows that have NO corresponding writer_final_reports record
    $bi_col_check = $conn->query("SHOW COLUMNS FROM bill_items LIKE 'reporting_doctor'");
    $bi_has_col   = ($bi_col_check instanceof mysqli_result && $bi_col_check->num_rows > 0);

    if ($bi_has_col) {
        $base_sql .= "
    UNION ALL
    SELECT
        bi.id         AS bill_item_id,
        b.id          AS bill_id,
        p.name        AS patient_name,
        p.age         AS patient_age,
        p.sex         AS patient_sex,
        t.main_test_name,
        t.sub_test_name AS test_name,
        NULL          AS file_path,
        bi.updated_at AS uploaded_at,
        COALESCE(bi.reporting_doctor, 'Not Assigned') AS reporting_doctor,
        'tinymce'     AS source
    FROM bill_items bi
    JOIN bills   b  ON b.id  = bi.bill_id
    JOIN patients p ON p.id  = b.patient_id
    JOIN tests    t ON t.id  = bi.test_id
    WHERE b.bill_status   != 'Void'
      AND bi.item_status   = 0
      AND bi.report_status = 'Completed'
      AND bi.reporting_doctor IS NOT NULL
      AND bi.reporting_doctor != ''
      AND DATE(bi.updated_at) BETWEEN ? AND ?
      AND bi.id NOT IN (SELECT bill_item_id FROM writer_final_reports)";

        $params[] = $start_date;
        $params[] = $end_date;
        $types   .= 'ss';

        if ($selected_doc !== 'all') {
            $base_sql .= " AND bi.reporting_doctor = ?";
            $params[] = $selected_doc;
            $types   .= 's';
        }
    }

    $base_sql .= " ORDER BY uploaded_at DESC";

    $stmt = $conn->prepare($base_sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $age = trim((string)($row['patient_age'] ?? ''));
            $sex = trim((string)($row['patient_sex'] ?? ''));
            $row['age_gender'] = trim($age . ($age !== '' && $sex !== '' ? ' / ' : '') . $sex);

            $fp = str_replace('\\', '/', $row['file_path'] ?? '');
            $row['file_url']    = $fp !== '' ? '../../' . ltrim($fp, '/') : '';
            $row['file_exists'] = $fp !== '' && is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fp));
            $row['is_tinymce']  = ($row['source'] ?? '') === 'tinymce';

            // Full test name
            $main = trim($row['main_test_name'] ?? '');
            $sub  = trim($row['test_name'] ?? '');
            $row['display_test'] = ($main !== '' && $sub !== '' && $main !== $sub)
                ? $main . ' – ' . $sub
                : ($main ?: $sub);

            $reports[] = $row;
        }
        $res->free();
        $stmt->close();
    }
}

require_once '../includes/header.php';
?>

<div class="main-content page-container">

    <!-- ── Page Header ─────────────────────────────────────────── -->
    <div class="dashboard-header">
        <div>
            <h1><i class="fas fa-user-md" style="color:var(--primary,#4f46e5);margin-right:.45rem;"></i>Reporting Doctors</h1>
            <p>View uploaded final reports filtered by radiologist and date range.</p>
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
        <?php if (!$tableExists): ?>
            <div class="rd-notice rd-notice-info">
                No reports have been uploaded yet. Once the writer team uploads final reports, they will appear here.
            </div>
        <?php elseif (empty($reports)): ?>
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
                                <?php if ($r['file_exists']): ?>
                                    <div class="rd-report-actions">
                                        <button type="button"
                                            class="btn-rd-view"
                                            onclick="openReportViewer(<?php echo htmlspecialchars(json_encode($r['file_url'])); ?>, <?php echo htmlspecialchars(json_encode($r['patient_name'])); ?>)"
                                            title="View Report">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <a href="<?php echo htmlspecialchars($r['file_url']); ?>"
                                           download
                                           class="btn-rd-download"
                                           title="Download Report">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    </div>
                                <?php elseif ($r['is_tinymce']): ?>
                                    <span class="rd-tinymce-badge">
                                        <i class="fas fa-pen-nib"></i> Written Online
                                    </span>
                                <?php else: ?>
                                    <span class="rd-no-file">File unavailable</span>
                                <?php endif; ?>
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


<!-- ── Report Viewer Modal ─────────────────────────────────────────── -->
<div id="reportViewerOverlay" class="rd-modal-overlay" style="display:none;" onclick="closeReportViewer(event)">
    <div class="rd-modal-box" onclick="event.stopPropagation()">
        <div class="rd-modal-header">
            <span id="reportViewerTitle" class="rd-modal-title">Report</span>
            <div class="rd-modal-header-actions">
                <a id="reportViewerDownload" href="#" download class="btn-rd-download" style="font-size:.82rem;">
                    <i class="fas fa-download"></i> Download
                </a>
                <button type="button" class="rd-modal-close" onclick="closeReportViewer()" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="rd-modal-body">
            <iframe id="reportViewerFrame" src="" frameborder="0" allowfullscreen></iframe>
        </div>
    </div>
</div>



<!-- ── Page-specific script ─────────────────────────────────────────── -->
<script>
function openReportViewer(fileUrl, patientName) {
    const overlay = document.getElementById('reportViewerOverlay');
    const frame   = document.getElementById('reportViewerFrame');
    const title   = document.getElementById('reportViewerTitle');
    const dlLink  = document.getElementById('reportViewerDownload');

    title.textContent = 'Report — ' + (patientName || '');
    dlLink.href = fileUrl;

    // For PDF files open directly; for docx use Google Docs viewer as fallback
    const ext = fileUrl.split('.').pop().toLowerCase();
    if (ext === 'pdf') {
        frame.src = fileUrl;
    } else {
        // Fallback: attempt inline; doc/docx may download depending on browser
        frame.src = 'https://docs.google.com/gview?url=' + encodeURIComponent(window.location.origin + '/' + fileUrl.replace(/^\.\.\//g,'')) + '&embedded=true';
    }

    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeReportViewer(event) {
    if (event && event.target !== document.getElementById('reportViewerOverlay')) return;
    _closeViewer();
}

function _closeViewer() {
    const overlay = document.getElementById('reportViewerOverlay');
    const frame   = document.getElementById('reportViewerFrame');
    overlay.style.display = 'none';
    frame.src = '';
    document.body.style.overflow = '';
}

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') _closeViewer();
});
</script>
