<?php
$page_title = "Existing Patients";
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

// Handle inline edit POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_patient') {
    header('Content-Type: application/json');
    $pid = (int)($_POST['patient_id'] ?? 0);
    $name = trim($_POST['patient_name'] ?? '');
    $age  = (int)($_POST['patient_age'] ?? -1);
    $sex  = trim($_POST['patient_sex'] ?? '');
    $mobile = trim($_POST['patient_mobile'] ?? '');
    $address = trim($_POST['patient_address'] ?? '');
    $city = trim($_POST['patient_city'] ?? '');

    if ($pid <= 0 || $name === '' || $age < 0 || !in_array($sex, ['Male','Female','Other'], true) || !preg_match('/^\d{10}$/', $mobile)) {
        echo json_encode(['success' => false, 'message' => 'Invalid input. Name, age, gender and 10-digit mobile are required.']);
        exit;
    }
    $stmt = $conn->prepare("UPDATE patients SET name=?, age=?, sex=?, mobile_number=?, address=?, city=? WHERE id=?");
    $stmt->bind_param('sissssi', $name, $age, $sex, $mobile, $address, $city, $pid);
    if ($stmt->execute()) {
        log_system_action($conn, 'PATIENT_UPDATED', $pid, 'Updated patient details from Existing Patients.');
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $stmt->error]);
    }
    $stmt->close();
    exit;
}

// Handle AJAX search
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    $search = trim($_GET['search'] ?? '');
    $patients = [];
    try {
        ensure_patient_registration_schema($conn);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $stmt = $conn->prepare(
                "SELECT p.id, p.uid, p.name, p.age, p.sex, p.mobile_number, p.address, p.city,
                        COUNT(DISTINCT b.id) AS visit_count
                 FROM patients p
                 LEFT JOIN bills b ON b.patient_id = p.id AND b.bill_status != 'Void'
                 WHERE p.uid LIKE ? OR p.name LIKE ? OR p.mobile_number LIKE ?
                 GROUP BY p.id ORDER BY p.name ASC LIMIT 100"
            );
            $stmt->bind_param('sss', $like, $like, $like);
        } else {
            $stmt = $conn->prepare(
                "SELECT p.id, p.uid, p.name, p.age, p.sex, p.mobile_number, p.address, p.city,
                        COUNT(DISTINCT b.id) AS visit_count
                 FROM patients p
                 LEFT JOIN bills b ON b.patient_id = p.id AND b.bill_status != 'Void'
                 GROUP BY p.id ORDER BY p.id DESC LIMIT 100"
            );
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $patients[] = $row;
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
    echo json_encode(['success' => true, 'patients' => $patients]);
    exit;
}

require_once '../includes/header.php';
?>

<div class="table-container">
    <h1>Existing Patients</h1>

    <div class="ep-search-bar">
        <input type="text" id="ep-search" placeholder="Search by ID, name, or mobile..." autocomplete="off">
        <span id="ep-search-status"></span>
    </div>

    <div class="table-responsive" id="ep-table-wrap">
        <table class="report-table" id="ep-table">
            <thead>
                <tr>
                    <th>Patient ID</th>
                    <th>Name</th>
                    <th>Age / Gender</th>
                    <th>Mobile</th>
                    <th>City</th>
                    <th>Visits</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="ep-tbody">
                <tr><td colspan="7" style="text-align:center; padding:30px; color:#888;">Type to search patients, or wait for the list to load...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- History Modal -->
<div id="historyModal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="historyModalTitle">Visit History</h2>
            <span class="close-modal" id="closeHistoryModal">&times;</span>
        </div>
        <div class="modal-body">
            <div id="historyLoader">Loading...</div>
            <div id="historyError" class="error-banner" style="display:none;"></div>
            <div id="historyContent" style="display:none;">
                <div class="history-summary">
                    <span>Total Visits: <strong id="visitCount">0</strong></span>
                    <span>Total Scans: <strong id="uniqueScanCount">0</strong></span>
                </div>
                <div id="scansIndex" style="margin:10px 0;"></div>
                <hr>
                <div id="visitsDetail"></div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit Patient</h2>
            <span class="close-modal" id="closeEditModal">&times;</span>
        </div>
        <div class="modal-body">
            <div id="editError" class="error-banner" style="display:none;"></div>
            <div id="editSuccess" class="success-banner" style="display:none;"></div>
            <form id="editForm">
                <input type="hidden" id="edit_patient_id" name="patient_id">
                <input type="hidden" name="action" value="edit_patient">
                <div class="form-row">
                    <div class="form-group" style="flex:2;">
                        <label>Name</label>
                        <input type="text" id="edit_name" name="patient_name" required>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Age</label>
                        <input type="number" id="edit_age" name="patient_age" min="0" required>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Gender</label>
                        <select id="edit_sex" name="patient_sex" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Mobile</label>
                        <input type="text" id="edit_mobile" name="patient_mobile" maxlength="10" inputmode="numeric" required>
                    </div>
                    <div class="form-group">
                        <label>City</label>
                        <input type="text" id="edit_city" name="patient_city">
                    </div>
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <textarea id="edit_address" name="patient_address" rows="2"></textarea>
                </div>
                <button type="submit" class="btn-submit">Save Changes</button>
            </form>
        </div>
    </div>
