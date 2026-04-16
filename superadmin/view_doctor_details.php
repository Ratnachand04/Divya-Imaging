<?php
$page_title = "Doctor Details";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

$sa_active_page = 'view_doctors.php';
$doctorId = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;

$defaultStartDate = date('Y-m-01');
$defaultEndDate = date('Y-m-d');

$doctorStartDate = $_GET['start_date'] ?? $defaultStartDate;
$doctorEndDate = $_GET['end_date'] ?? $defaultEndDate;

$doctorStartObj = DateTime::createFromFormat('Y-m-d', $doctorStartDate);
if (!$doctorStartObj || $doctorStartObj->format('Y-m-d') !== $doctorStartDate) {
    $doctorStartObj = new DateTime($defaultStartDate);
}

$doctorEndObj = DateTime::createFromFormat('Y-m-d', $doctorEndDate);
if (!$doctorEndObj || $doctorEndObj->format('Y-m-d') !== $doctorEndDate) {
    $doctorEndObj = new DateTime($defaultEndDate);
}

if ($doctorEndObj < $doctorStartObj) {
    $doctorEndObj = clone $doctorStartObj;
}

$doctorStartDate = $doctorStartObj->format('Y-m-d');
$doctorEndDate = $doctorEndObj->format('Y-m-d');

$doctorStartSql = $conn->real_escape_string($doctorStartDate . ' 00:00:00');
$doctorEndSql = $conn->real_escape_string($doctorEndDate . ' 23:59:59');

if ($doctorId <= 0) {
    echo '<div class="page-container"><div class="error-banner">Invalid doctor selected.</div></div>';
    require_once '../includes/footer.php';
    exit;
}

$doctorStmt = $conn->prepare("SELECT id, doctor_name, hospital_name, phone_number, email, city, address, is_active, created_at FROM referral_doctors WHERE id = ?");
$doctorStmt->bind_param('i', $doctorId);
$doctorStmt->execute();
$doctor = $doctorStmt->get_result()->fetch_assoc();
$doctorStmt->close();

if (!$doctor) {
    echo '<div class="page-container"><div class="error-banner">Doctor not found.</div></div>';
    require_once '../includes/footer.php';
    exit;
}

$summaryStmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT b.id) AS referred_patients,
        COUNT(bi.id) AS test_count,
        COALESCE(SUM(CASE WHEN b.bill_status != 'Void' THEN b.net_amount ELSE 0 END), 0) AS revenue,
        COALESCE(SUM(CASE WHEN b.bill_status != 'Void' THEN COALESCE(dtp.payable_amount, t.default_payable_amount, 0) ELSE 0 END), 0) AS professional_charges
    FROM bills b
    LEFT JOIN bill_items bi ON bi.bill_id = b.id AND bi.item_status = 0
    LEFT JOIN tests t ON t.id = bi.test_id
    LEFT JOIN doctor_test_payables dtp ON dtp.doctor_id = b.referral_doctor_id AND dtp.test_id = bi.test_id
    WHERE b.referral_type = 'Doctor'
      AND b.bill_status != 'Void'
      AND b.referral_doctor_id = ?
            AND b.created_at BETWEEN '{$doctorStartSql}' AND '{$doctorEndSql}'
");
$summaryStmt->bind_param('i', $doctorId);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

