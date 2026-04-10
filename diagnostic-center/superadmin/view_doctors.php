<?php
$page_title = "All Doctors";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$defaultStartDate = date('Y-m-01');
$defaultEndDate = date('Y-m-d');

$startDateInput = $_GET['start_date'] ?? $defaultStartDate;
$endDateInput = $_GET['end_date'] ?? $defaultEndDate;

$startDateObject = DateTime::createFromFormat('Y-m-d', $startDateInput);
if (!$startDateObject || $startDateObject->format('Y-m-d') !== $startDateInput) {
    $startDateObject = new DateTime($defaultStartDate);
}

$endDateObject = DateTime::createFromFormat('Y-m-d', $endDateInput);
if (!$endDateObject || $endDateObject->format('Y-m-d') !== $endDateInput) {
    $endDateObject = new DateTime($defaultEndDate);
}

if ($endDateObject < $startDateObject) {
    $endDateObject = clone $startDateObject;
}

$startDate = $startDateObject->format('Y-m-d');
$endDate = $endDateObject->format('Y-m-d');

$startDateForQuery = $startDate . ' 00:00:00';
$endDateForQuery = $endDate . ' 23:59:59';

$startDateSql = $conn->real_escape_string($startDateForQuery);
$endDateSql = $conn->real_escape_string($endDateForQuery);

$startDateDisplay = $startDateObject->format('d M Y');
$endDateDisplay = $endDateObject->format('d M Y');

// Consolidated doctor performance metrics with payouts, revenue, and discount overview.
$sql = "
    SELECT
        doc_data.doctor_id,
        doc_data.doctor_name,
        doc_data.hospital_name,
        doc_data.area,
        doc_data.city,
        doc_data.is_active,
        COUNT(DISTINCT doc_data.bill_id) AS total_bills,
        COALESCE(SUM(doc_data.total_tests), 0) AS total_tests,
        COALESCE(SUM(doc_data.total_payable), 0) AS professional_total,
        COALESCE(SUM(doc_data.total_payable_after_discount), 0) AS professional_after_discount,
        COALESCE(SUM(doc_data.discount_absorbed), 0) AS doctor_discount_absorbed,
        COALESCE(SUM(doc_data.gross_amount), 0) AS gross_total,
        COALESCE(SUM(doc_data.discount_amount), 0) AS discount_total,
        COALESCE(SUM(doc_data.net_amount), 0) AS net_total
    FROM (
        SELECT
            rd.id AS doctor_id,
            rd.doctor_name,
            rd.hospital_name,
            rd.address AS area,
            rd.city,
            rd.is_active,
            b.id AS bill_id,
            COUNT(bi.id) AS total_tests,
            COALESCE(b.gross_amount, 0) AS gross_amount,
            COALESCE(b.discount, 0) AS discount_amount,
            COALESCE(b.net_amount, 0) AS net_amount,
            SUM(COALESCE(dtp.payable_amount, t.default_payable_amount, 0)) AS total_payable,
            CASE
                WHEN b.discount_by = 'Doctor' THEN
                    GREATEST(SUM(COALESCE(dtp.payable_amount, t.default_payable_amount, 0)) - COALESCE(b.discount, 0), 0)
                ELSE
                    SUM(COALESCE(dtp.payable_amount, t.default_payable_amount, 0))
            END AS total_payable_after_discount,
            CASE
                WHEN b.discount_by = 'Doctor' THEN
                    LEAST(COALESCE(b.discount, 0), SUM(COALESCE(dtp.payable_amount, t.default_payable_amount, 0)))
                ELSE 0
            END AS discount_absorbed
        FROM referral_doctors rd
        LEFT JOIN bills b
            ON rd.id = b.referral_doctor_id
           AND b.bill_status != 'Void'
           AND b.referral_type = 'Doctor'
           AND b.created_at BETWEEN '{$startDateSql}' AND '{$endDateSql}'
        LEFT JOIN bill_items bi
            ON b.id = bi.bill_id
           AND bi.item_status = 0
        LEFT JOIN tests t
            ON bi.test_id = t.id
        LEFT JOIN doctor_test_payables dtp
            ON rd.id = dtp.doctor_id
           AND bi.test_id = dtp.test_id
        GROUP BY rd.id, rd.doctor_name, rd.hospital_name, rd.address, rd.city, rd.is_active,
                 b.id, b.gross_amount, b.discount, b.discount_by, b.net_amount
    ) AS doc_data
    GROUP BY doc_data.doctor_id, doc_data.doctor_name, doc_data.hospital_name, doc_data.area, doc_data.city, doc_data.is_active
    ORDER BY doc_data.doctor_name ASC
