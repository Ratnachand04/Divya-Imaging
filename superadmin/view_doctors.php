<?php
$page_title = "Doctors";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

$sa_active_page = 'view_doctors.php';

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

$summarySql = "
    SELECT
        rd.id,
        rd.doctor_name,
        COALESCE(rd.hospital_name, '-') AS hospital_name,
        COUNT(DISTINCT b.id) AS referred_patients,
        COUNT(bi.id) AS test_count,
        COALESCE(SUM(CASE WHEN b.bill_status != 'Void' THEN b.net_amount ELSE 0 END), 0) AS revenue,
        COALESCE(SUM(CASE WHEN b.bill_status != 'Void' THEN COALESCE(dtp.payable_amount, t.default_payable_amount, 0) ELSE 0 END), 0) AS professional_charges
    FROM referral_doctors rd
    LEFT JOIN bills b
        ON b.referral_doctor_id = rd.id
       AND b.referral_type = 'Doctor'
       AND b.bill_status != 'Void'
       AND b.created_at BETWEEN '{$doctorStartSql}' AND '{$doctorEndSql}'
    LEFT JOIN bill_items bi
        ON bi.bill_id = b.id
       AND bi.item_status = 0
    LEFT JOIN tests t
        ON t.id = bi.test_id
    LEFT JOIN doctor_test_payables dtp
        ON dtp.doctor_id = rd.id
       AND dtp.test_id = bi.test_id
    GROUP BY rd.id, rd.doctor_name, rd.hospital_name
    ORDER BY rd.doctor_name ASC
";

$summaryResult = $conn->query($summarySql);
$doctors = [];
if ($summaryResult) {
    while ($row = $summaryResult->fetch_assoc()) {
        $doctors[] = [
            'id' => (int)$row['id'],
            'doctor_name' => $row['doctor_name'],
            'hospital_name' => $row['hospital_name'],
            'test_count' => (int)$row['test_count'],
            'revenue' => (float)$row['revenue'],
            'referred_patients' => (int)$row['referred_patients'],
            'professional_charges' => (float)$row['professional_charges']
        ];
    }
}
?>

<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-doctors-page { display: grid; gap: 1rem; }
.sa-doctors-head h1 { margin: 0; color: #1e3a8a; font-size: 1.55rem; }
.sa-doctors-head p { margin: 0.2rem 0 0; color: #64748b; }
.sa-doctors-filter { display: flex; flex-wrap: wrap; gap: 0.65rem; align-items: flex-end; }
.sa-doctors-filter .sa-field { display: grid; gap: 0.2rem; }
.sa-doctors-filter label { font-size: 0.82rem; color: #64748b; font-weight: 700; }
.sa-doctors-filter input { border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.4rem 0.5rem; }
.sa-doctors-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 0.9rem; }
.sa-doctor-card {
    display: block;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
    text-decoration: none;
    color: #0f172a;
    transition: transform 0.18s ease, border-color 0.18s ease;
}
.sa-doctor-card:hover { transform: translateY(-2px); border-color: #93c5fd; text-decoration: none; }
.sa-doctor-card h3 { margin: 0; font-size: 1.05rem; color: #1e3a8a; }
.sa-doctor-card .hospital { margin: 0.25rem 0 0.8rem; color: #64748b; font-size: 0.92rem; }
.sa-doc-metrics { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; }
.sa-metric { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.55rem 0.65rem; }
.sa-metric .k { display: block; font-size: 0.73rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
.sa-metric .v { display: block; margin-top: 0.2rem; font-size: 0.97rem; font-weight: 700; color: #0f172a; }
.sa-doctors-empty { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 1rem; color: #475569; }
@media (max-width: 768px) {
    .sa-doc-metrics { grid-template-columns: 1fr; }
}
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<section class="sa-doctors-page">
    <div class="sa-doctors-head">
        <h1>Doctors</h1>
        <p>Click any doctor card to view full details and referred patient list.</p>
    </div>

    <form id="sa-doctors-filter" class="sa-doctors-filter" autocomplete="off">
        <div class="sa-field">
            <label for="sa-doctors-start-date">Start Date</label>
            <input type="date" id="sa-doctors-start-date" value="<?php echo htmlspecialchars($doctorStartDate); ?>">
        </div>
        <div class="sa-field">
            <label for="sa-doctors-end-date">End Date</label>
            <input type="date" id="sa-doctors-end-date" value="<?php echo htmlspecialchars($doctorEndDate); ?>">
        </div>
    </form>

    <?php if (count($doctors) === 0): ?>
        <div class="sa-doctors-empty">No doctors found.</div>
    <?php else: ?>
        <div class="sa-doctors-grid">
            <?php foreach ($doctors as $doctor): ?>
                <a class="sa-doctor-card" href="view_doctor_details.php?doctor_id=<?php echo (int)$doctor['id']; ?>&start_date=<?php echo urlencode($doctorStartDate); ?>&end_date=<?php echo urlencode($doctorEndDate); ?>">
                    <h3><?php echo htmlspecialchars($doctor['doctor_name']); ?></h3>
                    <p class="hospital"><?php echo htmlspecialchars($doctor['hospital_name']); ?></p>

                    <div class="sa-doc-metrics">
                        <div class="sa-metric">
                            <span class="k">Test Count</span>
                            <span class="v"><?php echo number_format($doctor['test_count']); ?></span>
                        </div>
                        <div class="sa-metric">
                            <span class="k">Revenue</span>
                            <span class="v">Rs. <?php echo number_format($doctor['revenue'], 2); ?></span>
                        </div>
                        <div class="sa-metric">
                            <span class="k">No. of Patients</span>
                            <span class="v"><?php echo number_format($doctor['referred_patients']); ?></span>
                        </div>
                        <div class="sa-metric">
                            <span class="k">Professional Charges</span>
                            <span class="v">Rs. <?php echo number_format($doctor['professional_charges'], 2); ?></span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('sa-doctors-filter');
    const start = document.getElementById('sa-doctors-start-date');
    const end = document.getElementById('sa-doctors-end-date');
    if (!form || !start || !end) return;

    function applyDateFilter() {
        const params = new URLSearchParams(window.location.search);
        if (start.value) params.set('start_date', start.value);
        if (end.value) params.set('end_date', end.value);
        window.location.search = params.toString();
    }

    start.addEventListener('change', applyDateFilter);
    end.addEventListener('change', applyDateFilter);
    form.addEventListener('submit', function (event) {
        event.preventDefault();
    });
});
</script>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>