$testBreakdownStmt = $conn->prepare("
    SELECT
        mt.main_test_name AS test_name,
        COALESCE(doc_data.revenue, 0) AS revenue,
        COALESCE(doc_data.patient_count, 0) AS patient_count,
        COALESCE(doc_data.professional_charges, 0) AS professional_charges,
        COALESCE(doc_data.referred_count, 0) AS referred_count
    FROM (
        SELECT DISTINCT main_test_name
        FROM tests
        WHERE main_test_name IN ('CT', 'ECG', 'ECHO', 'LAB', 'MAMMOGRAPHY', 'MRI', 'USG', 'X-RAY')
    ) mt
    LEFT JOIN (
        SELECT
            t.main_test_name,
            COALESCE(SUM(t.price - bi.discount_amount), 0) AS revenue,
                        COUNT(DISTINCT b.patient_id) AS patient_count,
                        COUNT(DISTINCT b.id) AS referred_count,
                        COALESCE(SUM(COALESCE(dtp.payable_amount, t.default_payable_amount, 0)), 0) AS professional_charges
        FROM bills b
        JOIN bill_items bi ON bi.bill_id = b.id AND bi.item_status = 0
        JOIN tests t ON t.id = bi.test_id
                LEFT JOIN doctor_test_payables dtp ON dtp.doctor_id = b.referral_doctor_id AND dtp.test_id = bi.test_id
        WHERE b.referral_type = 'Doctor'
          AND b.bill_status != 'Void'
          AND b.referral_doctor_id = ?
                    AND b.created_at BETWEEN '{$doctorStartSql}' AND '{$doctorEndSql}'
        GROUP BY t.main_test_name
    ) doc_data ON doc_data.main_test_name = mt.main_test_name
    ORDER BY FIELD(mt.main_test_name, 'CT', 'ECG', 'ECHO', 'LAB', 'MAMMOGRAPHY', 'MRI', 'USG', 'X-RAY')
");
$testBreakdownStmt->bind_param('i', $doctorId);
$testBreakdownStmt->execute();
$testBreakdownResult = $testBreakdownStmt->get_result();

$testBreakdownRows = [];
while ($row = $testBreakdownResult->fetch_assoc()) {
    $testBreakdownRows[] = $row;
}
$testBreakdownStmt->close();

$subTestStmt = $conn->prepare("
    SELECT
        t.main_test_name AS main_test_name,
        COALESCE(NULLIF(t.sub_test_name, ''), t.main_test_name) AS sub_test_name,
        COALESCE(SUM(t.price - bi.discount_amount), 0) AS revenue,
        COUNT(DISTINCT b.patient_id) AS patient_count,
        COUNT(DISTINCT b.id) AS referred_count,
        COALESCE(SUM(COALESCE(dtp.payable_amount, t.default_payable_amount, 0)), 0) AS professional_charges
    FROM bills b
    JOIN bill_items bi ON bi.bill_id = b.id AND bi.item_status = 0
    JOIN tests t ON t.id = bi.test_id
    LEFT JOIN doctor_test_payables dtp ON dtp.doctor_id = b.referral_doctor_id AND dtp.test_id = bi.test_id
    WHERE b.referral_type = 'Doctor'
      AND b.bill_status != 'Void'
      AND b.referral_doctor_id = ?
            AND b.created_at BETWEEN '{$doctorStartSql}' AND '{$doctorEndSql}'
    GROUP BY t.main_test_name, sub_test_name
    ORDER BY t.main_test_name ASC, revenue DESC, sub_test_name ASC
");
$subTestStmt->bind_param('i', $doctorId);
$subTestStmt->execute();
$subTestResult = $subTestStmt->get_result();

$subTestMap = [];
while ($row = $subTestResult->fetch_assoc()) {
    $mainKey = $row['main_test_name'] ?? '';
    if ($mainKey === '') {
        continue;
    }
    if (!isset($subTestMap[$mainKey])) {
        $subTestMap[$mainKey] = [];
    }
    $subTestMap[$mainKey][] = [
        'subTestName' => $row['sub_test_name'],
        'revenue' => (float)$row['revenue'],
        'patientCount' => (int)$row['patient_count'],
        'professionalCharges' => (float)$row['professional_charges'],
        'referredCount' => (int)$row['referred_count']
    ];
}
$subTestStmt->close();
?>

<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-doctor-detail-page { display: grid; gap: 1rem; }
.sa-back-link { color: #1e3a8a; text-decoration: none; font-weight: 700; }
.sa-doctor-filter { display: flex; flex-wrap: wrap; gap: 0.65rem; align-items: flex-end; }
.sa-doctor-filter .sa-field { display: grid; gap: 0.2rem; }
.sa-doctor-filter label { font-size: 0.82rem; color: #64748b; font-weight: 700; }
.sa-doctor-filter input { border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.4rem 0.5rem; }
.sa-detail-profile {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-detail-profile h1 { margin: 0; color: #1e3a8a; font-size: 1.55rem; }
.sa-detail-meta { margin-top: 0.55rem; color: #334155; display: grid; gap: 0.25rem; }
.sa-detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.65rem; }
.sa-detail-stat { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 0.75rem; }
.sa-detail-stat .k { color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700; }
.sa-detail-stat .v { color: #0f172a; font-size: 1rem; margin-top: 0.2rem; font-weight: 700; }
.sa-breakdown-wrap {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-breakdown-wrap h2 { margin: 0 0 0.75rem; color: #1e3a8a; font-size: 1.08rem; }
.sa-breakdown-table-wrap { overflow-x: auto; }
.sa-breakdown-table { width: 100%; border-collapse: collapse; min-width: 620px; }
.sa-breakdown-table th, .sa-breakdown-table td { padding: 0.65rem; border-bottom: 1px solid #e2e8f0; text-align: left; }
.sa-breakdown-table th { background: #f8fafc; color: #1e3a8a; }
.sa-breakdown-table tbody tr[data-main-test] { cursor: pointer; }
.sa-breakdown-table tbody tr[data-main-test]:hover { background: #f8fafc; }
.sa-subtest-wrap {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-subtest-wrap h2 { margin: 0 0 0.75rem; color: #1e3a8a; font-size: 1.08rem; }
.sa-subtest-controls { display: flex; justify-content: flex-end; margin-bottom: 0.55rem; }
.sa-subtest-controls label { display: inline-flex; align-items: center; gap: 0.35rem; color: #475569; font-size: 0.85rem; }
.sa-subtest-controls input { border: 1px solid #cbd5e1; border-radius: 7px; padding: 0.35rem 0.45rem; }
.sa-subtest-table-wrap { overflow-x: auto; }
.sa-subtest-table { width: 100%; border-collapse: collapse; min-width: 780px; }
.sa-subtest-table th, .sa-subtest-table td { padding: 0.65rem; border-bottom: 1px solid #e2e8f0; text-align: left; }
.sa-subtest-table th { background: #f8fafc; color: #1e3a8a; }
.sa-empty { color: #64748b; }
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<section class="sa-doctor-detail-page">
    <a class="sa-back-link" href="view_doctors.php?start_date=<?php echo urlencode($doctorStartDate); ?>&end_date=<?php echo urlencode($doctorEndDate); ?>"><i class="fas fa-arrow-left"></i> Back to Doctors</a>

    <form id="sa-doctor-filter" class="sa-doctor-filter" autocomplete="off">
        <div class="sa-field">
            <label for="sa-doctor-start-date">Start Date</label>
            <input type="date" id="sa-doctor-start-date" value="<?php echo htmlspecialchars($doctorStartDate); ?>">
        </div>
        <div class="sa-field">
            <label for="sa-doctor-end-date">End Date</label>
            <input type="date" id="sa-doctor-end-date" value="<?php echo htmlspecialchars($doctorEndDate); ?>">
        </div>
    </form>

    <article class="sa-detail-profile">
        <h1><?php echo htmlspecialchars($doctor['doctor_name']); ?></h1>
        <div class="sa-detail-meta">
            <div><strong>Hospital:</strong> <?php echo htmlspecialchars($doctor['hospital_name'] ?: '-'); ?></div>
            <div><strong>Phone:</strong> <?php echo htmlspecialchars($doctor['phone_number'] ?: '-'); ?></div>
            <div><strong>Email:</strong> <?php echo htmlspecialchars($doctor['email'] ?: '-'); ?></div>
            <div><strong>City:</strong> <?php echo htmlspecialchars($doctor['city'] ?: '-'); ?></div>
            <div><strong>Address:</strong> <?php echo htmlspecialchars($doctor['address'] ?: '-'); ?></div>
            <div><strong>Status:</strong> <?php echo ((int)$doctor['is_active'] === 1) ? 'Active' : 'Inactive'; ?></div>
        </div>
    </article>

    <section class="sa-detail-grid">
        <div class="sa-detail-stat">
            <div class="k">Test Count</div>
            <div class="v"><?php echo number_format((int)($summary['test_count'] ?? 0)); ?></div>
        </div>
        <div class="sa-detail-stat">
            <div class="k">Revenue</div>
            <div class="v">Rs. <?php echo number_format((float)($summary['revenue'] ?? 0), 2); ?></div>
        </div>
        <div class="sa-detail-stat">
            <div class="k">No. of Patients</div>
            <div class="v"><?php echo number_format((int)($summary['referred_patients'] ?? 0)); ?></div>
        </div>
        <div class="sa-detail-stat">
            <div class="k">Professional Charges</div>
            <div class="v">Rs. <?php echo number_format((float)($summary['professional_charges'] ?? 0), 2); ?></div>
        </div>
    </section>

    <article class="sa-breakdown-wrap" id="doctor-test-breakdown">
        <h2>Test Breakdown Details</h2>
        <div class="sa-breakdown-table-wrap">
            <table class="sa-breakdown-table">
                <thead>
                    <tr>
                        <th>Test Name</th>
                        <th>Revenue</th>
                        <th>No. of Patients</th>
                        <th>Professional Charges</th>
                        <th>No. of Referred</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($testBreakdownRows) === 0): ?>
                        <tr><td colspan="5" class="sa-empty">No test breakdown records found for this doctor.</td></tr>
                    <?php else: ?>
                        <?php foreach ($testBreakdownRows as $row): ?>
                            <tr data-main-test="<?php echo htmlspecialchars($row['test_name']); ?>">
                                <td><?php echo htmlspecialchars($row['test_name']); ?></td>
                                <td>Rs. <?php echo number_format((float)$row['revenue'], 2); ?></td>
                                <td><?php echo number_format((int)$row['patient_count']); ?></td>
                                <td>Rs. <?php echo number_format((float)$row['professional_charges'], 2); ?></td>
                                <td><?php echo number_format((int)$row['referred_count']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="sa-subtest-wrap" id="sa-subtest-breakdown" hidden>
        <h2 id="sa-subtest-heading">Sub Test Breakdown</h2>
        <div class="sa-subtest-controls">
            <label>
                Search
                <input type="search" id="sa-subtest-search" placeholder="Search sub test...">
            </label>
        </div>
        <div class="sa-subtest-table-wrap">
            <table class="sa-subtest-table">
                <thead>
                    <tr>
                        <th>Sub Test Name</th>
                        <th>Revenue</th>
                        <th>No. of Patients</th>
                        <th>Professional Charges</th>
                        <th>No. of Referred</th>
                    </tr>
                </thead>
                <tbody id="sa-subtest-body"></tbody>
            </table>
        </div>
    </article>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const subTestMap = <?php echo json_encode($subTestMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const mainRows = document.querySelectorAll('.sa-breakdown-table tbody tr[data-main-test]');
    const panel = document.getElementById('sa-subtest-breakdown');
    const heading = document.getElementById('sa-subtest-heading');
    const body = document.getElementById('sa-subtest-body');
    const subtestSearchInput = document.getElementById('sa-subtest-search');
    let activeMainTest = '';

    if (!panel || !heading || !body || !mainRows.length) {
        return;
    }

    function formatInr(value) {
        const amount = Number(value || 0);
        return 'Rs. ' + amount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderSubTests(mainTest) {
        activeMainTest = mainTest;
        const rows = Array.isArray(subTestMap[mainTest]) ? subTestMap[mainTest] : [];
        const searchText = subtestSearchInput ? String(subtestSearchInput.value || '').toLowerCase() : '';
        const filteredRows = rows.filter(function (row) {
            if (!searchText) return true;
            return String(row.subTestName || '').toLowerCase().includes(searchText);
        });

        heading.textContent = mainTest + ' - Sub Test Breakdown';

        if (filteredRows.length === 0) {
            body.innerHTML = '<tr><td colspan="5" class="sa-empty">No sub-test records found for ' + mainTest + '.</td></tr>';
            panel.hidden = false;
            return;
        }

        body.innerHTML = filteredRows.map(function (row) {
            return '<tr>' +
                '<td>' + String(row.subTestName || '-') + '</td>' +
                '<td>' + formatInr(row.revenue) + '</td>' +
                '<td>' + Number(row.patientCount || 0).toLocaleString('en-IN') + '</td>' +
                '<td>' + formatInr(row.professionalCharges) + '</td>' +
                '<td>' + Number(row.referredCount || 0).toLocaleString('en-IN') + '</td>' +
            '</tr>';
        }).join('');

        panel.hidden = false;
    }

    mainRows.forEach(function (row) {
        row.addEventListener('click', function () {
            const mainTest = row.getAttribute('data-main-test');
            if (!mainTest) return;
            renderSubTests(mainTest);
        });
    });

    if (subtestSearchInput) {
        subtestSearchInput.addEventListener('input', function () {
            if (!activeMainTest) return;
            renderSubTests(activeMainTest);
        });
    }

    const filterForm = document.getElementById('sa-doctor-filter');
    const start = document.getElementById('sa-doctor-start-date');
    const end = document.getElementById('sa-doctor-end-date');
    function applyDateFilter() {
        if (!start || !end) return;
        const params = new URLSearchParams(window.location.search);
        params.set('doctor_id', '<?php echo (int)$doctorId; ?>');
        if (start.value) params.set('start_date', start.value);
        if (end.value) params.set('end_date', end.value);
        window.location.search = params.toString();
    }

    if (start) start.addEventListener('change', applyDateFilter);
    if (end) end.addEventListener('change', applyDateFilter);
    if (filterForm) {
        filterForm.addEventListener('submit', function (event) {
            event.preventDefault();
        });
    }
});
</script>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>