<?php
$page_title = "Center Analysis";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';
require_once __DIR__ . '/components/center_analysis_data.php';

$sa_active_page = 'analysis.php';

$analysisMode = $analysisMode ?? 'daily';
if (!in_array($analysisMode, ['daily', 'monthly', 'yearly'], true)) {
    $analysisMode = 'daily';
}

$selectedDay = $_GET['day'] ?? date('Y-m-d');
$dayObj = DateTime::createFromFormat('Y-m-d', (string)$selectedDay);
if (!$dayObj || $dayObj->format('Y-m-d') !== (string)$selectedDay) {
    $selectedDay = date('Y-m-d');
}

$selectedMonth = $_GET['month'] ?? date('Y-m');
$monthObj = DateTime::createFromFormat('Y-m', (string)$selectedMonth);
if (!$monthObj || $monthObj->format('Y-m') !== (string)$selectedMonth) {
    $selectedMonth = date('Y-m');
}

$selectedYear = $_GET['year'] ?? date('Y');
if (!preg_match('/^\d{4}$/', (string)$selectedYear)) {
    $selectedYear = date('Y');
}

if ($analysisMode === 'daily') {
    $payload = sa_daily_payload($conn, $selectedDay);
    $title = 'Daily Center Analysis';
    $meta = 'Selected day: ' . $selectedDay;
} elseif ($analysisMode === 'monthly') {
    $payload = sa_monthly_payload($conn, $selectedMonth);
    $title = 'Monthly Center Analysis';
    $meta = 'Selected month: ' . $selectedMonth;
} else {
    $payload = sa_yearly_payload($conn, $selectedYear);
    $title = 'Yearly Center Analysis';
    $meta = 'Selected year: ' . $selectedYear;
}