";

$doctors_result = $conn->query($sql);

if (!$doctors_result) {
    die("Error fetching doctor data: " . $conn->error);
}

$doctors = [];
while ($row = $doctors_result->fetch_assoc()) {
    $doctors[] = [
        'id' => (int) $row['doctor_id'],
        'doctor_name' => $row['doctor_name'],
        'hospital_name' => $row['hospital_name'],
        'area' => $row['area'],
        'city' => $row['city'],
        'is_active' => (int) $row['is_active'],
        'total_bills' => (int) $row['total_bills'],
        'total_tests' => (int) $row['total_tests'],
        'professional_total' => (float) $row['professional_total'],
        'professional_after_discount' => (float) $row['professional_after_discount'],
        'doctor_discount_absorbed' => (float) $row['doctor_discount_absorbed'],
        'gross_total' => (float) $row['gross_total'],
        'discount_total' => (float) $row['discount_total'],
        'net_total' => (float) $row['net_total'],
    ];
}

$totals = [
    'doctors' => count($doctors),
    'total_bills' => 0,
    'total_tests' => 0,
    'gross_total' => 0.0,
    'discount_total' => 0.0,
    'net_total' => 0.0,
    'professional_total' => 0.0,
    'professional_after_discount' => 0.0,
];

foreach ($doctors as $doc) {
    $totals['total_bills'] += $doc['total_bills'];
    $totals['total_tests'] += $doc['total_tests'];
    $totals['gross_total'] += $doc['gross_total'];
    $totals['discount_total'] += $doc['discount_total'];
    $totals['net_total'] += $doc['net_total'];
    $totals['professional_total'] += $doc['professional_total'];
    $totals['professional_after_discount'] += $doc['professional_after_discount'];
}

if (!function_exists('format_inr')) {
    function format_inr($value)
    {
        return '₹' . number_format((float) $value, 2);
    }
}

$doctorTestBreakdown = [];

$testBreakdownSql = "
    SELECT
        b.referral_doctor_id AS doctor_id,
        COALESCE(t.main_test_name, 'Uncategorised') AS main_test_name,
        COALESCE(t.sub_test_name, 'General') AS sub_test_name,
        COUNT(bi.id) AS test_count
    FROM bills b
    JOIN bill_items bi ON b.id = bi.bill_id AND bi.item_status = 0
    JOIN tests t ON bi.test_id = t.id
    WHERE b.bill_status != 'Void'
      AND b.referral_type = 'Doctor'
      AND b.referral_doctor_id IS NOT NULL
      AND b.created_at BETWEEN '{$startDateSql}' AND '{$endDateSql}'
    GROUP BY b.referral_doctor_id, main_test_name, sub_test_name
";

$testBreakdownResult = $conn->query($testBreakdownSql);
if ($testBreakdownResult === false) {
    die('Error building doctor test breakdown: ' . $conn->error);
}

