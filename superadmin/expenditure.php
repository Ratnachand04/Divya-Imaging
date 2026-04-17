<?php
$page_title = "Financials";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/header.php';

$sa_active_page = 'expenditure.php';

$todayDate = date('Y-m-d');
$monthStartDate = date('Y-m-01');

$periodStartDate = $_GET['period_start'] ?? $monthStartDate;
$periodEndDate = $_GET['period_end'] ?? $todayDate;

$periodStartObj = DateTime::createFromFormat('Y-m-d', $periodStartDate);
if (!$periodStartObj || $periodStartObj->format('Y-m-d') !== $periodStartDate) {
    $periodStartObj = new DateTime($monthStartDate);
}

$periodEndObj = DateTime::createFromFormat('Y-m-d', $periodEndDate);
if (!$periodEndObj || $periodEndObj->format('Y-m-d') !== $periodEndDate) {
    $periodEndObj = new DateTime($todayDate);
}

if ($periodEndObj < $periodStartObj) {
    $periodEndObj = clone $periodStartObj;
}

$periodStartDate = $periodStartObj->format('Y-m-d');
$periodEndDate = $periodEndObj->format('Y-m-d');

if (!function_exists('format_inr')) {
    function format_inr($amount)
    {
        return 'Rs. ' . number_format((float)$amount, 2);
    }
}

if (!function_exists('expenditure_read_source')) {
    function expenditure_read_source(mysqli $conn, string $tableName, string $alias): string
    {
        if (function_exists('table_scale_get_read_source')) {
            return table_scale_get_read_source($conn, $tableName, $alias);
        }
        return '`' . $tableName . '` ' . $alias;
    }
}

function fetchFinancialSummary(mysqli $conn, string $startDate, string $endDate): array
{
    $summary = [
        'revenue' => 0.0,
        'discount_center' => 0.0,
        'discount_doctor' => 0.0,
        'professional_charges' => 0.0,
    ];

    $revenueSql = "
        SELECT
            COALESCE(SUM(CASE WHEN b.bill_status != 'Void' THEN b.net_amount ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN b.bill_status != 'Void' AND b.discount_by = 'Center' THEN COALESCE(b.discount, 0) ELSE 0 END), 0) AS discount_center,
            COALESCE(SUM(CASE WHEN b.bill_status != 'Void' AND b.discount_by = 'Doctor' THEN COALESCE(b.discount, 0) ELSE 0 END), 0) AS discount_doctor
        FROM " . expenditure_read_source($conn, 'bills', 'b') . "
        WHERE DATE(b.created_at) BETWEEN ? AND ?
    ";

    $stmt = $conn->prepare($revenueSql);
    if ($stmt) {
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $summary['revenue'] = (float)$row['revenue'];
            $summary['discount_center'] = (float)$row['discount_center'];
            $summary['discount_doctor'] = (float)$row['discount_doctor'];
        }
        $stmt->close();
    }

    $professionalSql = "
        SELECT
            COALESCE(SUM(COALESCE(dtp.payable_amount, t.default_payable_amount, 0)), 0) AS professional_charges
        FROM " . expenditure_read_source($conn, 'bills', 'b') . "
        JOIN " . expenditure_read_source($conn, 'bill_items', 'bi') . " ON bi.bill_id = b.id AND bi.item_status = 0
        LEFT JOIN " . expenditure_read_source($conn, 'tests', 't') . " ON t.id = bi.test_id
        LEFT JOIN " . expenditure_read_source($conn, 'doctor_test_payables', 'dtp') . " ON dtp.doctor_id = b.referral_doctor_id AND dtp.test_id = bi.test_id
        WHERE b.bill_status != 'Void'
          AND DATE(b.created_at) BETWEEN ? AND ?
    ";

    $stmt = $conn->prepare($professionalSql);
    if ($stmt) {
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $summary['professional_charges'] = (float)$row['professional_charges'];
        }
        $stmt->close();
    }

    return $summary;
}