$yearOptions = [];
$currentYear = (int)date('Y');
for ($y = $currentYear - 5; $y <= $currentYear + 1; $y++) {
    $yearOptions[] = (string)$y;
}
?>
<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-ca-wrap {
    display:grid;
    gap:1rem;
    font-family:'Sora',sans-serif;
    position:relative;
    padding-bottom:.25rem;
}
.sa-ca-wrap::before {
    content:'';
    position:absolute;
    inset:0;
    background:
        radial-gradient(circle at 100% 0%, rgba(37,99,235,.12), transparent 42%),
        radial-gradient(circle at 0% 100%, rgba(14,165,233,.12), transparent 38%);
    pointer-events:none;
    z-index:0;
}
.sa-ca-head, .sa-ca-picker, .sa-ca-card, .sa-ca-table, .sa-ca-kpi-strip {
    position:relative;
    z-index:1;
    background:rgba(255,255,255,.94);
    border:1px solid #e2e8f0;
    border-radius:16px;
    box-shadow:0 14px 28px rgba(15,23,42,.07);
    backdrop-filter:blur(4px);
}
.sa-ca-head { padding:1.05rem; background:linear-gradient(135deg,#0f2f78,#1744a1 45%,#0ea5e9); color:#fff; }
.sa-ca-head h1 { margin:0; font-size:1.55rem; }
.sa-ca-head p { margin:.3rem 0 0; color:#dbeafe; }
.sa-ca-nav { display:flex; gap:.5rem; flex-wrap:wrap; }
.sa-ca-nav a { border:1px solid #cbd5e1; border-radius:999px; padding:.46rem .9rem; text-decoration:none; color:#1e3a8a; font-weight:700; background:#fff; box-shadow:0 2px 8px rgba(15,23,42,.05); }
.sa-ca-nav a.active { background:#1e3a8a; color:#fff; border-color:#1e3a8a; }
.sa-ca-picker { padding:.75rem; display:grid; gap:.35rem; width:fit-content; min-width:240px; }
.sa-ca-picker label { color:#64748b; font-size:.76rem; font-weight:700; text-transform:uppercase; }
.sa-ca-picker input, .sa-ca-picker select { border:1px solid #cbd5e1; border-radius:8px; padding:.4rem .5rem; }
.sa-ca-meta { color:#475569; font-size:.85rem; }
.sa-ca-kpi-strip {
    padding:.55rem .75rem;
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:.5rem;
}
.sa-ca-kpi-chip {
    background:#f8fbff;
    border:1px solid #dbeafe;
    border-radius:10px;
    padding:.52rem .55rem;
}
.sa-ca-kpi-chip .k { color:#1d4ed8; font-size:.69rem; font-weight:700; text-transform:uppercase; }
.sa-ca-kpi-chip .v { color:#0f172a; margin-top:.18rem; font-size:.96rem; font-weight:700; }
.sa-ca-charts { display:grid; grid-template-columns:1fr 1fr; gap:.8rem; }
.sa-ca-card { padding:.95rem; }
.sa-ca-card h3 { margin:0 0 .35rem; color:#0f2f78; font-size:1rem; }
.sa-ca-card p { margin:0 0 .65rem; color:#64748b; font-size:.83rem; }
.sa-ca-card canvas { width:100% !important; height:320px !important; }
.sa-ca-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.55rem; margin-bottom:.65rem; }
.sa-ca-stat { border:1px solid #dbeafe; border-radius:10px; background:#f8fbff; padding:.55rem; }
.sa-ca-stat .k { color:#1e3a8a; font-size:.7rem; font-weight:700; text-transform:uppercase; }
.sa-ca-stat .v { color:#0f172a; margin-top:.2rem; font-size:1rem; font-weight:700; }

.sa-ca-table { padding:.85rem; overflow:hidden; }
.sa-ca-table h3 { margin:0 0 .2rem; color:#0f2f78; }
.sa-ca-table p { margin:0 0 .7rem; color:#64748b; font-size:.84rem; }
.sa-ca-table-wrap { overflow:auto; }
.sa-ca-table table { width:100%; border-collapse:collapse; }
.sa-ca-table th, .sa-ca-table td { padding:.62rem .55rem; border-bottom:1px solid #e2e8f0; text-align:left; white-space:nowrap; }
.sa-ca-table th { font-size:.78rem; color:#334155; text-transform:uppercase; letter-spacing:.02em; }
.sa-ca-table tbody tr:nth-child(even) { background:#fbfdff; }
.sa-ca-table tr.clickable { cursor:pointer; }
.sa-ca-table tr.clickable:hover { background:#f8fbff; }

.sa-ca-modal { position:fixed; inset:0; background:rgba(15,23,42,.55); display:none; align-items:center; justify-content:center; padding:1rem; z-index:1060; }
.sa-ca-modal.open { display:flex; }
.sa-ca-modal-card { width:min(920px,96vw); max-height:86vh; overflow:hidden; background:#fff; border-radius:14px; box-shadow:0 18px 35px rgba(2,6,23,.38); display:grid; grid-template-rows:auto 1fr; }
.sa-ca-modal-head { display:flex; justify-content:space-between; align-items:center; padding:.85rem 1rem; border-bottom:1px solid #e2e8f0; }
.sa-ca-modal-head h4 { margin:0; color:#0f172a; font-size:1.03rem; }
.sa-ca-modal-close { border:0; background:#e2e8f0; color:#0f172a; width:32px; height:32px; border-radius:50%; cursor:pointer; font-weight:700; }
.sa-ca-modal-body { padding:.8rem 1rem 1rem; overflow:auto; }
.sa-ca-modal table { width:100%; border-collapse:collapse; }
.sa-ca-modal th, .sa-ca-modal td { border-bottom:1px solid #e2e8f0; padding:.55rem .45rem; text-align:left; }
.sa-ca-proof-link { color:#1d4ed8; text-decoration:none; font-weight:700; }

@media (max-width:1100px){
    .sa-ca-charts{grid-template-columns:1fr;}
    .sa-ca-kpi-strip{grid-template-columns:repeat(2,minmax(0,1fr));}
    .sa-ca-stats{grid-template-columns:repeat(2,minmax(0,1fr));}
}
@media (max-width:700px){
    .sa-ca-kpi-strip{grid-template-columns:1fr;}
    .sa-ca-stats{grid-template-columns:1fr;}
}
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>
<section class="sa-ca-wrap">
    <header class="sa-ca-head">
        <h1><?php echo htmlspecialchars($title); ?></h1>
        <p>Focused layout with two chart sectors and detailed expense drilldown.</p>
    </header>

    <div class="sa-ca-nav">
        <a class="<?php echo $analysisMode === 'daily' ? 'active' : ''; ?>" href="analysis_daily.php?day=<?php echo urlencode($selectedDay); ?>&month=<?php echo urlencode($selectedMonth); ?>&year=<?php echo urlencode($selectedYear); ?>">Daily</a>
        <a class="<?php echo $analysisMode === 'monthly' ? 'active' : ''; ?>" href="analysis_monthly.php?day=<?php echo urlencode($selectedDay); ?>&month=<?php echo urlencode($selectedMonth); ?>&year=<?php echo urlencode($selectedYear); ?>">Monthly</a>
        <a class="<?php echo $analysisMode === 'yearly' ? 'active' : ''; ?>" href="analysis_yearly.php?day=<?php echo urlencode($selectedDay); ?>&month=<?php echo urlencode($selectedMonth); ?>&year=<?php echo urlencode($selectedYear); ?>">Yearly</a>
    </div>

    <form id="pickerForm" class="sa-ca-picker" method="GET" action="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF'])); ?>">
        <?php if ($analysisMode === 'daily'): ?>
            <label for="day">Select Day</label>
            <input type="date" id="day" name="day" value="<?php echo htmlspecialchars($selectedDay); ?>">
            <input type="hidden" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
            <input type="hidden" name="year" value="<?php echo htmlspecialchars($selectedYear); ?>">
        <?php elseif ($analysisMode === 'monthly'): ?>
            <label for="month">Select Month</label>
            <input type="month" id="month" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
            <input type="hidden" name="day" value="<?php echo htmlspecialchars($selectedDay); ?>">
            <input type="hidden" name="year" value="<?php echo htmlspecialchars($selectedYear); ?>">
        <?php else: ?>
            <label for="year">Select Year</label>
            <select id="year" name="year">
                <?php foreach ($yearOptions as $optionYear): ?>
                    <option value="<?php echo htmlspecialchars($optionYear); ?>" <?php echo $selectedYear === $optionYear ? 'selected' : ''; ?>><?php echo htmlspecialchars($optionYear); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="day" value="<?php echo htmlspecialchars($selectedDay); ?>">
            <input type="hidden" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
        <?php endif; ?>
    </form>

    <div class="sa-ca-meta"><?php echo htmlspecialchars($meta); ?> | Data window: <?php echo htmlspecialchars($payload['from']); ?> to <?php echo htmlspecialchars($payload['to']); ?></div>

    <section class="sa-ca-kpi-strip" id="kpiStrip"></section>

    <section class="sa-ca-charts">
        <article class="sa-ca-card">
            <h3>Revenue vs Expenses</h3>
            <p>Clear financial trend view for the selected period.</p>
            <div class="sa-ca-stats" id="stats"></div>
            <canvas id="finance"></canvas>
        </article>
        <article class="sa-ca-card">
            <h3>Growth Metrics Pareto</h3>
            <p>Per test comparison: Revenue, Discounts and No. of Tests.</p>
            <canvas id="growthPareto"></canvas>
        </article>
    </section>

    <section class="sa-ca-table">
        <h3>Expenses Table</h3>
        <p>Click any row to view sub info in a popup.</p>
        <div class="sa-ca-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Expense Type</th>
                        <th>Total Amount</th>
                        <th>Entries</th>
                    </tr>
                </thead>
                <tbody id="expenseRows"></tbody>
            </table>
        </div>
    </section>
</section>

<div id="expenseModal" class="sa-ca-modal" aria-hidden="true">
    <div class="sa-ca-modal-card" role="dialog" aria-modal="true" aria-labelledby="expenseModalTitle">
        <div class="sa-ca-modal-head">
            <h4 id="expenseModalTitle">Expense Details</h4>
            <button id="expenseModalClose" class="sa-ca-modal-close" type="button">x</button>
        </div>
        <div class="sa-ca-modal-body">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Accountant</th>
                        <th>Proof</th>
                    </tr>
                </thead>
                <tbody id="expenseModalRows"></tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    const payload = <?php echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const form = document.getElementById('pickerForm');
    const picker = form ? form.querySelector('input,select') : null;
    const modal = document.getElementById('expenseModal');
    const modalRows = document.getElementById('expenseModalRows');
    const modalTitle = document.getElementById('expenseModalTitle');

    function inr(v) {
        return 'Rs. ' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function num(v) {
        return Number(v || 0).toLocaleString('en-IN');
    }

    function escHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    const k = payload.kpis || {};
    const kpiStripItems = [
        ['Net Position', inr(k.net || 0)],
        ['Tests', num(k.tests || 0)],
        ['Patients', num(k.patients || 0)],
        ['Bills', num(k.bills || 0)]
    ];
    document.getElementById('kpiStrip').innerHTML = kpiStripItems
        .map(x => '<article class="sa-ca-kpi-chip"><div class="k">' + x[0] + '</div><div class="v">' + x[1] + '</div></article>')
        .join('');

    const statCards = [
        ['Revenue', inr(k.revenue || 0)],
        ['Expenses', inr(k.expenses || 0)],
        ['Discounts', inr(k.discounts || 0)],
        ['Revenue / Test', inr(k.revenue_per_test || 0)]
    ];
    document.getElementById('stats').innerHTML = statCards
        .map(x => '<article class="sa-ca-stat"><div class="k">' + x[0] + '</div><div class="v">' + x[1] + '</div></article>')
        .join('');

    const financeCtx = document.getElementById('finance').getContext('2d');
    const revGradient = financeCtx.createLinearGradient(0, 0, 0, 320);
    revGradient.addColorStop(0, 'rgba(37,99,235,0.28)');
    revGradient.addColorStop(1, 'rgba(37,99,235,0.03)');
    const expGradient = financeCtx.createLinearGradient(0, 0, 0, 320);
    expGradient.addColorStop(0, 'rgba(220,38,38,0.23)');
    expGradient.addColorStop(1, 'rgba(220,38,38,0.03)');

    new Chart(financeCtx, {
        type:'line',
        data:{ labels:payload.labels||[], datasets:[
            { label:'Revenue', data:payload.revenue||[], borderColor:'#1d4ed8', backgroundColor:revGradient, borderWidth:2.4, fill:true, tension:0.32, pointRadius:2, pointHoverRadius:4 },
            { label:'Expenses', data:payload.expenses||[], borderColor:'#dc2626', backgroundColor:expGradient, borderWidth:2.2, fill:true, tension:0.32, pointRadius:2, pointHoverRadius:4 }
        ]},
        options:{
            responsive:true,
            maintainAspectRatio:false,
            plugins:{
                legend:{ position:'bottom' },
                tooltip:{ callbacks:{ label: ctx => ctx.dataset.label + ': ' + inr(ctx.raw) } }
            },
            scales:{ y:{ ticks:{ callback: value => 'Rs. ' + Number(value).toLocaleString('en-IN') } } }
        }
    });

    const testLabels = payload.testLabels || [];
    const testRevenue = payload.testRevenue || [];
    const testDiscounts = payload.testDiscounts || [];
    const testCounts = payload.testCounts || [];

    const revenueSum = testRevenue.reduce((acc, cur) => acc + Number(cur || 0), 0);
    let runningRevenue = 0;
    const cumulativeRevenuePercent = testRevenue.map(function (val) {
        runningRevenue += Number(val || 0);
        if (revenueSum <= 0) {
            return 0;
        }
        return Number(((runningRevenue / revenueSum) * 100).toFixed(2));
    });

    const growthCtx = document.getElementById('growthPareto').getContext('2d');
    if (testLabels.length === 0) {
        growthCtx.font = '600 14px Sora';
        growthCtx.fillStyle = '#64748b';
        growthCtx.fillText('No test growth data available for this period.', 16, 28);
    } else {
        new Chart(growthCtx, {
            data: {
                labels: testLabels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Revenue',
                        data: testRevenue,
                        backgroundColor: '#2563eb',
                        borderRadius: 5,
                        yAxisID: 'yMoney'
                    },
                    {
                        type: 'bar',
                        label: 'Discounts',
                        data: testDiscounts,
                        backgroundColor: '#f97316',
                        borderRadius: 5,
                        yAxisID: 'yMoney'
                    },
                    {
                        type: 'line',
                        label: 'No. of Tests',
                        data: testCounts,
                        borderColor: '#059669',
                        backgroundColor: '#059669',
                        tension: 0.28,
                        pointRadius: 2,
                        yAxisID: 'yTests'
                    },
                    {
                        type: 'line',
                        label: 'Pareto Cumulative % (Revenue)',
                        data: cumulativeRevenuePercent,
                        borderColor: '#9333ea',
                        backgroundColor: '#9333ea',
                        borderDash: [6, 4],
                        tension: 0.2,
                        pointRadius: 2,
                        yAxisID: 'yPercent'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            afterBody: function (items) {
                                const idx = items && items.length ? items[0].dataIndex : -1;
                                if (idx < 0) {
                                    return '';
                                }
                                const rpt = Number(testCounts[idx] || 0) > 0 ? (Number(testRevenue[idx] || 0) / Number(testCounts[idx] || 0)) : 0;
                                return [
                                    'Revenue/Test: ' + inr(rpt)
                                ];
                            }
                        }
                    }
                },
                scales: {
                    yMoney: {
                        type: 'linear',
                        position: 'left',
                        title: { display: true, text: 'Amount' },
                        ticks: { callback: value => 'Rs. ' + Number(value).toLocaleString('en-IN') }
                    },
                    yTests: {
                        type: 'linear',
                        position: 'right',
                        title: { display: true, text: 'No. of Tests' },
                        grid: { drawOnChartArea: false },
                        beginAtZero: true
                    },
                    yPercent: {
                        type: 'linear',
                        position: 'right',
                        min: 0,
                        max: 100,
                        title: { display: true, text: 'Pareto %' },
                        grid: { drawOnChartArea: false },
                        ticks: { callback: value => Number(value).toFixed(0) + '%' }
                    }
                }
            }
        });
    }

    const expenseRows = document.getElementById('expenseRows');
    const expenseTypes = payload.expenseTypes || [];
    const expenseDetailMap = payload.expenseDetailsByType || {};

    if (!expenseTypes.length) {
        expenseRows.innerHTML = '<tr><td colspan="4">No expense records found for this period.</td></tr>';
    } else {
        expenseRows.innerHTML = expenseTypes.map(function (row, idx) {
            const typeEsc = String(row.type || '');
            const safeType = escHtml(typeEsc);
            return '<tr class="clickable" data-expense-type="' + safeType + '">'
                + '<td>' + (idx + 1) + '</td>'
                + '<td>' + safeType + '</td>'
                + '<td>' + inr(row.total || 0) + '</td>'
                + '<td>' + num(row.count || 0) + '</td>'
                + '</tr>';
        }).join('');
    }

    function openExpenseModal(expenseType) {
        if (!modal || !modalRows || !modalTitle) {
            return;
        }
        const rows = expenseDetailMap[expenseType] || [];
        modalTitle.textContent = expenseType + ' - Sub Info';

        if (!rows.length) {
            modalRows.innerHTML = '<tr><td colspan="5">No sub info available.</td></tr>';
        } else {
            modalRows.innerHTML = rows.map(function (r) {
                const proofPath = String(r.proof_path || '');
                const proofCell = proofPath
                    ? '<a class="sa-ca-proof-link" href="' + encodeURI(proofPath) + '" target="_blank" rel="noopener">View</a>'
                    : '-';
                return '<tr>'
                    + '<td>' + escHtml(String(r.date || '-')) + '</td>'
                    + '<td>' + inr(r.amount || 0) + '</td>'
                    + '<td>' + escHtml(String(r.status || '-')) + '</td>'
                    + '<td>' + escHtml(String(r.accountant || '-')) + '</td>'
                    + '<td>' + proofCell + '</td>'
                    + '</tr>';
            }).join('');
        }

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeExpenseModal() {
        if (!modal) {
            return;
        }
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.getElementById('expenseRows').addEventListener('click', function (event) {
        const tr = event.target.closest('tr[data-expense-type]');
        if (!tr) {
            return;
        }
        openExpenseModal(tr.getAttribute('data-expense-type') || 'Expense');
    });

    const closeBtn = document.getElementById('expenseModalClose');
    if (closeBtn) {
        closeBtn.addEventListener('click', closeExpenseModal);
    }

    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeExpenseModal();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal && modal.classList.contains('open')) {
            closeExpenseModal();
        }
    });

    if (picker && form) {
        picker.addEventListener('change', function () { form.submit(); });
    }
})();
</script>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>
