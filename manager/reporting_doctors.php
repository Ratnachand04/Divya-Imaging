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

ensure_reporting_doctors_schema($conn);

$doctor_form = [
    'id' => 0,
    'doctor_name' => '',
    'phone_number' => '',
    'email' => '',
    'address' => '',
    'city' => '',
    'hospital_name' => '',
    'is_active' => 1,
];
$doctor_form_mode = 'create';
$doctor_message = '';
$doctor_message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['doctor_action']) ? trim((string)$_POST['doctor_action']) : '';

    if ($action === 'save') {
        $doctor_id = isset($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : 0;
        $doctor_name = trim((string)($_POST['doctor_name'] ?? ''));
        $phone_number = trim((string)($_POST['phone_number'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $city = trim((string)($_POST['city'] ?? ''));
        $hospital_name = trim((string)($_POST['hospital_name'] ?? ''));
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($doctor_name === '' || $phone_number === '') {
            $doctor_message = 'Doctor Name and Phone Number are required.';
            $doctor_message_type = 'error';
        } else {
            if ($doctor_id > 0) {
                $stmt = $conn->prepare("UPDATE reporting_doctors SET doctor_name = ?, phone_number = ?, email = ?, address = ?, city = ?, hospital_name = ?, is_active = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('ssssssii', $doctor_name, $phone_number, $email, $address, $city, $hospital_name, $is_active, $doctor_id);
                    if ($stmt->execute()) {
                        $doctor_message = 'Reporting doctor updated successfully.';
                        $doctor_message_type = 'success';
                    } else {
                        $doctor_message = 'Unable to update reporting doctor. This name may already exist.';
                        $doctor_message_type = 'error';
                    }
                    $stmt->close();
                } else {
                    $doctor_message = 'Unable to prepare update request.';
                    $doctor_message_type = 'error';
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO reporting_doctors (doctor_name, phone_number, email, address, city, hospital_name, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('ssssssi', $doctor_name, $phone_number, $email, $address, $city, $hospital_name, $is_active);
                    if ($stmt->execute()) {
                        $doctor_message = 'Reporting doctor added successfully.';
                        $doctor_message_type = 'success';
                    } else {
                        $doctor_message = 'Unable to add reporting doctor. This name may already exist.';
                        $doctor_message_type = 'error';
                    }
                    $stmt->close();
                } else {
                    $doctor_message = 'Unable to prepare add request.';
                    $doctor_message_type = 'error';
                }
            }
        }
    } elseif ($action === 'delete') {
        $doctor_id = isset($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : 0;
        if ($doctor_id > 0) {
            $stmt = $conn->prepare("DELETE FROM reporting_doctors WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $doctor_id);
                if ($stmt->execute()) {
                    $doctor_message = 'Reporting doctor deleted successfully.';
                    $doctor_message_type = 'success';
                } else {
                    $doctor_message = 'Unable to delete reporting doctor.';
                    $doctor_message_type = 'error';
                }
                $stmt->close();
            }
        }
    }
}

if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    if ($edit_id > 0) {
        $edit_stmt = $conn->prepare("SELECT id, doctor_name, phone_number, email, address, city, hospital_name, is_active FROM reporting_doctors WHERE id = ? LIMIT 1");
        if ($edit_stmt) {
            $edit_stmt->bind_param('i', $edit_id);
            $edit_stmt->execute();
            $edit_result = $edit_stmt->get_result();
            if ($edit_result && ($edit_row = $edit_result->fetch_assoc())) {
                $doctor_form['id'] = (int)$edit_row['id'];
                $doctor_form['doctor_name'] = (string)$edit_row['doctor_name'];
                $doctor_form['phone_number'] = (string)$edit_row['phone_number'];
                $doctor_form['email'] = (string)($edit_row['email'] ?? '');
                $doctor_form['address'] = (string)($edit_row['address'] ?? '');
                $doctor_form['city'] = (string)($edit_row['city'] ?? '');
                $doctor_form['hospital_name'] = (string)($edit_row['hospital_name'] ?? '');
                $doctor_form['is_active'] = (int)$edit_row['is_active'];
                $doctor_form_mode = 'edit';
            }
            if ($edit_result instanceof mysqli_result) {
                $edit_result->free();
            }
            $edit_stmt->close();
        }
    }
}

$doctors = [];
$doctor_result = $conn->query("SELECT id, doctor_name, phone_number, email, address, city, hospital_name, is_active FROM reporting_doctors ORDER BY doctor_name ASC");
if ($doctor_result instanceof mysqli_result) {
    while ($row = $doctor_result->fetch_assoc()) {
        $doctors[] = $row;
    }
    $doctor_result->free();
}

$radiologist_list = [];
foreach ($doctors as $doctor_row) {
    if ((int)($doctor_row['is_active'] ?? 0) === 1) {
        $name = trim((string)($doctor_row['doctor_name'] ?? ''));
        if ($name !== '') {
            $radiologist_list[] = $name;
        }
    }
}

if (empty($radiologist_list)) {
    $radiologist_list = get_reporting_radiologist_list();
}

// -----------------------------------------------------------------------
// Filters
// -----------------------------------------------------------------------
$start_date   = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date'])
                ? $_GET['start_date'] : date('Y-m-d');
$end_date     = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date'])
                ? $_GET['end_date'] : date('Y-m-d');
$selected_doc = isset($_GET['doctor_name']) ? trim($_GET['doctor_name']) : 'all';

if ($selected_doc !== 'all') {
    $legacy_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM {$bill_items_source} WHERE reporting_doctor = ?");
    if ($legacy_stmt) {
        $legacy_stmt->bind_param('s', $selected_doc);
        $legacy_stmt->execute();
        $legacy_count = 0;
        $legacy_res = $legacy_stmt->get_result();
        if ($legacy_res && ($legacy_row = $legacy_res->fetch_assoc())) {
            $legacy_count = (int)($legacy_row['cnt'] ?? 0);
        }
        if ($legacy_res instanceof mysqli_result) {
            $legacy_res->free();
        }
        $legacy_stmt->close();

        if (!in_array($selected_doc, $radiologist_list, true) && $legacy_count <= 0) {
            $selected_doc = 'all';
        }
    }
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
            <p>Manage reporting doctors and view uploaded reports by doctor/date.</p>
        </div>
    </div>

    <?php if ($doctor_message !== ''): ?>
        <div class="rd-notice <?php echo $doctor_message_type === 'success' ? 'rd-notice-success' : 'rd-notice-error'; ?>">
            <?php echo htmlspecialchars($doctor_message); ?>
        </div>
    <?php endif; ?>

    <div class="table-container" style="margin-bottom:1rem;">
        <h2 style="margin-top:0;margin-bottom:.85rem;font-size:1.02rem;">Doctor Master</h2>

        <form method="POST" action="reporting_doctors.php<?php echo $selected_doc !== 'all' ? '?doctor_name=' . urlencode($selected_doc) : ''; ?>" class="filter-form compact-filters rd-filter-form rd-master-form">
            <input type="hidden" name="doctor_action" value="save">
            <input type="hidden" name="doctor_id" value="<?php echo (int)$doctor_form['id']; ?>">

            <div class="filter-group rd-col-doctor">
                <label for="doctor_name_master">Doctor Name <span style="color:#b91c1c;">*</span></label>
                <input type="text" id="doctor_name_master" name="doctor_name" value="<?php echo htmlspecialchars($doctor_form['doctor_name']); ?>" required>
            </div>

            <div class="filter-group rd-col-phone">
                <label for="phone_number_master">Phone Number <span style="color:#b91c1c;">*</span></label>
                <input type="text" id="phone_number_master" name="phone_number" value="<?php echo htmlspecialchars($doctor_form['phone_number']); ?>" required>
            </div>

            <div class="filter-group rd-col-email">
                <label for="email_master">Email</label>
                <input type="email" id="email_master" name="email" value="<?php echo htmlspecialchars($doctor_form['email']); ?>">
            </div>

            <div class="filter-group rd-col-city">
                <label for="city_master">City</label>
                <input type="text" id="city_master" name="city" value="<?php echo htmlspecialchars($doctor_form['city']); ?>">
            </div>

            <div class="filter-group rd-col-hospital">
                <label for="hospital_master">Hospital Name</label>
                <input type="text" id="hospital_master" name="hospital_name" value="<?php echo htmlspecialchars($doctor_form['hospital_name']); ?>">
            </div>

            <div class="filter-group rd-col-address" style="min-width:220px;">
                <label for="address_master">Address</label>
                <input type="text" id="address_master" name="address" value="<?php echo htmlspecialchars($doctor_form['address']); ?>">
            </div>

            <div class="filter-group rd-col-active" style="max-width:120px;">
                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;margin-top:1.5rem;">
                    <input type="checkbox" name="is_active" value="1" <?php echo (int)$doctor_form['is_active'] === 1 ? 'checked' : ''; ?>>
                    Active
                </label>
            </div>

            <div class="filter-actions rd-col-actions">
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> <?php echo $doctor_form_mode === 'edit' ? 'Update Doctor' : 'Add Doctor'; ?>
                </button>
                <?php if ($doctor_form_mode === 'edit'): ?>
                    <a href="reporting_doctors.php" class="btn-cancel" style="text-decoration:none; margin-top:.35rem; display:inline-block;">Cancel Edit</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="table-responsive" style="margin-top:.75rem;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Doctor Name</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>City</th>
                        <th>Status</th>
                        <th style="width:180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($doctors)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;color:#6b7280;">No reporting doctors found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($doctors as $doctor_row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$doctor_row['doctor_name']); ?></td>
                                <td><?php echo htmlspecialchars((string)$doctor_row['phone_number']); ?></td>
                                <td><?php echo htmlspecialchars((string)($doctor_row['email'] ?: '—')); ?></td>
                                <td><?php echo htmlspecialchars((string)($doctor_row['city'] ?: '—')); ?></td>
                                <td>
                                    <span class="status-badge <?php echo (int)$doctor_row['is_active'] === 1 ? 'status-approved' : 'status-rejected'; ?>">
                                        <?php echo (int)$doctor_row['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display:flex;gap:.45rem;flex-wrap:wrap;">
                                        <a href="reporting_doctors.php?edit_id=<?php echo (int)$doctor_row['id']; ?>" class="btn-edit" style="text-decoration:none;">
                                            <i class="fas fa-pen"></i> Edit
                                        </a>
                                        <form method="POST" action="reporting_doctors.php" onsubmit="return confirm('Delete this reporting doctor?');" style="margin:0;">
                                            <input type="hidden" name="doctor_action" value="delete">
                                            <input type="hidden" name="doctor_id" value="<?php echo (int)$doctor_row['id']; ?>">
                                            <button type="submit" class="btn-delete">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Filter Form ─────────────────────────────────────────── -->
    <form method="GET" action="reporting_doctors.php" class="filter-form compact-filters rd-filter-form rd-search-form">

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
