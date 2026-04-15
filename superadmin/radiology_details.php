<?php
$page_title = "Radiologist Details";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

$sa_active_page = 'test_count.php';

$conn->query("ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS reporting_doctor VARCHAR(150) DEFAULT NULL");

$radiologist = isset($_GET['radiologist']) ? trim($_GET['radiologist']) : '';
if ($radiologist === '') {
    echo '<div class="page-container"><div class="error-banner">Invalid radiologist selected.</div></div>';
    require_once '../includes/footer.php';
    exit;
}

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$summarySql = "
    SELECT
        COUNT(*) AS total_allotted,
        SUM(CASE WHEN bi.report_status = 'Completed' THEN 1 ELSE 0 END) AS completed_count,
        SUM(CASE WHEN COALESCE(bi.report_status, 'Pending') = 'Pending' THEN 1 ELSE 0 END) AS pending_count
    FROM bill_items bi
    JOIN bills b ON b.id = bi.bill_id
    WHERE bi.item_status = 0
      AND b.bill_status != 'Void'
      AND bi.reporting_doctor = ?
      AND DATE(b.created_at) BETWEEN ? AND ?
";

$summaryStmt = $conn->prepare($summarySql);
$summaryStmt->bind_param('sss', $radiologist, $monthStart, $monthEnd);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

$detailsSql = "
    SELECT
        t.main_test_name,
        COALESCE(NULLIF(t.sub_test_name, ''), t.main_test_name) AS sub_test_name,
        COUNT(*) AS total_allotted,
        SUM(CASE WHEN bi.report_status = 'Completed' THEN 1 ELSE 0 END) AS completed_count,
        SUM(CASE WHEN COALESCE(bi.report_status, 'Pending') = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
        COUNT(DISTINCT b.patient_id) AS patient_count,
        COALESCE(SUM(t.price - bi.discount_amount), 0) AS revenue,
        COALESCE(SUM(COALESCE(dtp.payable_amount, t.default_payable_amount, 0)), 0) AS professional_charges
    FROM bill_items bi
    JOIN bills b ON b.id = bi.bill_id
    JOIN tests t ON t.id = bi.test_id
    LEFT JOIN doctor_test_payables dtp ON dtp.doctor_id = b.referral_doctor_id AND dtp.test_id = bi.test_id
    WHERE bi.item_status = 0
      AND b.bill_status != 'Void'
      AND bi.reporting_doctor = ?
      AND DATE(b.created_at) BETWEEN ? AND ?
    GROUP BY t.id, t.main_test_name, sub_test_name
    ORDER BY t.main_test_name ASC, sub_test_name ASC
";

$detailsStmt = $conn->prepare($detailsSql);
$detailsStmt->bind_param('sss', $radiologist, $monthStart, $monthEnd);
$detailsStmt->execute();
$detailsResult = $detailsStmt->get_result();

$rows = [];
while ($row = $detailsResult->fetch_assoc()) {
    $rows[] = $row;
}
$detailsStmt->close();
?>

<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-rad-detail-page { display: grid; gap: 1rem; }
.sa-back-link { color: #1e3a8a; text-decoration: none; font-weight: 700; }
.sa-rad-filter { display: flex; gap: 0.65rem; align-items: flex-end; }
.sa-rad-filter .sa-field { display: grid; gap: 0.2rem; }
.sa-rad-filter label { font-size: 0.82rem; color: #64748b; font-weight: 700; }
.sa-rad-filter input { border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.4rem 0.5rem; }
.sa-rad-profile {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-rad-profile h1 { margin: 0; color: #1e3a8a; font-size: 1.45rem; }
.sa-rad-meta { margin-top: 0.2rem; color: #64748b; }
.sa-rad-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.65rem; }
.sa-rad-stat { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 0.75rem; }
.sa-rad-stat .k { color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700; }
.sa-rad-stat .v { color: #0f172a; font-size: 1rem; margin-top: 0.2rem; font-weight: 700; }
.sa-rad-table-wrap {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-rad-table-wrap h2 { margin: 0 0 0.75rem; color: #1e3a8a; font-size: 1.08rem; }
.sa-rad-table-scroll { overflow-x: auto; }
.sa-rad-table { width: 100%; border-collapse: collapse; min-width: 1180px; }
.sa-rad-table th, .sa-rad-table td { padding: 0.65rem; border-bottom: 1px solid #e2e8f0; text-align: left; }
.sa-rad-table th { background: #f8fafc; color: #1e3a8a; }
.sa-empty { color: #64748b; }
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<section class="sa-rad-detail-page">
    <a class="sa-back-link" href="test_count.php?month=<?php echo urlencode($month); ?>"><i class="fas fa-arrow-left"></i> Back to Radiology</a>

    <form id="sa-rad-detail-filter" class="sa-rad-filter" autocomplete="off">
        <div class="sa-field">
            <label for="sa-rad-detail-month">Month</label>
            <input type="month" id="sa-rad-detail-month" value="<?php echo htmlspecialchars($month); ?>">
        </div>
    </form>

    <article class="sa-rad-profile">
        <h1><?php echo htmlspecialchars($radiologist); ?></h1>
        <p class="sa-rad-meta">Report allocation and completion details for <?php echo date('F Y', strtotime($monthStart)); ?></p>
    </article>

    <section class="sa-rad-grid">
        <div class="sa-rad-stat">
            <div class="k">Total Test Allotted</div>
            <div class="v"><?php echo number_format((int)($summary['total_allotted'] ?? 0)); ?></div>
        </div>
        <div class="sa-rad-stat">
            <div class="k">Completed</div>
            <div class="v"><?php echo number_format((int)($summary['completed_count'] ?? 0)); ?></div>
        </div>
        <div class="sa-rad-stat">
            <div class="k">Pending</div>
            <div class="v"><?php echo number_format((int)($summary['pending_count'] ?? 0)); ?></div>
        </div>
    </section>

    <article class="sa-rad-table-wrap">
        <h2>All Test Details</h2>
        <div class="sa-rad-table-scroll">
            <table class="sa-rad-table">
                <thead>
                    <tr>
                        <th>Main Test</th>
                        <th>Sub Test</th>
                        <th>Allotted</th>
                        <th>Completed</th>
                        <th>Pending</th>
                        <th>No. of Patients</th>
                        <th>Revenue</th>
                        <th>Professional Charges</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($rows) === 0): ?>
                        <tr><td colspan="8" class="sa-empty">No test details found for selected month.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['main_test_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['sub_test_name']); ?></td>
                                <td><?php echo number_format((int)$row['total_allotted']); ?></td>
                                <td><?php echo number_format((int)$row['completed_count']); ?></td>
                                <td><?php echo number_format((int)$row['pending_count']); ?></td>
                                <td><?php echo number_format((int)$row['patient_count']); ?></td>
                                <td>Rs. <?php echo number_format((float)$row['revenue'], 2); ?></td>
                                <td>Rs. <?php echo number_format((float)$row['professional_charges'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const monthInput = document.getElementById('sa-rad-detail-month');
    if (!monthInput) return;

    monthInput.addEventListener('change', function () {
        const params = new URLSearchParams(window.location.search);
        params.set('radiologist', <?php echo json_encode($radiologist); ?>);
        if (monthInput.value) {
            params.set('month', monthInput.value);
        }
        window.location.search = params.toString();
    });
});
</script>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>