function fetchPaymentModeRevenue(mysqli $conn, string $startDate, string $endDate): array
{
    $out = [];

    $sql = "
        SELECT
            COALESCE(NULLIF(b.payment_mode, ''), 'Unspecified') AS payment_mode,
            COALESCE(SUM(b.net_amount), 0) AS total_revenue
                FROM " . expenditure_read_source($conn, 'bills', 'b') . "
        WHERE b.bill_status != 'Void'
          AND DATE(b.created_at) BETWEEN ? AND ?
        GROUP BY payment_mode
        ORDER BY total_revenue DESC
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $out[] = [
                'label' => $row['payment_mode'],
                'value' => (float)$row['total_revenue'],
            ];
        }
        $stmt->close();
    }

    return $out;
}

function fetchTopExpenditures(mysqli $conn, string $startDate, string $endDate): array
{
    $items = [];

    $sql = "
        SELECT
            COALESCE(NULLIF(e.expense_type, ''), 'Uncategorised') AS expense_type,
            COALESCE(SUM(e.amount), 0) AS total_amount
        FROM " . expenditure_read_source($conn, 'expenses', 'e') . "
        WHERE DATE(e.created_at) BETWEEN ? AND ?
        GROUP BY expense_type
        ORDER BY total_amount DESC
        LIMIT 10
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'name' => $row['expense_type'],
                'amount' => (float)$row['total_amount'],
            ];
        }
        $stmt->close();
    }

    return $items;
}

function fetchTopProfessionalCharges(mysqli $conn, string $startDate, string $endDate): array
{
    $items = [];

    $sql = "
        SELECT
            COALESCE(rd.doctor_name, 'Unknown Doctor') AS doctor_name,
            COALESCE(SUM(COALESCE(dtp.payable_amount, t.default_payable_amount, 0)), 0) AS total_amount
        FROM " . expenditure_read_source($conn, 'bills', 'b') . "
        JOIN " . expenditure_read_source($conn, 'bill_items', 'bi') . " ON bi.bill_id = b.id AND bi.item_status = 0
        LEFT JOIN " . expenditure_read_source($conn, 'tests', 't') . " ON t.id = bi.test_id
        LEFT JOIN " . expenditure_read_source($conn, 'doctor_test_payables', 'dtp') . " ON dtp.doctor_id = b.referral_doctor_id AND dtp.test_id = bi.test_id
        LEFT JOIN " . expenditure_read_source($conn, 'referral_doctors', 'rd') . " ON rd.id = b.referral_doctor_id
        WHERE b.bill_status != 'Void'
          AND b.referral_type = 'Doctor'
          AND DATE(b.created_at) BETWEEN ? AND ?
        GROUP BY b.referral_doctor_id, rd.doctor_name
        ORDER BY total_amount DESC
                LIMIT 10
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'name' => $row['doctor_name'],
                'amount' => (float)$row['total_amount'],
            ];
        }
        $stmt->close();
    }

    return $items;
}

$todaySummary = fetchFinancialSummary($conn, $todayDate, $todayDate);
$monthSummary = fetchFinancialSummary($conn, $periodStartDate, $periodEndDate);

$todayPaymentModes = fetchPaymentModeRevenue($conn, $todayDate, $todayDate);
$monthPaymentModes = fetchPaymentModeRevenue($conn, $periodStartDate, $periodEndDate);

$topExpenditures = fetchTopExpenditures($conn, $periodStartDate, $periodEndDate);
$topProfessionalCharges = fetchTopProfessionalCharges($conn, $periodStartDate, $periodEndDate);
?>

