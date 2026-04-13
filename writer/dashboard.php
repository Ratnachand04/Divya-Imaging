<?php
$page_title = "Writer Dashboard";
$required_role = "writer";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$pending_uploads = [];
$uploadTableExists = false;

$today = date('Y-m-d');
$start_date = $_GET['start_date'] ?? $today;
$end_date = $_GET['end_date'] ?? $today;
$search_term = trim($_GET['search'] ?? '');
$rows_per_page_options = [10, 20, 50, 100];
$rows_per_page_input = isset($_GET['rows_per_page']) ? (int) $_GET['rows_per_page'] : 20;
$rows_per_page = in_array($rows_per_page_input, $rows_per_page_options, true) ? $rows_per_page_input : 20;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$total_records = 0;
$total_pages = 1;
$showing_start = 0;
$showing_end = 0;

$validateDate = function ($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
};

if (!$validateDate($start_date)) {
    $start_date = $today;
}
if (!$validateDate($end_date)) {
    $end_date = $today;
}
if (strtotime($start_date) > strtotime($end_date)) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}

$bindParams = function ($stmt, $types, $params) {
    if (empty($types) || empty($params)) {
        return;
    }
    $bind_values = [];
    $bind_values[] = $types;
    foreach ($params as $key => $value) {
        $bind_values[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_values);
};

$tableCheck = $conn->query("SHOW TABLES LIKE 'writer_final_reports'");
if ($tableCheck instanceof mysqli_result) {
    if ($tableCheck->num_rows > 0) {
        $uploadTableExists = true;
    }
    $tableCheck->free();
}

// Backfill column for older databases so dashboard queries do not fail.
$conn->query("ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS reporting_doctor VARCHAR(150) DEFAULT NULL");

$selectColumns = "SELECT
    bi.id AS bill_item_id,
    b.id AS bill_id,
    p.uid AS patient_uid,
    p.name AS patient_name,
    p.age AS patient_age,
    p.sex AS patient_sex,
    bi.reporting_doctor,
    t.sub_test_name AS test_name,
    b.created_at AS bill_created_at";
$joinClause = '';

if ($uploadTableExists) {
    $selectColumns .= ",
        wfr.file_path,
        wfr.uploaded_at";
    $joinClause = " LEFT JOIN writer_final_reports wfr ON wfr.bill_item_id = bi.id";
} else {
    $selectColumns .= ",
        NULL AS file_path,
        NULL AS uploaded_at";
}

$where_clauses = [
    "b.bill_status != 'Void'",
    "COALESCE(bi.report_status, 'Pending') = 'Pending'",
    "DATE(b.created_at) BETWEEN ? AND ?"
];
$where_params = [$start_date, $end_date];
$where_types = 'ss';

if ($search_term !== '') {
    $search_like = '%' . $search_term . '%';
    $where_clauses[] = "(CAST(b.id AS CHAR) LIKE ? OR p.name LIKE ? OR t.sub_test_name LIKE ?)";
    $where_params[] = $search_like;
    $where_params[] = $search_like;
    $where_params[] = $search_like;
    $where_types .= 'sss';
}

$where_sql = implode(' AND ', $where_clauses);

$countSql = "SELECT COUNT(*) AS total
    FROM bill_items bi
    JOIN bills b ON bi.bill_id = b.id
    JOIN patients p ON b.patient_id = p.id
    JOIN tests t ON bi.test_id = t.id
    $joinClause
    WHERE $where_sql";

if ($count_stmt = $conn->prepare($countSql)) {
    if (!empty($where_params)) {
        $bindParams($count_stmt, $where_types, $where_params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    if ($count_result) {
        $total_records = (int) ($count_result->fetch_assoc()['total'] ?? 0);
    }
    $count_stmt->close();
}

if ($total_records > 0) {
    $total_pages = (int) ceil($total_records / $rows_per_page);
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $rows_per_page;
    $showing_start = $offset + 1;
    $showing_end = min($total_records, $offset + $rows_per_page);
} else {
    $total_pages = 1;
    $page = 1;
    $offset = 0;
}

$dataSql = $selectColumns . "
    FROM bill_items bi
    JOIN bills b ON bi.bill_id = b.id
    JOIN patients p ON b.patient_id = p.id
    JOIN tests t ON bi.test_id = t.id
    $joinClause
    WHERE $where_sql
    ORDER BY b.created_at DESC
    LIMIT ? OFFSET ?";

$data_params = $where_params;
$data_types = $where_types . 'ii';
$data_params[] = $rows_per_page;
$data_params[] = $offset;

if ($data_stmt = $conn->prepare($dataSql)) {
    $bindParams($data_stmt, $data_types, $data_params);
    $data_stmt->execute();
    $pendingResult = $data_stmt->get_result();
    if ($pendingResult instanceof mysqli_result) {
        while ($row = $pendingResult->fetch_assoc()) {
            $age = isset($row['patient_age']) ? trim((string)$row['patient_age']) : '';
            $sex = isset($row['patient_sex']) ? trim((string)$row['patient_sex']) : '';
            $row['age_gender'] = trim($age . ($age !== '' && $sex !== '' ? ' / ' : '') . $sex);
            $pending_uploads[] = $row;
        }
        $pendingResult->free();
    }
    $data_stmt->close();
}

$filter_query_params = [
    'start_date' => $start_date,
    'end_date' => $end_date,
    'search' => $search_term,
];
$pagination_query_params = $filter_query_params;
$pagination_query_params['rows_per_page'] = $rows_per_page;

require_once '../includes/header.php';
?>

<div class="main-content page-container writer-dashboard">
    <div class="dashboard-header">
        <div>
            <h1>Writer's Dashboard</h1>
            <p>Select an action to begin your reporting workflow.</p>
        </div>
        <div class="page-actions">
            <a class="btn-outline" href="templates.php">Templates</a>
        </div>
    </div>

    <div class="writer-actions">
        <a class="writer-action-card" href="write_reports.php">
            <span class="action-icon">WR</span>
            <h2>Write Reports</h2>
            <p>Draft new diagnostic reports or continue your saved work.</p>
        </a>
        <a class="writer-action-card" href="view_reports.php">
            <span class="action-icon">VR</span>
            <h2>View Reports</h2>
            <p>Review submitted reports and track their approval status.</p>
        </a>
    </div>

    <div class="writer-upload-panel">
        <div>
            <h2>Attach Final Reports</h2>
            <p>Upload the signed documents for each completed examination once your report is ready.</p>
        </div>

        <div class="writer-filter-bar">
            <form method="GET" action="dashboard.php" class="writer-filter-form">
                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <div class="form-group search-group">
                    <label for="universal_search">Universal Search</label>
                    <input type="text" id="universal_search" name="search" placeholder="Bill #, patient, test" value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
                <input type="hidden" name="rows_per_page" value="<?php echo (int) $rows_per_page; ?>">
                <input type="hidden" name="page" value="1">
                <div class="filter-actions">
                    <button type="submit" class="btn-primary">Filter</button>
                    <a href="dashboard.php" class="btn-secondary">Reset</a>
                </div>
            </form>
            <form method="GET" action="dashboard.php" class="rows-control-form">
                <?php foreach ($filter_query_params as $param_key => $param_value): ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($param_key); ?>" value="<?php echo htmlspecialchars($param_value); ?>">
                <?php endforeach; ?>
                <input type="hidden" name="page" value="1">
                <label for="rows_per_page" class="rows-label">Rows:</label>
                <select id="rows_per_page" name="rows_per_page" onchange="this.form.submit()">
                    <?php foreach ($rows_per_page_options as $option): ?>
                        <option value="<?php echo $option; ?>" <?php echo ($option === $rows_per_page) ? 'selected' : ''; ?>><?php echo $option; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ($total_records > 0): ?>
            <div class="upload-table-wrapper">
                <table class="upload-table">
                    <thead>
                        <tr>
                            <th>Bill #</th>
                            <th>Patient ID</th>
                            <th>Patient Name</th>
                            <th>Test Name</th>
                            <th>Age / Gender</th>
                            <th>Upload</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_uploads as $row): ?>
                            <tr>
                                <td><?php echo (int) $row['bill_id']; ?></td>
                                <td><span style="font-size:0.82rem;color:#666;"><?php echo htmlspecialchars($row['patient_uid'] ?? ''); ?></span></td>
                                <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['test_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['age_gender']); ?></td>
                                <td>
                                    <div class="action-stack">
                                        <button
                                            type="button"
                                            class="btn-upload-report"
                                            data-action="open-upload"
                                            data-bill-item="<?php echo (int)$row['bill_item_id']; ?>"
                                            data-patient-name="<?php echo htmlspecialchars($row['patient_name']); ?>"
                                            data-test-name="<?php echo htmlspecialchars($row['test_name']); ?>"
                                            data-age-gender="<?php echo htmlspecialchars($row['age_gender']); ?>"
                                            data-reporting-doctor="<?php echo htmlspecialchars((string)($row['reporting_doctor'] ?? ''), ENT_QUOTES); ?>"
                                            data-reporting-locked="<?php echo !empty($row['reporting_doctor']) ? '1' : '0'; ?>"
                                            data-status-target="upload-status-<?php echo (int)$row['bill_item_id']; ?>"
                                            data-has-upload="<?php echo !empty($row['file_path']) ? '1' : '0'; ?>">
                                            <?php echo !empty($row['file_path']) ? 'Replace Report' : 'Add Report'; ?>
                                        </button>
                                        <?php
                                            $hasUpload = !empty($row['file_path']) && !empty($row['uploaded_at']);
                                            $statusLabel = $hasUpload
                                                ? 'Uploaded on ' . date('d M Y, h:i A', strtotime($row['uploaded_at']))
                                                : 'Awaiting Upload';
                                        ?>
                                        <span class="upload-pill <?php echo $hasUpload ? 'is-success' : 'is-warning'; ?>" id="upload-status-<?php echo (int)$row['bill_item_id']; ?>">
                                            <?php echo htmlspecialchars($statusLabel); ?>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="writer-pagination-bar">
                <div class="pagination-info">
                    Showing <?php echo $showing_start; ?> - <?php echo $showing_end; ?> of <?php echo number_format($total_records); ?> pending item<?php echo ($total_records === 1) ? '' : 's'; ?>
                </div>
                <?php if ($total_pages > 1): ?>
                <div class="writer-pagination">
                    <?php
                    $window = 2;
                    $start_loop = max(1, $page - $window);
                    $end_loop = min($total_pages, $page + $window);
                    ?>
                    <?php if ($page > 1): ?>
                        <a class="page-link" href="<?php echo htmlspecialchars('dashboard.php?' . http_build_query(array_merge($pagination_query_params, ['page' => $page - 1]))); ?>">Prev</a>
                    <?php else: ?>
                        <span class="page-link disabled">Prev</span>
                    <?php endif; ?>

                    <?php if ($start_loop > 1): ?>
                        <a class="page-link" href="<?php echo htmlspecialchars('dashboard.php?' . http_build_query(array_merge($pagination_query_params, ['page' => 1]))); ?>">1</a>
                        <?php if ($start_loop > 2): ?><span class="page-ellipsis">…</span><?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start_loop; $i <= $end_loop; $i++): ?>
                        <?php $is_active = ($i === $page); ?>
                        <a class="page-link<?php echo $is_active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars('dashboard.php?' . http_build_query(array_merge($pagination_query_params, ['page' => $i]))); ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($end_loop < $total_pages): ?>
                        <?php if ($end_loop < $total_pages - 1): ?><span class="page-ellipsis">…</span><?php endif; ?>
                        <a class="page-link" href="<?php echo htmlspecialchars('dashboard.php?' . http_build_query(array_merge($pagination_query_params, ['page' => $total_pages]))); ?>"><?php echo $total_pages; ?></a>
                    <?php endif; ?>

                    <?php if ($page < $total_pages): ?>
                        <a class="page-link" href="<?php echo htmlspecialchars('dashboard.php?' . http_build_query(array_merge($pagination_query_params, ['page' => $page + 1]))); ?>">Next</a>
                    <?php else: ?>
                        <span class="page-link disabled">Next</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="upload-empty-state">
                No pending uploads found for the selected filters. Adjust the dates or search criteria to see more items.
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="upload-modal" id="uploadModal" aria-hidden="true">
    <div class="upload-modal__dialog">
        <button type="button" class="upload-modal__close" data-action="close-upload" aria-label="Close upload dialog">&times;</button>
        <h3>Attach Final Report</h3>
        <p class="upload-modal__meta">
            <span id="modalPatient"></span>
            <span id="modalTest"></span>
            <span id="modalAge"></span>
        </p>
        <form id="finalReportForm" enctype="multipart/form-data">
            <input type="hidden" name="bill_item_id" id="uploadBillItemId">
            <div class="form-group">
                <label for="reportingDoctorSelect">Reporting Doctor / Radiologist</label>
                <select name="reporting_doctor" id="reportingDoctorSelect" required>
                    <option value="">-- Select Radiologist --</option>
                    <option value="Dr. G. Mamatha MD (RD)">Dr. G. Mamatha MD (RD)</option>
                    <option value="Dr. G. Sri Kanth DMRD">Dr. G. Sri Kanth DMRD</option>
                    <option value="Dr. P. Madhu Babu MD">Dr. P. Madhu Babu MD</option>
                    <option value="Dr. Sahithi Chowdary">Dr. Sahithi Chowdary</option>
                    <option value="Dr. SVN. Vamsi Krishna MD(RD)">Dr. SVN. Vamsi Krishna MD(RD)</option>
                    <option value="Dr. T. Koushik MD(RD)">Dr. T. Koushik MD(RD)</option>
                    <option value="Dr. T. Rajeshwar Rao MD DMRD">Dr. T. Rajeshwar Rao MD DMRD</option>
                </select>
                <small id="reportingDoctorLockNotice" style="display:none; color:#64748b;">Locked for writer: reporting doctor already saved for this report. Only manager can change the reporting doctors list.</small>
            </div>
            <div class="form-group">
                <label for="reportFileInput">Report File</label>
                <input type="file" name="report_file" id="reportFileInput" accept=".pdf,.doc,.docx" required>
                <small>Accepted formats: PDF, DOC, DOCX up to 15&nbsp;MB.</small>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-action="close-upload">Cancel</button>
                <button type="submit" class="btn-primary" id="uploadSubmitBtn">Upload Report</button>
            </div>
            <p class="upload-feedback" id="uploadFeedback" role="status" aria-live="polite"></p>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('uploadModal');
    const form = document.getElementById('finalReportForm');
    const billItemInput = document.getElementById('uploadBillItemId');
    const patientLabel = document.getElementById('modalPatient');
    const testLabel = document.getElementById('modalTest');
    const ageLabel = document.getElementById('modalAge');
    const feedback = document.getElementById('uploadFeedback');
    const submitBtn = document.getElementById('uploadSubmitBtn');
    const fileInput = document.getElementById('reportFileInput');
    const reportingDoctorSelect = document.getElementById('reportingDoctorSelect');
    const reportingDoctorLockNotice = document.getElementById('reportingDoctorLockNotice');
    let activeButton = null;

    const openModal = (button) => {
        activeButton = button;
        billItemInput.value = button.dataset.billItem || '';
        patientLabel.textContent = 'Patient: ' + (button.dataset.patientName || '');
        testLabel.textContent = 'Test: ' + (button.dataset.testName || '');
        ageLabel.textContent = 'Age / Gender: ' + (button.dataset.ageGender || '');
        if (reportingDoctorSelect) {
            const savedDoctor = button.dataset.reportingDoctor || '';
            const isLocked = button.dataset.reportingLocked === '1' && savedDoctor !== '';
            reportingDoctorSelect.value = savedDoctor;
            reportingDoctorSelect.disabled = isLocked;
            reportingDoctorSelect.required = !isLocked;
            if (reportingDoctorLockNotice) {
                reportingDoctorLockNotice.style.display = isLocked ? 'block' : 'none';
            }
        }
        fileInput.value = '';
        feedback.textContent = '';
        modal.classList.add('is-visible');
        modal.setAttribute('aria-hidden', 'false');
    };

    const closeModal = () => {
        modal.classList.remove('is-visible');
        modal.setAttribute('aria-hidden', 'true');
        activeButton = null;
        form.reset();
        if (reportingDoctorSelect) {
            reportingDoctorSelect.disabled = false;
            reportingDoctorSelect.required = true;
        }
        if (reportingDoctorLockNotice) {
            reportingDoctorLockNotice.style.display = 'none';
        }
        feedback.textContent = '';
    };

    document.querySelectorAll('[data-action="open-upload"]').forEach((button) => {
        button.addEventListener('click', () => openModal(button));
    });

    document.querySelectorAll('[data-action="close-upload"]').forEach((button) => {
        button.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-visible')) {
            closeModal();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!billItemInput.value) {
            feedback.textContent = 'Missing bill reference. Please reopen the dialog.';
            return;
        }

        const formData = new FormData(form);
        submitBtn.disabled = true;
        submitBtn.textContent = 'Uploading...';
        feedback.textContent = 'Uploading report, please wait...';

        try {
            const response = await fetch('upload_final_report.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload && payload.message ? payload.message : 'Upload failed.');
            }

            if (activeButton) {
                activeButton.textContent = 'Replace Report';
                activeButton.dataset.hasUpload = '1';
                const statusTarget = activeButton.dataset.statusTarget;
                if (statusTarget) {
                    const statusEl = document.getElementById(statusTarget);
                    if (statusEl) {
                        statusEl.textContent = payload.statusLabel || 'Uploaded';
                        statusEl.classList.remove('is-warning');
                        statusEl.classList.add('is-success');
                    }
                }
            }

            feedback.textContent = payload.message || 'Report uploaded successfully.';
            setTimeout(() => {
                closeModal();
            }, 600);
        } catch (error) {
            feedback.textContent = error.message || 'Upload failed. Please try again.';
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Upload Report';
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>