while ($row = $testBreakdownResult->fetch_assoc()) {
    $doctorId = (int) ($row['doctor_id'] ?? 0);
    if ($doctorId === 0) {
        continue;
    }

    $mainName = $row['main_test_name'] ?: 'Uncategorised';
    $subName = $row['sub_test_name'] ?: 'General';
    $count = (int) ($row['test_count'] ?? 0);

    if (!isset($doctorTestBreakdown[$doctorId])) {
        $doctorTestBreakdown[$doctorId] = [
            'total' => 0,
            'main' => [],
        ];
    }

    $doctorTestBreakdown[$doctorId]['total'] += $count;

    if (!isset($doctorTestBreakdown[$doctorId]['main'][$mainName])) {
        $doctorTestBreakdown[$doctorId]['main'][$mainName] = [
            'total' => 0,
            'subtests' => [],
        ];
    }

    $doctorTestBreakdown[$doctorId]['main'][$mainName]['total'] += $count;
    $doctorTestBreakdown[$doctorId]['main'][$mainName]['subtests'][$subName] =
        ($doctorTestBreakdown[$doctorId]['main'][$mainName]['subtests'][$subName] ?? 0) + $count;
}

require_once '../includes/header.php';
?>

<style>
.doctor-name {
    cursor: pointer;
}

.doctor-name:hover,
.doctor-name:focus {
    text-decoration: underline;
}

.doctor-card {
    position: relative;
    cursor: pointer;
}

.doctor-select-wrap {
    position: absolute;
    right: 0.7rem;
    bottom: 0.65rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 7px;
    border: 1px solid #e4b8cd;
    background: #fff;
    box-shadow: 0 2px 7px rgba(15, 23, 42, 0.12);
}

.doctor-select-checkbox {
    width: 14px;
    height: 14px;
    margin: 0;
    cursor: pointer;
    accent-color: #db2777;
}

.doctor-details-modal {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1100;
    padding: 1rem;
}

.doctor-details-modal.is-open {
    display: flex;
}

.doctor-details-modal__dialog {
    width: min(640px, 96vw);
    max-height: 82vh;
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e7d2e1;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.2);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.doctor-details-modal__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.9rem 1rem;
    border-bottom: 1px solid #efe4eb;
    background: #fff7fb;
}

.doctor-details-modal__title {
    margin: 0;
    font-size: 1.05rem;
    color: #1e293b;
}

.doctor-details-modal__meta {
    margin: 0.2rem 0 0;
    font-size: 0.88rem;
    color: #64748b;
}

.doctor-details-modal__close {
    border: none;
    background: transparent;
    font-size: 1.4rem;
    line-height: 1;
    cursor: pointer;
    color: #334155;
}

.doctor-details-modal__content {
    padding: 1rem;
    overflow-y: hidden;
}

.doctor-details-modal__content.is-scroll-active {
    overflow-y: auto;
}
</style>