<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-financials-page { display: grid; gap: 1rem; }
.sa-fin-head h1 { margin: 0; color: #1e3a8a; font-size: 1.55rem; }
.sa-fin-head p { margin: 0.2rem 0 0; color: #64748b; }
.sa-fin-section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-fin-section h2 { margin: 0; color: #1e3a8a; font-size: 1.1rem; }
.sa-fin-section .meta { margin-top: 0.15rem; color: #64748b; font-size: 0.85rem; }
.sa-period-filter { margin-top: 0.6rem; display: flex; flex-wrap: wrap; gap: 0.65rem; align-items: flex-end; }
.sa-period-filter .sa-field { display: grid; gap: 0.2rem; }
.sa-period-filter label { font-size: 0.8rem; color: #64748b; font-weight: 700; }
.sa-period-filter input { border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.4rem 0.5rem; }
.sa-fin-grid { margin-top: 0.8rem; display: grid; grid-template-columns: 1.2fr 1fr; gap: 0.8rem; }
.sa-fin-grid { align-items: start; }
.sa-fin-kpis { display: grid; grid-template-columns: repeat(2, minmax(160px, 1fr)); gap: 0.6rem; }
.sa-fin-kpi {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.7rem;
}
.sa-fin-kpi .k { display: block; color: #64748b; font-size: 0.74rem; text-transform: uppercase; font-weight: 700; }
.sa-fin-kpi .v { display: block; margin-top: 0.2rem; color: #0f172a; font-size: 1rem; font-weight: 700; }
.sa-chart-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.65rem;
    height: 300px;
    max-height: 300px;
    overflow: hidden;
}
.sa-chart-title { margin: 0 0 0.35rem; font-size: 0.82rem; color: #475569; font-weight: 700; }
.sa-chart-card canvas {
    width: 100% !important;
    height: 240px !important;
}
.sa-bottom-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
.sa-list-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-list-card h3 { margin: 0 0 0.6rem; color: #1e3a8a; font-size: 1rem; }
.sa-list-card ul { margin: 0; padding-left: 1rem; max-height: 220px; overflow-y: auto; }
.sa-list-card li { margin: 0.35rem 0; color: #334155; }
.sa-list-card strong { color: #0f172a; }
.sa-empty { color: #64748b; margin: 0; }
@media (max-width: 1024px) {
    .sa-fin-grid { grid-template-columns: 1fr; }
    .sa-bottom-grid { grid-template-columns: 1fr; }
}
@media (max-width: 760px) {
    .sa-fin-kpis { grid-template-columns: 1fr; }
}
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<section class="sa-financials-page">
    <div class="sa-fin-head">
        <h1>Financials</h1>
        <p>Today and month-to-date financial performance overview.</p>
    </div>

    <article class="sa-fin-section">
        <h2>Today</h2>
        <p class="meta"><?php echo date('d M Y', strtotime($todayDate)); ?></p>

        <div class="sa-fin-grid">
            <div class="sa-fin-kpis">
                <div class="sa-fin-kpi">
                    <span class="k">Revenue</span>
                    <span class="v"><?php echo format_inr($todaySummary['revenue']); ?></span>
                </div>
                <div class="sa-fin-kpi">
                    <span class="k">Center Discount</span>
                    <span class="v"><?php echo format_inr($todaySummary['discount_center']); ?></span>
                </div>
                <div class="sa-fin-kpi">
                    <span class="k">Doctor Discount</span>
                    <span class="v"><?php echo format_inr($todaySummary['discount_doctor']); ?></span>
                </div>
                <div class="sa-fin-kpi">
                    <span class="k">Professional Charges</span>
                    <span class="v"><?php echo format_inr($todaySummary['professional_charges']); ?></span>
                </div>
            </div>
            <div class="sa-chart-card">
                <p class="sa-chart-title">Revenue vs Payment Type (Today)</p>
                <canvas id="todayPaymentChart" height="210"></canvas>
            </div>
        </div>
    </article>

    <article class="sa-fin-section">
        <h2>This Month</h2>
        <p class="meta"><?php echo date('d M Y', strtotime($periodStartDate)); ?> to <?php echo date('d M Y', strtotime($periodEndDate)); ?></p>

        <form id="sa-period-filter" class="sa-period-filter" autocomplete="off">
            <div class="sa-field">
                <label for="sa-period-start">Start Date</label>
                <input type="date" id="sa-period-start" value="<?php echo htmlspecialchars($periodStartDate); ?>">
            </div>
            <div class="sa-field">
                <label for="sa-period-end">End Date</label>
                <input type="date" id="sa-period-end" value="<?php echo htmlspecialchars($periodEndDate); ?>">
            </div>
        </form>

        <div class="sa-fin-grid">
            <div class="sa-fin-kpis">
                <div class="sa-fin-kpi">
                    <span class="k">Revenue</span>
                    <span class="v"><?php echo format_inr($monthSummary['revenue']); ?></span>
                </div>
                <div class="sa-fin-kpi">
                    <span class="k">Center Discount</span>
                    <span class="v"><?php echo format_inr($monthSummary['discount_center']); ?></span>
                </div>
                <div class="sa-fin-kpi">
                    <span class="k">Doctor Discount</span>
                    <span class="v"><?php echo format_inr($monthSummary['discount_doctor']); ?></span>
                </div>
                <div class="sa-fin-kpi">
                    <span class="k">Professional Charges</span>
                    <span class="v"><?php echo format_inr($monthSummary['professional_charges']); ?></span>
                </div>
            </div>
            <div class="sa-chart-card">
                <p class="sa-chart-title">Revenue vs Payment Type (This Month)</p>
                <canvas id="monthPaymentChart" height="210"></canvas>
            </div>
        </div>
    </article>

    <div class="sa-bottom-grid">
        <article class="sa-list-card">
            <h3>Top 10 Expenditure</h3>
            <?php if (count($topExpenditures) === 0): ?>
                <p class="sa-empty">No expenditure data found for this month.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($topExpenditures as $item): ?>
                        <li><strong><?php echo htmlspecialchars($item['name']); ?></strong> - <?php echo format_inr($item['amount']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>

        <article class="sa-list-card">
            <h3>Top 10 Professional Charges</h3>
            <?php if (count($topProfessionalCharges) === 0): ?>
                <p class="sa-empty">No professional charge data found for this month.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($topProfessionalCharges as $item): ?>
                        <li><strong><?php echo htmlspecialchars($item['name']); ?></strong> - <?php echo format_inr($item['amount']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    </div>
</section>

<script>
window.addEventListener('load', function () {
    const periodStart = document.getElementById('sa-period-start');
    const periodEnd = document.getElementById('sa-period-end');
    const periodForm = document.getElementById('sa-period-filter');

    function applyPeriodFilter() {
        if (!periodStart || !periodEnd) return;
        const params = new URLSearchParams(window.location.search);
        if (periodStart.value) params.set('period_start', periodStart.value);
        if (periodEnd.value) params.set('period_end', periodEnd.value);
        window.location.search = params.toString();
    }

    if (periodStart) periodStart.addEventListener('change', applyPeriodFilter);
    if (periodEnd) periodEnd.addEventListener('change', applyPeriodFilter);
    if (periodForm) {
        periodForm.addEventListener('submit', function (event) {
            event.preventDefault();
        });
    }

    function buildBarChart(canvasId, labels, values, color) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Revenue',
                    data: values,
                    backgroundColor: color,
                    borderRadius: 6,
                    maxBarThickness: 42
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'Rs. ' + Number(value).toLocaleString('en-IN');
                            }
                        }
                    }
                }
            }
        });
    }

    const todayPayment = <?php echo json_encode($todayPaymentModes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const monthPayment = <?php echo json_encode($monthPaymentModes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    const todayLabels = todayPayment.map(function (x) { return x.label; });
    const todayValues = todayPayment.map(function (x) { return x.value; });

    const monthLabels = monthPayment.map(function (x) { return x.label; });
    const monthValues = monthPayment.map(function (x) { return x.value; });

    buildBarChart('todayPaymentChart', todayLabels, todayValues, 'rgba(30, 64, 175, 0.75)');
    buildBarChart('monthPaymentChart', monthLabels, monthValues, 'rgba(8, 145, 178, 0.75)');
});
</script>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>
