<?php
$page_title = "Existing Patients";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

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
                  GROUP BY p.id
                  HAVING COUNT(DISTINCT b.id) > 0
                  ORDER BY p.name ASC LIMIT 100"
            );
            $stmt->bind_param('sss', $like, $like, $like);
        } else {
            $stmt = $conn->prepare(
                "SELECT p.id, p.uid, p.name, p.age, p.sex, p.mobile_number, p.address, p.city,
                        COUNT(DISTINCT b.id) AS visit_count
                 FROM patients p
                 LEFT JOIN bills b ON b.patient_id = p.id AND b.bill_status != 'Void'
                  GROUP BY p.id
                  HAVING COUNT(DISTINCT b.id) > 0
                  ORDER BY p.id DESC LIMIT 100"
            );
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $patients[] = $row;
        }
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
                    <span>Total Tests: <strong id="uniqueScanCount">0</strong></span>
                    <span>Net Billed: <strong id="historyNetTotal">₹0.00</strong></span>
                    <span>Paid: <strong id="historyPaidTotal">₹0.00</strong></span>
                    <span>Pending: <strong id="historyPendingTotal">₹0.00</strong></span>
                </div>
                <div id="scansIndex" style="margin:10px 0;"></div>
                <hr>
                <div id="visitsDetail"></div>
            </div>
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

    function formatInr(value) {
        var amount = parseFloat(value || 0);
        if (!isFinite(amount)) {
            amount = 0;
        }
        return '\u20b9' + amount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
                + '<a class="btn-action btn-edit" href="edit_patient.php?id=' + encodeURIComponent(p.id) + '">Edit</a>'
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
                if (data.success) {
                    renderRows(data.patients);
                } else {
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:red;">' + escHtml(data.message) + '</td></tr>';
                }
            })
            .catch(function() { statusEl.textContent = 'Error loading patients.'; });
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() { doSearch(searchInput.value.trim()); }, 250);
    });

    doSearch('');

    var historyModal = document.getElementById('historyModal');
    document.getElementById('closeHistoryModal').addEventListener('click', function() { historyModal.style.display = 'none'; });
    window.addEventListener('click', function(e) { if (e.target === historyModal) historyModal.style.display = 'none'; });

    function openHistory(uid, name) {
        document.getElementById('historyModalTitle').textContent = name + ' (' + uid + ')';
        historyModal.style.display = 'flex';
        var loader = document.getElementById('historyLoader');
        var errEl = document.getElementById('historyError');
        var content = document.getElementById('historyContent');
        loader.style.display = 'block';
        errEl.style.display = 'none';
        content.style.display = 'none';

        fetch('ajax_patient_history.php?patient_uid=' + encodeURIComponent(uid))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load history.');
                }

                document.getElementById('visitCount').textContent = data.visit_count;
                document.getElementById('uniqueScanCount').textContent = data.total_unique_scans;
                var billingSummary = data.billing_summary || {};
                document.getElementById('historyNetTotal').textContent = formatInr(billingSummary.net_amount || 0);
                document.getElementById('historyPaidTotal').textContent = formatInr(billingSummary.amount_paid || 0);
                document.getElementById('historyPendingTotal').textContent = formatInr(billingSummary.balance_amount || 0);

                document.getElementById('scansIndex').innerHTML = (data.all_scans || []).map(function(s) {
                    var label = s.sub_test_name ? s.main_test_name + ' \u2013 ' + s.sub_test_name : s.main_test_name;
                    return '<span class="scan-tag">' + escHtml(label) + '</span>';
                }).join('') || '<em style="color:#888">No scans recorded.</em>';

                document.getElementById('visitsDetail').innerHTML = (data.visits || []).map(function(v, idx) {
                    var date = v.visit_date ? new Date(v.visit_date).toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'}) : '-';
                    var statusClass = v.payment_status === 'Paid' ? 'ps-paid' : (v.payment_status === 'Due' ? 'ps-due' : 'ps-partial');
                    var ref = v.referral_doctor ? v.referral_doctor : (v.referral_type || 'Self');
                    var testsCount = parseInt(v.tests_count || (v.tests || []).length || 0, 10) || 0;
                    var completedReports = parseInt(v.completed_tests || 0, 10) || 0;
                    var pendingReports = Math.max(0, testsCount - completedReports);
                    var modeDisplay = v.payment_mode_display || v.payment_mode || '-';

                    var testsHtml = (v.tests || []).map(function(t) {
                        var label = t.sub_test_name ? t.main_test_name + ' \u2013 ' + t.sub_test_name : t.main_test_name;
                        var rs = t.report_status || 'Pending';
                        var rsClass = rs === 'Completed' ? 'rs-done' : (rs === 'In Progress' ? 'rs-wip' : 'rs-pending');
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
                        + '<span><strong>Mode:</strong> ' + escHtml(modeDisplay) + '</span>'
                        + '<span><strong>Tests:</strong> ' + testsCount + '</span>'
                        + '<span><strong>Reports:</strong> ' + completedReports + '/' + testsCount + ' done</span>'
                        + '</div>'
                        + (testsHtml ? '<table class="visit-tests-table"><thead><tr><th>Test</th><th>Price</th><th>Status</th></tr></thead><tbody>' + testsHtml + '</tbody></table>' : '')
                        + '<div class="visit-amounts">'
                        + '<span>Gross: <strong>' + formatInr(v.gross_amount || 0) + '</strong></span>'
                        + '<span>Discount: <strong>' + formatInr(v.discount || 0) + '</strong></span>'
                        + '<span class="net-amt">Net: <strong>' + formatInr(v.net_amount || 0) + '</strong></span>'
                        + '<span>Paid: <strong>' + formatInr(v.amount_paid || 0) + '</strong></span>'
                        + '<span class="bal-amt">Pending: <strong>' + formatInr(v.balance_amount || 0) + '</strong></span>'
                        + (pendingReports > 0 ? '<span class="bal-amt">Reports Pending: <strong>' + pendingReports + '</strong></span>' : '')
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

    tbody.addEventListener('click', function(e) {
        var btn = e.target.closest('.history-btn');
        if (btn) {
            openHistory(btn.dataset.uid, btn.dataset.name);
        }
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