</div>



<script>
(function() {
    var searchInput = document.getElementById('ep-search');
    var tbody = document.getElementById('ep-tbody');
    var statusEl = document.getElementById('ep-search-status');
    var searchTimer = null;

    function escHtml(s) {
        return String(s || '').replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
        });
    }

    function renderRows(patients) {
        if (!patients.length) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#888;">No patients found.</td></tr>';
            return;
        }
        tbody.innerHTML = patients.map(function(p) {
            return '<tr>'
                + '<td><strong>' + escHtml(p.uid) + '</strong></td>'
                + '<td>' + escHtml(p.name) + (p.address ? '<br><small style="color:#888">' + escHtml(p.address) + '</small>' : '') + '</td>'
                + '<td>' + escHtml(p.age) + ' / ' + escHtml(p.sex) + '</td>'
                + '<td>' + escHtml(p.mobile_number || '-') + '</td>'
                + '<td>' + escHtml(p.city || '-') + '</td>'
                + '<td><span class="visit-badge">' + (parseInt(p.visit_count) || 0) + ' visit' + (p.visit_count == 1 ? '' : 's') + '</span></td>'
                + '<td>'
                + '<button class="btn-action btn-view history-btn" data-uid="' + escHtml(p.uid) + '" data-name="' + escHtml(p.name) + '">History</button> '
                + '<button class="btn-action btn-edit edit-btn" '
                + 'data-id="' + escHtml(p.id) + '" data-name="' + escHtml(p.name) + '" data-age="' + escHtml(p.age) + '" '
                + 'data-sex="' + escHtml(p.sex) + '" data-mobile="' + escHtml(p.mobile_number || '') + '" '
                + 'data-city="' + escHtml(p.city || '') + '" data-address="' + escHtml(p.address || '') + '">Edit</button>'
                + '</td>'
                + '</tr>';
        }).join('');
    }

    function doSearch(q) {
        statusEl.textContent = 'Searching...';
        fetch('existing_patients.php?ajax=1&search=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                statusEl.textContent = data.patients ? data.patients.length + ' result(s)' : '';
                if (data.success) renderRows(data.patients);
                else tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:red;">' + escHtml(data.message) + '</td></tr>';
            })
            .catch(function() { statusEl.textContent = 'Error loading patients.'; });
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() { doSearch(searchInput.value.trim()); }, 250);
    });

    // Initial load
    doSearch('');

    // --- History Modal ---
    var historyModal = document.getElementById('historyModal');
    document.getElementById('closeHistoryModal').addEventListener('click', function() { historyModal.style.display = 'none'; });
    window.addEventListener('click', function(e) { if (e.target === historyModal) historyModal.style.display = 'none'; });

    function openHistory(uid, name) {
        document.getElementById('historyModalTitle').textContent = name + ' (' + uid + ')';
        historyModal.style.display = 'flex';
        var loader  = document.getElementById('historyLoader');
        var errEl   = document.getElementById('historyError');
        var content = document.getElementById('historyContent');
        loader.style.display = 'block'; errEl.style.display = 'none'; content.style.display = 'none';

        fetch('ajax_patient_history.php?patient_uid=' + encodeURIComponent(uid))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) throw new Error(data.message || 'Failed to load history.');

                document.getElementById('visitCount').textContent = data.visit_count;
                document.getElementById('uniqueScanCount').textContent = data.total_unique_scans;

                // All scans tags
                document.getElementById('scansIndex').innerHTML = (data.all_scans || []).map(function(s) {
                    var label = s.sub_test_name ? s.main_test_name + ' \u2013 ' + s.sub_test_name : s.main_test_name;
                    return '<span class="scan-tag">' + escHtml(label) + '</span>';
                }).join('') || '<em style="color:#888">No scans recorded.</em>';

                // Per-visit cards
                document.getElementById('visitsDetail').innerHTML = (data.visits || []).map(function(v, idx) {
                    var date = v.visit_date ? new Date(v.visit_date).toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'}) : '-';
                    var statusClass = v.payment_status === 'Paid' ? 'ps-paid' : (v.payment_status === 'Due' ? 'ps-due' : 'ps-half');
                    var ref = v.referral_doctor ? v.referral_doctor : (v.referral_type || 'Self');

                    var testsHtml = (v.tests || []).map(function(t) {
                        var label = t.sub_test_name ? t.main_test_name + ' \u2013 ' + t.sub_test_name : t.main_test_name;
                        var rawStatus = t.report_status || 'Pending';
                        var rs = rawStatus === 'Completed' ? 'Uploaded' : rawStatus;
                        var rsClass = rawStatus === 'Completed' ? 'rs-done' : (rawStatus === 'In Progress' ? 'rs-wip' : 'rs-pending');
                        return '<tr><td>' + escHtml(label) + '</td>'
                             + '<td>\u20b9' + parseFloat(t.price || 0).toFixed(2) + '</td>'
                             + '<td><span class="rs-badge ' + rsClass + '">' + escHtml(rs) + '</span></td></tr>';
                    }).join('');

                    return '<div class="visit-card">'
                        + '<div class="visit-card-head">'
                        + '<div>'
                        + '<span class="visit-num">Visit #' + (idx + 1) + '</span>'
                        + '<span class="visit-date">' + escHtml(date) + '</span>'
                        + '</div>'
                        + '<span class="ps-badge ' + statusClass + '">' + escHtml(v.payment_status || '-') + '</span>'
                        + '</div>'
                        + '<div class="visit-meta">'
                        + '<span><strong>Bill #</strong>' + escHtml(String(v.bill_id)) + '</span>'
                        + '<span><strong>Ref:</strong> ' + escHtml(ref) + '</span>'
                        + '<span><strong>Mode:</strong> ' + escHtml(v.payment_mode || '-') + '</span>'
                        + '</div>'
                        + (testsHtml ? '<table class="visit-tests-table"><thead><tr><th>Test</th><th>Price</th><th>Status</th></tr></thead><tbody>' + testsHtml + '</tbody></table>' : '')
                        + '<div class="visit-amounts">'
                        + '<span>Gross: <strong>\u20b9' + parseFloat(v.gross_amount || 0).toFixed(2) + '</strong></span>'
                        + (parseFloat(v.discount) > 0 ? '<span>Discount: <strong>\u20b9' + parseFloat(v.discount).toFixed(2) + '</strong></span>' : '')
                        + '<span class="net-amt">Net: <strong>\u20b9' + parseFloat(v.net_amount || 0).toFixed(2) + '</strong></span>'
                        + (v.payment_status !== 'Paid' ? '<span class="bal-amt">Balance: <strong>\u20b9' + parseFloat(v.balance_amount || 0).toFixed(2) + '</strong></span>' : '')
                        + '</div>'
                        + '</div>';
                }).join('') || '<p style="color:#888;text-align:center;padding:1rem;">No visit history found.</p>';

                loader.style.display = 'none';
                content.style.display = 'block';
            })
            .catch(function(err) {
                loader.style.display = 'none';
                errEl.textContent = err.message;
                errEl.style.display = 'block';
            });
    }

    // --- Edit Modal ---
    var editModal = document.getElementById('editModal');
    document.getElementById('closeEditModal').addEventListener('click', function() { editModal.style.display = 'none'; });
    window.addEventListener('click', function(e) { if (e.target === editModal) editModal.style.display = 'none'; });

    function openEdit(btn) {
        document.getElementById('edit_patient_id').value = btn.dataset.id;
        document.getElementById('edit_name').value = btn.dataset.name;
        document.getElementById('edit_age').value = btn.dataset.age;
        document.getElementById('edit_sex').value = btn.dataset.sex;
        document.getElementById('edit_mobile').value = btn.dataset.mobile;
        document.getElementById('edit_city').value = btn.dataset.city;
        document.getElementById('edit_address').value = btn.dataset.address;
        document.getElementById('editError').style.display = 'none';
        document.getElementById('editSuccess').style.display = 'none';
        editModal.style.display = 'flex';
    }

    document.getElementById('editForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var errEl = document.getElementById('editError');
        var okEl = document.getElementById('editSuccess');
        errEl.style.display = 'none'; okEl.style.display = 'none';
        var fd = new FormData(this);
        fetch('existing_patients.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    okEl.textContent = 'Patient updated successfully.';
                    okEl.style.display = 'block';
                    // Refresh the table
                    doSearch(searchInput.value.trim());
                    setTimeout(function() { editModal.style.display = 'none'; }, 1200);
                } else {
                    errEl.textContent = data.message || 'Update failed.';
                    errEl.style.display = 'block';
                }
            })
            .catch(function() { errEl.textContent = 'Network error.'; errEl.style.display = 'block'; });
    });

    // Delegated click handlers for dynamically rendered rows
    tbody.addEventListener('click', function(e) {
        var btn = e.target.closest('.history-btn');
        if (btn) { openHistory(btn.dataset.uid, btn.dataset.name); return; }
        btn = e.target.closest('.edit-btn');
        if (btn) { openEdit(btn); }
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
