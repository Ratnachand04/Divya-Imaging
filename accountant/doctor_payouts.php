<?php
$page_title = "Professional Charges";
$required_role = "accountant";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$feedback = '';
$feedback_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_as_paid'])) {
    $doctor_id = (int)$_POST['doctor_id'];
    $payout_amount = (float)$_POST['payout_amount'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $accountant_id = $_SESSION['user_id'];
    $proof_path = null;
    if (isset($_FILES['payout_proof']) && $_FILES['payout_proof']['error'] == 0) {
        $target_dir = "../uploads/payout_proofs/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
        $file_extension = pathinfo($_FILES["payout_proof"]["name"], PATHINFO_EXTENSION);
        $target_file = $target_dir . "payout_{$doctor_id}_" . uniqid() . '.' . $file_extension;
        if (move_uploaded_file($_FILES["payout_proof"]["tmp_name"], $target_file)) {
            $proof_path = $target_file;
        } else {
            $feedback = "Warning: A proof file was selected but failed to upload."; $feedback_type = 'error';
        }
    }
    $stmt = $conn->prepare("INSERT INTO doctor_payout_history (doctor_id, payout_amount, payout_period_start, payout_period_end, proof_path, accountant_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("idsssi", $doctor_id, $payout_amount, $start_date, $end_date, $proof_path, $accountant_id);
    if ($stmt->execute()) {
        $feedback = "Professional Charges of ₹" . number_format($payout_amount, 2) . " marked as paid successfully!";
        $feedback_type = 'success';
    } else {
        $feedback = "Database error: Could not record payment."; $feedback_type = 'error';
    }
    $stmt->close();
}

$start_date_filter = $_GET['start_date'] ?? ($_POST['start_date'] ?? date('Y-m-01'));
$end_date_filter = $_GET['end_date'] ?? ($_POST['end_date'] ?? date('Y-m-t'));
$doctor_id_filter = null;
if (isset($_GET['doctor_id']) && $_GET['doctor_id'] !== '') {
    $doctor_id_filter = (int) $_GET['doctor_id'];
} elseif (isset($_POST['doctor_id']) && $_POST['doctor_id'] !== '') {
    $doctor_id_filter = (int) $_POST['doctor_id'];
}

$doctors = $conn->query("SELECT id, doctor_name FROM referral_doctors WHERE is_active = 1 ORDER BY doctor_name");
$history_query = "SELECT h.*, d.doctor_name, u.username as accountant_name FROM doctor_payout_history h JOIN referral_doctors d ON h.doctor_id = d.id JOIN users u ON h.accountant_id = u.id WHERE h.paid_at BETWEEN ? AND ?";
$history_params = [$start_date_filter, $end_date_filter . ' 23:59:59'];
$history_types = 'ss';
if ($doctor_id_filter) {
    $history_query .= " AND h.doctor_id = ?";
    $history_params[] = $doctor_id_filter;
    $history_types .= 'i';
}
$history_query .= " ORDER BY h.paid_at DESC";
$history_stmt = $conn->prepare($history_query);
if ($history_stmt === false) { die("Fatal Error: Failed to prepare statement for payout history. " . $conn->error); }
$history_stmt->bind_param($history_types, ...$history_params);
$history_stmt->execute();
$history_result = $history_stmt->get_result();

require_once '../includes/header.php';
?>
<div class="main-content page-container">
    <div class="dashboard-header">
        <div>
            <h1>Professional Charges</h1>
            <p>This dashboard summarises doctor-wise professional charges, applies any discounts absorbed by doctors, and highlights the updated payable balance in real time.</p>
        </div>
    </div>

    <?php if ($feedback && $feedback_type === 'success'): ?><div class="success-banner"><?php echo $feedback; ?></div><?php endif; ?>
    <?php if ($feedback && $feedback_type === 'error'): ?><div class="error-banner"><?php echo $feedback; ?></div><?php endif; ?>

    <form method="GET" action="doctor_payouts.php" class="filter-form compact-filters" id="payout-filter-form" style="margin-bottom: 1.5rem;">
        <div class="filter-group">
            <div class="form-group">
                <label>Start Date</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date_filter); ?>">
            </div>
            <div class="form-group">
                <label>End Date</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date_filter); ?>">
            </div>
            <div class="form-group">
                <label>Filter by Doctor</label>
                <select name="doctor_id" style="padding: .5rem .6rem; border: 1px solid var(--border-light); border-radius: 8px; height: 38px; background: var(--bg-primary); color: var(--text-secondary); min-width: 200px;">
                    <option value="">All Doctors</option>
                    <?php $doctors->data_seek(0); // Reset doctor query pointer for second loop ?>
                    <?php while($doc = $doctors->fetch_assoc()): ?>
                    <option value="<?php echo $doc['id']; ?>" <?php if($doctor_id_filter == $doc['id']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($doc['doctor_name']); ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-submit">Filter</button>
        </div>
    </form>
    
    <div class="insight-card">
        <div class="table-header" style="display:flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-light);">
            <h3 style="margin:0;">Pending Charges for Period</h3>
            <div style="display:flex; align-items:center; gap:0.75rem; font-size:0.85rem; color:#666;">
                <span id="payouts-last-updated" class="table-meta">Last updated: --</span>
                <button type="button" id="refresh-payouts-btn" class="btn-action btn-view" style="padding:0.35rem 0.85rem;">Refresh</button>
            </div>
        </div>
        <div id="payouts-message" style="display:none; margin:0.75rem 0;"></div>
        <p style="margin:0.25rem 0 1rem; font-size:0.85rem; color:#555;">Original Payable reflects the full professional charges; Discount Applied shows the doctor-side concessions; Remaining Payable is automatically recalculated.</p>
        <div class="table-responsive">
        <table class="data-table custom-table">
            <thead>
                <tr>
                    <th>Doctor</th>
                    <th>Bills / Tests</th>
                    <th>Original Payable (₹)</th>
                    <th>Discount Applied (₹)</th>
                    <th>Remaining Payable (₹)</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="pending-payouts-body">
                <tr><td colspan="6" class="text-center">Loading payouts...</td></tr>
            </tbody>
        </table>
        </div>
    </div>

    <div class="insight-card" style="margin-top: 1.5rem;">
        <h3 style="margin-bottom: 1rem;">Recent Payment History</h3>
        <div class="table-responsive">
        <table class="data-table">
             <thead><tr><th>Doctor</th><th>Amount Paid</th><th>Period</th><th>Paid On</th><th>Paid By</th><th>Proof</th></tr></thead>
             <tbody>
                <?php if ($history_result->num_rows > 0): while($hist = $history_result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($hist['doctor_name']); ?></td>
                    <td>₹ <?php echo number_format($hist['payout_amount'], 2); ?></td>
                    <td><?php echo date('d-m-Y', strtotime($hist['payout_period_start'])) . ' to ' . date('d-m-Y', strtotime($hist['payout_period_end'])); ?></td>
                    <td><?php echo date('d-m-Y H:i', strtotime($hist['paid_at'])); ?></td>
                    <td><?php echo htmlspecialchars($hist['accountant_name']); ?></td>
                    <td>
                        <?php if(!empty($hist['proof_path'])): 
                            $proof_url = 'download_proof.php?file=' . urlencode(ltrim(str_replace('../', '', $hist['proof_path']), '/'));
                        ?>
                            <a href="<?php echo $proof_url; ?>" class="btn-action btn-view" target="_blank">View</a>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; endif; ?>
             </tbody>
        </table>
        </div>
    </div>
</div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const filterForm = document.getElementById('payout-filter-form');
        const refreshButton = document.getElementById('refresh-payouts-btn');
        const payoutsBody = document.getElementById('pending-payouts-body');
        const messageBox = document.getElementById('payouts-message');
        const lastUpdatedEl = document.getElementById('payouts-last-updated');
        const currencyFormatter = new Intl.NumberFormat('en-IN', {
            style: 'currency',
            currency: 'INR',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        const refreshIntervalMs = 30000;
        let refreshTimerId = null;
        let isFetching = false;

        const escapeContainer = document.createElement('div');
        const escapeHtml = (value) => {
            escapeContainer.textContent = value ?? '';
            return escapeContainer.innerHTML;
        };

        const formatAmount = (value) => currencyFormatter.format(Number(value ?? 0));

        const showMessage = (text, type = 'success') => {
            if (!messageBox) return;
            if (!text) {
                messageBox.style.display = 'none';
                messageBox.textContent = '';
                messageBox.className = '';
                return;
            }
            messageBox.textContent = text;
            messageBox.style.display = 'block';
            messageBox.className = type === 'error' ? 'error-banner' : 'success-banner';
        };

        const updateLastUpdated = () => {
            if (!lastUpdatedEl) return;
            const now = new Date();
            lastUpdatedEl.textContent = `Last updated: ${now.toLocaleString('en-IN', { hour12: true })}`;
        };

        const buildRow = (row, filters) => {
        const pendingAmount = Math.max(0, Number(row.payable_after_discount ?? 0));
            const isPayable = pendingAmount > 0;
            const rowElement = document.createElement('tr');
            const startValue = filters.start_date || '';
            const endValue = filters.end_date || '';
            const doctorId = Number(row.doctor_id ?? 0);
            const totalBills = Number(row.total_bills ?? 0);
            const totalTests = Number(row.total_tests ?? 0);
            const billsLabel = `${totalBills} bill${totalBills === 1 ? '' : 's'}`;
            const testsLabel = `${totalTests} test${totalTests === 1 ? '' : 's'}`;

            rowElement.innerHTML = `
                <td>${escapeHtml(row.doctor_name)}</td>
                <td>${billsLabel} / ${testsLabel}</td>
                <td>${formatAmount(row.total_payable)}</td>
                <td>${formatAmount(row.discount_applied)}</td>
                <td>${formatAmount(pendingAmount)}</td>
                <td>
                    <form action="doctor_payouts.php" method="POST" enctype="multipart/form-data" class="payout-form">
                        <input type="hidden" name="doctor_id" value="${doctorId}">
                        <input type="hidden" name="payout_amount" value="${pendingAmount.toFixed(2)}">
                        <input type="hidden" name="start_date" value="${escapeHtml(startValue)}">
                        <input type="hidden" name="end_date" value="${escapeHtml(endValue)}">
                        <div class="form-group-inline">
                            <label for="payout_proof_${doctorId}">Proof:</label>
                            <input type="file" name="payout_proof" id="payout_proof_${doctorId}" accept=".pdf,.jpg,.png" ${isPayable ? '' : 'disabled'}>
                        </div>
                        <button type="submit" name="mark_as_paid" class="btn-action btn-paid" ${isPayable ? '' : 'disabled title="No payout due"'}>${isPayable ? 'Mark as Paid' : 'Cleared'}</button>
                    </form>
                </td>
            `;

            return rowElement;
        };

        const renderRows = (rows, filters) => {
            payoutsBody.innerHTML = '';
            if (!Array.isArray(rows) || rows.length === 0) {
                const emptyRow = document.createElement('tr');
                emptyRow.innerHTML = '<td colspan="6" class="text-center">No pending charges found for the selected criteria.</td>';
                payoutsBody.appendChild(emptyRow);
                return;
            }
            rows.forEach((row) => payoutsBody.appendChild(buildRow(row, filters)));
        };

        const fetchPayouts = ({ silent = false, force = false } = {}) => {
            if (!filterForm) return;
            if (isFetching && !force) return;
            isFetching = true;
            const formData = new FormData(filterForm);
            const params = new URLSearchParams();
            params.set('action', 'getDoctorPayouts');
            const startDate = formData.get('start_date') || '';
            const endDate = formData.get('end_date') || '';
            params.set('start_date', startDate);
            params.set('end_date', endDate);
            const doctorId = formData.get('doctor_id');
            if (doctorId) params.set('doctor_id', doctorId);

            if (!silent) {
                payoutsBody.innerHTML = '<tr><td colspan="6" class="text-center">Refreshing charges...</td></tr>';
            }

            fetch(`ajax_handler.php?${params.toString()}`, { credentials: 'same-origin' })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`Request failed with status ${response.status}`);
                    }
                    return response.json();
                })
                .then((data) => {
                    const filters = data.filters || { start_date: startDate, end_date: endDate };
                    if (silent) {
                        const fileInputs = payoutsBody.querySelectorAll('input[type="file"]');
                        const hasPendingUpload = Array.from(fileInputs).some((input) => input.files && input.files.length > 0);
                        const activeElementInsideTable = document.activeElement && payoutsBody.contains(document.activeElement);
                        if (hasPendingUpload || activeElementInsideTable) {
                            return;
                        }
                    }
                    renderRows(data.payouts || [], filters);
                    showMessage('');
                    updateLastUpdated();
                })
                .catch((error) => {
                    console.error('Unable to fetch payouts:', error);
                    showMessage('Unable to refresh payouts right now. Please retry or adjust the filters.', 'error');
                })
                .finally(() => {
                    isFetching = false;
                });
        };

        const scheduleAutoRefresh = () => {
            if (refreshTimerId) {
                clearInterval(refreshTimerId);
            }
        refreshTimerId = setInterval(() => fetchPayouts({ silent: true }), refreshIntervalMs);
        };

        if (filterForm) {
            filterForm.addEventListener('submit', (event) => {
                event.preventDefault();
                fetchPayouts({ force: true });
                scheduleAutoRefresh();
            });
        }

        if (refreshButton) {
            refreshButton.addEventListener('click', () => {
                fetchPayouts({ force: true });
                scheduleAutoRefresh();
            });
        }

        window.addEventListener('focus', () => fetchPayouts({ silent: true }));

        fetchPayouts({ force: true });
        scheduleAutoRefresh();
    });
    </script>
    <?php 
$history_stmt->close();
require_once '../includes/footer.php'; 
?>