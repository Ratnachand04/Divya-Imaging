<?php
$page_title = "Existing Patients";
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

// Explicitly deny patient edit attempts for receptionist role.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_patient') {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Receptionist is not allowed to edit patient details.']);
    exit;
}

// Handle AJAX search
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    $search = trim($_GET['search'] ?? '');
    $patients = [];
    $uid_requires_full_length = false;
    try {
        ensure_patient_registration_schema($conn);
        if ($search !== '') {
            $search_upper = strtoupper($search);
            $is_uid_style_input = preg_match('/^DC\d*$/', $search_upper) === 1;
            $is_full_uid = preg_match('/^DC\d{8}$/', $search_upper) === 1;

            if ($is_uid_style_input && !$is_full_uid) {
                $uid_requires_full_length = true;
            } elseif ($is_full_uid) {
                $stmt = $conn->prepare(
                    "SELECT p.id, p.uid, p.name, p.age, p.sex, p.mobile_number, p.address, p.city,
                            COUNT(DISTINCT b.id) AS visit_count
                     FROM patients p
                     LEFT JOIN bills b ON b.patient_id = p.id AND b.bill_status != 'Void'
                     WHERE p.uid = ?
                     GROUP BY p.id
                     HAVING COUNT(DISTINCT b.id) > 0
                     LIMIT 1"
                );
                $stmt->bind_param('s', $search_upper);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $patients[] = $row;
                }
                $stmt->close();
            } else {
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
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $patients[] = $row;
                }
                $stmt->close();
            }
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
    echo json_encode([
        'success' => true,
        'patients' => $patients,
        'uid_requires_full_length' => $uid_requires_full_length
    ]);
    exit;
}

require_once '../includes/header.php';
?>

<style>
    #ep-table th,
    #ep-table td {
        text-align: left !important;
    }

    #ep-table .visit-badge {
        margin-left: 0;
    }