<main class="main-content doctor-overview-page">
    <div class="content-header">
        <div class="header-container">
            <h1>Referring Doctors Overview</h1>
            <div class="header-metrics">
                <span class="metric-chip">
                    <span class="chip-label">Doctors</span>
                    <span class="chip-value" id="doctor-total-count"><?php echo number_format($totals['doctors']); ?></span>
                </span>
                <span class="metric-chip">
                    <span class="chip-label">Total Referrals</span>
                    <span class="chip-value"><?php echo number_format($totals['total_bills']); ?></span>
                </span>
                <span class="metric-chip">
                    <span class="chip-label">Net Revenue</span>
                    <span class="chip-value"><?php echo format_inr($totals['net_total']); ?></span>
                </span>
                <span class="metric-chip">
                    <span class="chip-label">Professional Charges</span>
                    <span class="chip-value"><?php echo format_inr($totals['professional_after_discount']); ?></span>
                </span>
            </div>
            <div class="range-display" aria-live="polite">
                <span class="range-label">Data from</span>
                <span class="range-value"><?php echo htmlspecialchars($startDateDisplay); ?> – <?php echo htmlspecialchars($endDateDisplay); ?></span>
            </div>
        </div>
        <p class="page-subtitle">Review every referring doctor in one place. The summary tiles highlight revenue and professional charges, and expanding a card reveals the detailed mix with counts.</p>
    </div>

    <div class="page-card doctor-filter-card">
        <form class="doctor-range-form" id="doctor-range-form" method="GET">
            <div class="filter-group">
                <label for="start_date">From Date</label>
                <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>" max="<?php echo htmlspecialchars($defaultEndDate); ?>">
            </div>
            <div class="filter-group">
                <label for="end_date">To Date</label>
                <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>" max="<?php echo htmlspecialchars($defaultEndDate); ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="range-submit-btn">Apply Range</button>
                <a href="view_doctors.php" class="range-reset-link">Reset</a>
            </div>
        </form>
        <form class="doctor-filter-form" id="doctor-filter-form" onsubmit="return false;">
            <div class="filter-group">
                <label for="doctor-search">Filter Doctors</label>
                <input type="search" id="doctor-search" placeholder="Search by doctor, hospital, area or city" autocomplete="off">
            </div>
            <div class="filter-group">
                <label for="doctor-sort">Sort By</label>
                <select id="doctor-sort">
                    <option value="totalBills" selected>Most Referrals</option>
                    <option value="netTotal">Net Revenue</option>
                    <option value="grossTotal">Gross Revenue</option>
                    <option value="professional">Professional Charges</option>
                    <option value="doctorName">Doctor Name (A–Z)</option>
                </select>
            </div>
            <div class="filter-group filter-toggle">
                <label class="toggle-label">
                    <input type="checkbox" id="show-inactive">
                    <span>Show inactive doctors</span>
                </label>
            </div>
            <div class="filter-summary">
                <span><strong id="doctor-visible-count"><?php echo number_format($totals['doctors']); ?></strong> visible</span>
                <span class="muted-count">(<span id="doctor-visible-active-count"><?php echo number_format($totals['doctors']); ?></span> active, <span id="doctor-visible-inactive-count">0</span> inactive)</span>
            </div>
        </form>
    </div>

    <section class="doctor-cards-grid" id="doctor-cards-container">
        <?php if (!empty($doctors)): ?>
            <?php
                $accentPalette = ['accent-blue', 'accent-teal', 'accent-purple', 'accent-amber', 'accent-rose', 'accent-indigo', 'accent-sky', 'accent-green'];
                foreach ($doctors as $index => $doc):
                    $accentClass = $accentPalette[$index % count($accentPalette)];
                    $searchParts = array_filter([
                        $doc['doctor_name'] ?? '',
                        $doc['hospital_name'] ?? '',
                        $doc['city'] ?? '',
                        $doc['area'] ?? ''
                    ]);
                    $searchTokens = strtolower(implode(' ', $searchParts));
                    $net = $doc['net_total'];
                    $gross = $doc['gross_total'];
                    $discount = $doc['discount_total'];
                    $professionalOriginal = $doc['professional_total'];
                    $professionalAfterDiscount = $doc['professional_after_discount'];
                    $doctorDiscountAbsorbed = $doc['doctor_discount_absorbed'];
                    $detailsId = 'doctor-details-' . $doc['id'];
                    $isActive = (bool) $doc['is_active'];
                    $testsForDoctor = $doctorTestBreakdown[$doc['id']]['main'] ?? [];
                    $doctorTotalTestCount = $doctorTestBreakdown[$doc['id']]['total'] ?? $doc['total_tests'];
                    if (!empty($testsForDoctor)) {
                        ksort($testsForDoctor, SORT_NATURAL | SORT_FLAG_CASE);
                    }
            ?>
            <article class="doctor-card <?php echo $accentClass; ?><?php echo $isActive ? '' : ' is-inactive'; ?>"
                     data-search="<?php echo htmlspecialchars($searchTokens); ?>"
                     data-total-bills="<?php echo $doc['total_bills']; ?>"
                     data-net-total="<?php echo number_format($net, 2, '.', ''); ?>"
                     data-gross-total="<?php echo number_format($gross, 2, '.', ''); ?>"
                     data-professional="<?php echo number_format($professionalAfterDiscount, 2, '.', ''); ?>"
                     data-doctor-name="<?php echo htmlspecialchars(strtolower($doc['doctor_name'])); ?>"
                     data-doctor-title="Dr. <?php echo htmlspecialchars($doc['doctor_name'], ENT_QUOTES); ?>"
                     data-doctor-meta="<?php echo htmlspecialchars(trim(($doc['hospital_name'] ?? '') . (!empty($doc['city']) ? ' · ' . $doc['city'] : '')), ENT_QUOTES); ?>"
                     data-is-active="<?php echo $isActive ? '1' : '0'; ?>">
                <button class="doctor-card-toggle" type="button" aria-expanded="false" aria-controls="<?php echo $detailsId; ?>">
                    <header class="doctor-card-header">
                        <div>
                            <h2 class="doctor-name">Dr. <?php echo htmlspecialchars($doc['doctor_name']); ?></h2>
                            <?php if (!$isActive): ?>
                                <span class="status-badge status-inactive">Inactive</span>
                            <?php endif; ?>
                            <?php if (!empty($doc['hospital_name'])): ?>
                                <p class="doctor-meta"><?php echo htmlspecialchars($doc['hospital_name']); ?><?php if (!empty($doc['city'])): ?> · <?php echo htmlspecialchars($doc['city']); ?><?php endif; ?></p>
                            <?php elseif (!empty($doc['city'])): ?>
                                <p class="doctor-meta"><?php echo htmlspecialchars($doc['city']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="doctor-key-stats">
                            <span class="stat-block">
                                <span class="stat-label">Bills</span>
                                <span class="stat-value"><?php echo number_format($doc['total_bills']); ?></span>
                            </span>
                            <span class="stat-block">
                                <span class="stat-label">Tests</span>
                                <span class="stat-value"><?php echo number_format($doctorTotalTestCount); ?></span>
                            </span>
                            <span class="stat-block">
                                <span class="stat-label">Net</span>
                                <span class="stat-value"><?php echo format_inr($net); ?></span>
                            </span>
                            <span class="stat-block">
                                <span class="stat-label">Prof. Charges</span>
                                <span class="stat-value"><?php echo format_inr($professionalAfterDiscount); ?></span>
                            </span>
                        </div>
                    </header>
                    <div class="doctor-card-summary">
                        <div class="summary-item">
                            <span class="summary-label">Gross Revenue</span>
                            <span class="summary-value"><?php echo format_inr($gross); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Discounts</span>
                            <span class="summary-value"><?php echo format_inr($discount); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Payable After Discount</span>
                            <span class="summary-value"><?php echo format_inr($professionalAfterDiscount); ?></span>
                        </div>
                    </div>
                    <p class="summary-hint">Tap card to view detailed breakdown and test mix.</p>
                </button>
                <div class="doctor-card-details" id="<?php echo $detailsId; ?>" hidden>
                    <div class="details-grid">
                        <div class="detail-item">
                            <span class="detail-label">Bills Referred</span>
                            <span class="detail-value"><?php echo number_format($doc['total_bills']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Tests Ordered</span>
                            <span class="detail-value"><?php echo number_format($doctorTotalTestCount); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Gross Revenue</span>
                            <span class="detail-value"><?php echo format_inr($gross); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Discounts</span>
                            <span class="detail-value"><?php echo format_inr($discount); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Net Revenue</span>
                            <span class="detail-value"><?php echo format_inr($net); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Professional Charges</span>
                            <span class="detail-value"><?php echo format_inr($professionalOriginal); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Doctor Discount Absorbed</span>
                            <span class="detail-value"><?php echo format_inr($doctorDiscountAbsorbed); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Payable After Discount</span>
                            <span class="detail-value"><?php echo format_inr($professionalAfterDiscount); ?></span>
                        </div>
                    </div>
                    <?php if (!empty($testsForDoctor)): ?>
                        <div class="doctor-test-breakdown">
                            <h3 class="test-breakdown-title">Test mix for this period</h3>
                            <p class="test-breakdown-summary"><?php echo number_format($doctorTotalTestCount); ?> tests across <?php echo count($testsForDoctor); ?> categories.</p>
                            <div class="test-breakdown-groups">
                                <?php foreach ($testsForDoctor as $mainName => $mainData): ?>
                                    <?php
                                        $subtests = $mainData['subtests'] ?? [];
                                        if (!empty($subtests)) {
                                            ksort($subtests, SORT_NATURAL | SORT_FLAG_CASE);
                                        }
                                    ?>
                                    <article class="test-group">
                                        <header class="test-group-header">
                                            <span class="test-group-name"><?php echo htmlspecialchars($mainName); ?></span>
                                            <span class="test-group-total"><?php echo number_format($mainData['total']); ?> tests</span>
                                        </header>
                                        <?php if (!empty($subtests)): ?>
                                            <ul class="test-subtests">
                                                <?php foreach ($subtests as $subName => $subCount): ?>
                                                    <li>
                                                        <span class="subtest-name"><?php echo htmlspecialchars($subName); ?></span>
                                                        <span class="subtest-count"><?php echo number_format($subCount); ?></span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="test-breakdown-empty">No tests recorded for this doctor in the selected range.</p>
                    <?php endif; ?>
                    <div class="doctor-card-actions">
                        <a class="btn-outline" href="view_doctor_details.php?doctor_id=<?php echo $doc['id']; ?>">Doctor profile</a>
                        <a class="btn-outline" href="view_doctor_referrals.php?doctor_id=<?php echo $doc['id']; ?>">View referrals</a>
                    </div>
                </div>
                <label class="doctor-select-wrap" title="Select for multi-doctor popup">
                    <input class="doctor-select-checkbox" type="checkbox" aria-label="Select Dr. <?php echo htmlspecialchars($doc['doctor_name'], ENT_QUOTES); ?>">
                </label>
            </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="doctors-empty-state" id="doctor-empty-state">
                <h2>No referring doctors available</h2>
                <p>Doctors will appear here once bills are linked to them.</p>
            </div>
        <?php endif; ?>
    </section>

    <?php if (!empty($doctors)): ?>
    <div class="doctors-empty-state" id="doctor-empty-state" style="display: none;">
        <h2>No doctors match the current filters</h2>
        <p>Try clearing the search box or include inactive doctors.</p>
    </div>

    <div class="doctor-details-modal" id="doctor-details-modal" aria-hidden="true">
        <div class="doctor-details-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="doctor-modal-title">
            <div class="doctor-details-modal__header">
                <div>
                    <h2 class="doctor-details-modal__title" id="doctor-modal-title">Doctor details</h2>
                    <p class="doctor-details-modal__meta" id="doctor-modal-meta"></p>
                </div>
                <button type="button" class="doctor-details-modal__close" id="doctor-modal-close" aria-label="Close popup">×</button>
            </div>
            <div class="doctor-details-modal__content" id="doctor-modal-content"></div>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var cardsContainer = document.getElementById('doctor-cards-container');
    if (!cardsContainer) {
        return;
    }

    var searchInput = document.getElementById('doctor-search');
    var sortSelect = document.getElementById('doctor-sort');
    var showInactiveToggle = document.getElementById('show-inactive');
    var visibleCountEl = document.getElementById('doctor-visible-count');
    var activeVisibleCountEl = document.getElementById('doctor-visible-active-count');
    var inactiveVisibleCountEl = document.getElementById('doctor-visible-inactive-count');
    var totalCountEl = document.getElementById('doctor-total-count');
    var emptyStateEl = document.getElementById('doctor-empty-state');

    var numberFormatter = new Intl.NumberFormat('en-IN');
    var collator = new Intl.Collator('en', { sensitivity: 'base' });

    var getCards = function () {
        return Array.prototype.slice.call(cardsContainer.querySelectorAll('.doctor-card'));
    };

    var updateCounts = function (visibleCount, activeVisibleCount, inactiveVisibleCount) {
        if (visibleCountEl) {
            visibleCountEl.textContent = numberFormatter.format(visibleCount);
        }
        if (activeVisibleCountEl) {
            activeVisibleCountEl.textContent = numberFormatter.format(activeVisibleCount);
        }
        if (inactiveVisibleCountEl) {
            inactiveVisibleCountEl.textContent = numberFormatter.format(inactiveVisibleCount);
        }
        if (totalCountEl) {
            totalCountEl.textContent = numberFormatter.format(getCards().length);
        }
    };

    var applySort = function () {
        if (!sortSelect) {
            return;
        }

        var metric = sortSelect.value;
        var cards = getCards();

        var metricValue = function (card) {
            switch (metric) {
                case 'netTotal':
                    return Number(card.dataset.netTotal || 0);
                case 'grossTotal':
                    return Number(card.dataset.grossTotal || 0);
                case 'professional':
                    return Number(card.dataset.professional || 0);
                case 'doctorName':
                    return card.dataset.doctorName || '';
                default:
                    return Number(card.dataset.totalBills || 0);
            }
        };

        cards.sort(function (a, b) {
            if (metric === 'doctorName') {
                return collator.compare(metricValue(a), metricValue(b));
            }
            return metricValue(b) - metricValue(a);
        }).forEach(function (card) {
            cardsContainer.appendChild(card);
        });
    };

    var applyFilters = function () {
        var query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        var showInactive = showInactiveToggle ? showInactiveToggle.checked : true;

        var visibleCount = 0;
        var activeVisibleCount = 0;
        var inactiveVisibleCount = 0;

        getCards().forEach(function (card) {
            var matchesSearch = !query || (card.dataset.search || '').indexOf(query) !== -1;
            var isActive = card.dataset.isActive === '1';
            var passesStatus = showInactive || isActive;

            if (matchesSearch && passesStatus) {
                card.style.display = '';
                card.classList.remove('is-hidden');
                visibleCount += 1;
                if (isActive) {
                    activeVisibleCount += 1;
                } else {
                    inactiveVisibleCount += 1;
                }
            } else {
                card.style.display = 'none';
                card.classList.add('is-hidden');
            }
        });

        if (emptyStateEl) {
            emptyStateEl.style.display = visibleCount === 0 ? 'flex' : 'none';
        }

        updateCounts(visibleCount, activeVisibleCount, inactiveVisibleCount);
    };

    var refresh = function () {
        applySort();
        applyFilters();
    };

    refresh();

    if (searchInput) {
        searchInput.addEventListener('input', refresh);
    }
    if (sortSelect) {
        sortSelect.addEventListener('change', refresh);
    }
    if (showInactiveToggle) {
        showInactiveToggle.addEventListener('change', refresh);
    }

    var modal = document.getElementById('doctor-details-modal');
    var modalCloseBtn = document.getElementById('doctor-modal-close');
    var modalTitle = document.getElementById('doctor-modal-title');
    var modalMeta = document.getElementById('doctor-modal-meta');
    var modalContent = document.getElementById('doctor-modal-content');
    var modalDialog = modal ? modal.querySelector('.doctor-details-modal__dialog') : null;
    var modalMode = '';
    var isPointerOnModal = false;

    var setModalScrollMode = function (enabled) {
        if (!modalContent) {
            return;
        }

        if (enabled) {
            modalContent.classList.add('is-scroll-active');
        } else {
            modalContent.classList.remove('is-scroll-active');
        }
    };

    var closeModal = function () {
        if (!modal) {
            return;
        }

        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');

        if (modalContent) {
            modalContent.innerHTML = '';
        }

        modalMode = '';
        isPointerOnModal = false;
        setModalScrollMode(false);
    };

    var openModalForCard = function (card) {
        if (!card || !modal || !modalContent) {
            return;
        }

        var detailsId = card.querySelector('.doctor-card-toggle') ? card.querySelector('.doctor-card-toggle').getAttribute('aria-controls') : '';
        var details = detailsId ? document.getElementById(detailsId) : null;
        if (!details) {
            return;
        }

        var doctorNameEl = card.querySelector('.doctor-name');
        var doctorMetaEl = card.querySelector('.doctor-meta');

        if (modalTitle) {
            modalTitle.textContent = doctorNameEl ? doctorNameEl.textContent.trim() : 'Doctor details';
        }
        if (modalMeta) {
            modalMeta.textContent = doctorMetaEl ? doctorMetaEl.textContent.trim() : '';
        }

        var detailsClone = details.cloneNode(true);
        detailsClone.removeAttribute('id');
        detailsClone.removeAttribute('hidden');
        modalContent.innerHTML = detailsClone.innerHTML;

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        modalMode = 'single';
        setModalScrollMode(isPointerOnModal);
    };

    var openModalForMultiSelection = function (selectedCards) {
        if (!modal || !modalContent || !selectedCards || selectedCards.length <= 1) {
            return;
        }

        if (modalTitle) {
            modalTitle.textContent = 'Selected Doctors (' + selectedCards.length + ')';
        }
        if (modalMeta) {
            modalMeta.textContent = 'Combined details in one compact popup';
        }

        var combinedHtml = selectedCards.map(function (card) {
            var doctorTitle = card.dataset.doctorTitle || 'Doctor';
            var doctorMeta = card.dataset.doctorMeta || '';
            var detailsId = card.querySelector('.doctor-card-toggle') ? card.querySelector('.doctor-card-toggle').getAttribute('aria-controls') : '';
            var details = detailsId ? document.getElementById(detailsId) : null;
            if (!details) {
                return '';
            }

            var detailsClone = details.cloneNode(true);
            detailsClone.removeAttribute('id');
            detailsClone.removeAttribute('hidden');

            return '<section style="padding:0.75rem; border:1px solid #efd7e4; border-radius:10px; margin-bottom:0.85rem;">'
                + '<h3 style="margin:0 0 0.25rem; font-size:0.98rem; color:#1e293b;">' + doctorTitle + '</h3>'
                + (doctorMeta ? '<p style="margin:0 0 0.6rem; color:#64748b; font-size:0.84rem;">' + doctorMeta + '</p>' : '')
                + detailsClone.innerHTML
                + '</section>';
        }).join('');

        modalContent.innerHTML = combinedHtml || '<p>No data available for selected doctors.</p>';
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        modalMode = 'multi';
        setModalScrollMode(isPointerOnModal);
    };

    cardsContainer.addEventListener('click', function (event) {
        var checkbox = event.target.closest('.doctor-select-checkbox');
        if (checkbox) {
            event.stopPropagation();
            var selectedCards = getCards().filter(function (card) {
                var cb = card.querySelector('.doctor-select-checkbox');
                return cb && cb.checked;
            });

            if (selectedCards.length > 1) {
                openModalForMultiSelection(selectedCards);
            } else if (modalMode === 'multi') {
                closeModal();
            }
            return;
        }

        var card = event.target.closest('.doctor-card');
        if (!card) {
            return;
        }
        openModalForCard(card);
    });

    if (modalCloseBtn) {
        modalCloseBtn.addEventListener('click', closeModal);
    }

    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        modal.addEventListener('wheel', function (event) {
            if (!modal.classList.contains('is-open') || isPointerOnModal) {
                return;
            }

            // Route wheel scrolling to the page when pointer is outside popup.
            event.preventDefault();
            window.scrollBy(0, event.deltaY);
        }, { passive: false });
    }

    if (modalDialog) {
        modalDialog.addEventListener('mouseenter', function () {
            isPointerOnModal = true;
            setModalScrollMode(true);
        });

        modalDialog.addEventListener('mouseleave', function () {
            isPointerOnModal = false;
            setModalScrollMode(false);
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal && modal.classList.contains('is-open')) {
            closeModal();
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>