</style>

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
                    <th id="ep-actions-head" style="display:none;">Actions</th>
                </tr>
            </thead>
            <tbody id="ep-tbody">
                <tr><td colspan="6" style="text-align:center; padding:30px; color:#888;">Search patients to view details and history.</td></tr>
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
                    <span class="history-patient-ident">Patient: <strong id="historyPatientName">-</strong></span>
                    <span>Total Visits: <strong id="visitCount">0</strong></span>
                    <span>Total Items: <strong id="uniqueScanCount">0</strong></span>
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
    var actionsHead = document.getElementById('ep-actions-head');
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

    function setActionsColumnVisibility(showActions) {
        if (!actionsHead) {
            return;
        }
        actionsHead.style.display = showActions ? '' : 'none';
    }

    function buildMessageRow(message, showActionsColumn) {
        var colspan = showActionsColumn ? 7 : 6;
        return '<tr><td colspan="' + colspan + '" style="text-align:center;padding:30px;color:#888;">' + escHtml(message) + '</td></tr>';
    }

    function renderRows(patients, searchTerm) {
        var hasSearch = String(searchTerm || '').trim() !== '';
        var showActionsColumn = hasSearch;

        setActionsColumnVisibility(showActionsColumn);

        if (!hasSearch) {
            tbody.innerHTML = buildMessageRow('Search patients to view details and history.', false);
            return;
        }

        if (!patients.length) {
            tbody.innerHTML = buildMessageRow('No patients found.', showActionsColumn);
            return;
        }

        tbody.innerHTML = patients.map(function(p) {
            var actionMarkup = showActionsColumn
                ? '<td><button class="btn-action btn-view history-btn" data-uid="' + escHtml(p.uid) + '" data-name="' + escHtml(p.name) + '">History</button></td>'
                : '';

            return '<tr>'
                + '<td><strong>' + escHtml(p.uid) + '</strong></td>'
                + '<td>' + escHtml(p.name) + (p.address ? '<br><small style="color:#888">' + escHtml(p.address) + '</small>' : '') + '</td>'
                + '<td>' + escHtml(p.age) + ' / ' + escHtml(p.sex) + '</td>'
                + '<td>' + escHtml(p.mobile_number || '-') + '</td>'
                + '<td>' + escHtml(p.city || '-') + '</td>'
                + '<td><span class="visit-badge">' + (parseInt(p.visit_count) || 0) + ' visit' + (p.visit_count == 1 ? '' : 's') + '</span></td>'
                + actionMarkup
                + '</tr>';
        }).join('');
    }

    function doSearch(q) {
        var trimmedQuery = String(q || '').trim();
        var normalizedUpper = trimmedQuery.toUpperCase();
        var isUidStyleInput = /^DC\d*$/.test(normalizedUpper);
        var isFullUid = /^DC\d{8}$/.test(normalizedUpper);

        if (trimmedQuery === '') {
            statusEl.textContent = 'Search required';
            renderRows([], '');
            return;
        }

        if (isUidStyleInput && !isFullUid) {
            statusEl.textContent = 'Enter ID as DC1234XXXX';
            setActionsColumnVisibility(false);
            tbody.innerHTML = buildMessageRow('Type complete Patient ID in this format: DC1234XXXX.', false);
            return;
        }

        var requestQuery = isFullUid ? normalizedUpper : trimmedQuery;

        statusEl.textContent = 'Searching...';
        fetch('existing_patients.php?ajax=1&search=' + encodeURIComponent(requestQuery))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    statusEl.textContent = '';
                    setActionsColumnVisibility(false);
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:red;">' + escHtml(data.message) + '</td></tr>';
                    return;
                }

                if (data.uid_requires_full_length) {
                    statusEl.textContent = 'Enter ID as DC1234XXXX';
                    setActionsColumnVisibility(false);
                    tbody.innerHTML = buildMessageRow('Type complete Patient ID in this format: DC1234XXXX.', false);
                    return;
                }

                var resultCount = data.patients ? data.patients.length : 0;
                statusEl.textContent = resultCount + ' result(s)';

                renderRows(data.patients || [], requestQuery);
            })
            .catch(function() {
                statusEl.textContent = 'Error loading patients.';
                setActionsColumnVisibility(false);
                tbody.innerHTML = buildMessageRow('Unable to load patient details right now.', false);
            });
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() { doSearch(searchInput.value.trim()); }, 250);
    });

    // Privacy by default: do not auto-load patient records.
    renderRows([], '');

    // --- History Modal ---
    var historyModal = document.getElementById('historyModal');
    document.getElementById('closeHistoryModal').addEventListener('click', function() { historyModal.style.display = 'none'; });
    window.addEventListener('click', function(e) { if (e.target === historyModal) historyModal.style.display = 'none'; });

    function openHistory(uid, name) {
        document.getElementById('historyModalTitle').textContent = 'Visit History';
        historyModal.style.display = 'flex';
        var loader  = document.getElementById('historyLoader');
        var errEl   = document.getElementById('historyError');
        var content = document.getElementById('historyContent');
        loader.style.display = 'block'; errEl.style.display = 'none'; content.style.display = 'none';

        var patientLabel = document.getElementById('historyPatientName');
        if (patientLabel) {
            patientLabel.textContent = name + ' (' + uid + ')';
        }

        fetch('ajax_patient_history.php?patient_uid=' + encodeURIComponent(uid))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) throw new Error(data.message || 'Failed to load history.');

                document.getElementById('visitCount').textContent = data.visit_count;
                document.getElementById('uniqueScanCount').textContent = data.total_items || data.total_unique_scans || 0;
                var billingSummary = data.billing_summary || {};
                document.getElementById('historyNetTotal').textContent = formatInr(billingSummary.net_amount || 0);
                document.getElementById('historyPaidTotal').textContent = formatInr(billingSummary.amount_paid || 0);
                document.getElementById('historyPendingTotal').textContent = formatInr(billingSummary.balance_amount || 0);

                // All scans tags
                document.getElementById('scansIndex').innerHTML = (data.all_scans || []).map(function(s) {
                    var label = s.sub_test_name ? s.main_test_name + ' \u2013 ' + s.sub_test_name : s.main_test_name;
                    return '<span class="scan-tag">' + escHtml(label) + '</span>';
                }).join('') || '<em style="color:#888">No scans recorded.</em>';

                // Per-visit cards
                document.getElementById('visitsDetail').innerHTML = (data.visits || []).map(function(v, idx) {
                    var date = v.visit_date ? new Date(v.visit_date).toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'}) : '-';
                    var paymentStatus = String(v.payment_status || '').trim();
                    var normalizedStatus = paymentStatus.toLowerCase();
                    var statusClass = normalizedStatus === 'paid' ? 'ps-paid' : ((normalizedStatus === 'pending' || normalizedStatus === 'due') ? 'ps-due' : 'ps-partial');
                    var ref = v.referral_doctor ? v.referral_doctor : (v.referral_type || 'Self');
                    var testsCount = parseInt(v.tests_count || (v.tests || []).length || 0, 10) || 0;
                    var reportableCount = parseInt(v.reportable_tests_count || testsCount, 10) || 0;
                    var completedReports = parseInt(v.completed_tests || 0, 10) || 0;
                    var pendingReports = Math.max(0, reportableCount - completedReports);
                    var modeDisplay = v.payment_mode_display || v.payment_mode || '-';
                    var billId = parseInt(v.bill_id, 10);
                    var printBillLink = Number.isFinite(billId) && billId > 0
                        ? '<a class="btn-action btn-outline visit-print-link" href="preview_bill.php?bill_id=' + billId + '" target="_blank" rel="noopener noreferrer">Print Bill</a>'
                        : '';

                    var testsHtml = (v.tests || []).map(function(t) {
                        var itemType = t.item_type || 'test';
                        var label = itemType === 'screening'
                            ? (t.label || ((t.sub_test_name ? t.main_test_name + ' \u2013 ' + t.sub_test_name : t.main_test_name) + ' Screening'))
                            : (t.sub_test_name ? t.main_test_name + ' \u2013 ' + t.sub_test_name : t.main_test_name);

                        if (itemType === 'screening') {
                            return '<tr class="screening-row"><td>' + escHtml(label) + '</td>'
                                 + '<td>' + formatInr(t.price || 0) + '</td>'
                                 + '<td><span class="rs-badge rs-na">Service</span></td></tr>';
                        }

                        var rs = t.report_status || 'Pending';
                        var rsClass = rs === 'Completed' ? 'rs-done' : (rs === 'In Progress' ? 'rs-wip' : 'rs-pending');
                        return '<tr><td>' + escHtml(label) + '</td>'
                             + '<td>' + formatInr(t.price || 0) + '</td>'
                             + '<td><span class="rs-badge ' + rsClass + '">' + escHtml(rs) + '</span></td></tr>';
                    }).join('');

                    return '<div class="visit-card">'
                        + '<div class="visit-card-head">'
                        + '<div>'
                        + '<span class="visit-num">Visit #' + (idx + 1) + '</span>'
                        + '<span class="visit-date">' + escHtml(date) + '</span>'
                        + '</div>'
                        + '<div class="visit-card-head-right">'
                        + '<span class="ps-badge ' + statusClass + '">' + escHtml(v.payment_status || '-') + '</span>'
                        + printBillLink
                        + '</div>'
                        + '</div>'
                        + '<div class="visit-meta">'
                        + '<span><strong>Bill #</strong>' + escHtml(String(v.bill_id)) + '</span>'
                        + '<span><strong>Ref:</strong> ' + escHtml(ref) + '</span>'
                        + '<span><strong>Mode:</strong> ' + escHtml(modeDisplay) + '</span>'
                        + '<span><strong>Tests/Services:</strong> ' + testsCount + '</span>'
                        + '<span><strong>Reports:</strong> ' + completedReports + '/' + reportableCount + ' done</span>'
                        + '</div>'
                        + (testsHtml ? '<table class="visit-tests-table"><thead><tr><th>Item</th><th>Price</th><th>Status</th></tr></thead><tbody>' + testsHtml + '</tbody></table>' : '')
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

    // Delegated click handlers for dynamically rendered rows
    tbody.addEventListener('click', function(e) {
        var btn = e.target.closest('.history-btn');
        if (btn) { openHistory(btn.dataset.uid, btn.dataset.name); return; }
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
