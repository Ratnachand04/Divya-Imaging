<?php
$page_title = "Accountant Dashboard";
$required_role = "accountant";
require_once '../includes/auth_check.php';
require_once '../includes/header.php';
?>


<div class="main-content page-container">
    <div class="dashboard-header">
        <div>
            <h1>Accountant's Dashboard</h1>
            <p>Financial overview and key performance indicators for the selected period.</p>
        </div>
    </div>

    <form id="date-filter-form" class="filter-form compact-filters">
        <div class="filter-group">
            <div class="form-group">
                <label>Quick Dates</label>
                <div class="quick-date-pills">
                    <button type="button" class="btn-action active" data-range="today">Today</button>
                    <button type="button" class="btn-action" data-range="week">This Week</button>
                    <button type="button" class="btn-action" data-range="month">This Month</button>
                    <button type="button" class="btn-action" data-range="last_month">Last Month</button>
                </div>
            </div>
            <div class="form-group">
                <label for="start_date">Start</label>
                <input type="date" id="start_date" name="start_date" value="<?php echo date('Y-m-d'); ?>" style="color: #000 !important;">
            </div>
            <div class="form-group">
                <label for="end_date">End</label>
                <input type="date" id="end_date" name="end_date" value="<?php echo date('Y-m-d'); ?>" style="color: #000 !important;">
            </div>
        </div>
        <div class="filter-actions">
            <a href="dashboard.php" class="btn-cancel">Reset</a>
            <button type="submit" class="btn-submit">Apply</button>
        </div>
    </form>


    <div class="summary-cards">
        <div class="summary-card clickable-card" data-url="manage_payments.php">
            <h3>Total Earnings</h3>
            <p id="kpi-total-earnings">₹ 0.00</p>
        </div>
        <div class="summary-card clickable-card" data-url="discount_report.php">
            <h3>Total Discounts Given</h3>
            <p id="kpi-total-discounts">₹ 0.00</p>
        </div>
        <div class="summary-card clickable-card" data-url="doctor_payouts.php">
            <h3>Total Payouts</h3>
            <p id="kpi-total-payouts">₹ 0.00</p>
        </div>
        <div class="summary-card clickable-card" data-url="doctor_payouts.php">
            <h3>Pending Payouts</h3>
            <p id="kpi-pending-payouts">₹ 0.00</p>
        </div>
        <div class="summary-card">
            <h3>Package Revenue</h3>
            <p id="kpi-package-revenue">₹ 0.00</p>
        </div>
        <div class="summary-card">
            <h3>Package Sales Count</h3>
            <p id="kpi-package-sales">0</p>
        </div>
        <div class="summary-card">
            <h3>Package Discount Impact</h3>
            <p id="kpi-package-discount-impact">₹ 0.00</p>
        </div>
    </div>

    <div class="insights-grid">
        <div class="insight-card span-2" id="cashflow-card">
            <h3>Cashflow Pulse</h3>
            <div class="inline-metric-grid">
                <div class="inline-metric highlight">
                    <span class="label">Collections Today</span>
                    <span class="value" id="cashflow-collections-today">₹ 0.00</span>
                </div>
                <div class="inline-metric">
                    <span class="label">Expenses Paid</span>
                    <span class="value" id="cashflow-expenses-today">₹ 0.00</span>
                </div>
                <div class="inline-metric">
                    <span class="label">Doctor Payouts</span>
                    <span class="value" id="cashflow-payouts-today">₹ 0.00</span>
                </div>
                <div class="inline-metric">
                    <span class="label">Net Position</span>
                    <span class="value" id="cashflow-net-today">₹ 0.00</span>
                </div>
            </div>
            <div class="cashflow-ribbon">
                <div class="meta">
                    <span class="label">Yesterday Net</span>
                    <span class="value" id="cashflow-net-yesterday">₹ 0.00</span>
                </div>
                <span class="delta" id="cashflow-day-delta">+ ₹ 0.00 vs yesterday</span>
            </div>
        </div>
        <div class="insight-card" id="receivables-card">
            <h3>Receivables Aging</h3>
            <table class="mini-table">
                <thead>
                    <tr><th>Bucket</th><th>Bills</th><th>Outstanding</th></tr>
                </thead>
                <tbody id="aging-table-body"></tbody>
            </table>
            <div class="insight-footer">
                <span>Total Due</span>
                <strong id="aging-total-due">₹ 0.00</strong>
            </div>
        </div>
        <div class="insight-card" id="doctor-settlement-card">
            <h3>Doctor Settlement Snapshot</h3>
            <div class="inline-metric-grid">
                <div class="inline-metric"><span class="label">Total Payable</span><span class="value" id="doctor-total-payable">₹ 0.00</span></div>
                <div class="inline-metric"><span class="label">Settled</span><span class="value" id="doctor-total-settled">₹ 0.00</span></div>
                <div class="inline-metric"><span class="label">Pending</span><span class="value" id="doctor-total-pending">₹ 0.00</span></div>
                <div class="inline-metric"><span class="label">Overdue</span><span class="value" id="doctor-total-overdue">₹ 0.00</span></div>
            </div>
            <h4>Needs Attention</h4>
            <ul class="list-reset" id="doctor-pending-list"></ul>
        </div>
        <div class="insight-card span-2" id="expense-anomalies-card">
            <h3>Expense Anomalies</h3>
            <table class="mini-table">
                <thead>
                    <tr><th>Expense Type</th><th>Current</th><th>Previous</th><th>Δ Amount</th><th>Δ %</th></tr>
                </thead>
                <tbody id="expense-anomalies-body"></tbody>
            </table>
        </div>
        <div class="insight-card span-2" id="profitability-card">
            <h3>Top Test Profitability</h3>
            <table class="mini-table">
                <thead>
                    <tr><th>Test Category</th><th>Tests</th><th>Gross</th><th>Discount</th><th>Doctor Payout</th><th>Net Margin</th></tr>
                </thead>
                <tbody id="test-profitability-body"></tbody>
            </table>
        </div>
        <div class="insight-card" id="pending-expenses-card">
            <h3>Pending Expense Queue</h3>
            <ul class="list-reset" id="pending-expense-list"></ul>
        </div>
        <div class="insight-card" id="receivables-watchlist-card">
            <h3>Receivables Watchlist</h3>
            <ul class="list-reset" id="receivables-watchlist"></ul>
        </div>
    </div>

    <div class="charts-section">
        <div class="chart-container span-2">
            <h3>Collections Efficiency Trend</h3>
            <canvas id="collectionsTrendChart"></canvas>
        </div>
        <div class="chart-container span-2">
            <h3>Revenue vs. Expenses (Last 6 Months)</h3>
            <canvas id="revenueVsExpensesChart"></canvas>
        </div>
        <div class="chart-container">
            <h3>Expense Breakdown</h3>
            <canvas id="expenseBreakdownChart"></canvas>
        </div>
        <div class="chart-container">
            <h3>Revenue by Payment Method</h3>
            <canvas id="paymentModeChart"></canvas>
        </div>
        <div class="chart-container">
            <h3>Top 5 Doctor Payouts Due</h3>
            <canvas id="topDoctorsPayoutsChart"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    let charts = {};

    if (window.ChartDataLabels) {
        Chart.register(ChartDataLabels);
    }

    const formatCurrency = (value) => {
        const numericValue = Number(value ?? 0);
        return `₹ ${numericValue.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    const formatNumber = value => Number(value ?? 0).toLocaleString('en-IN');
    const formatDate = value => {
        if (!value) return '—';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' });
    };
    const palette = ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#fd7e14', '#20c997'];

    const pieLabelFormatter = (value, context) => {
        const numeric = Number(value ?? 0);
        const values = context?.dataset?.data || [];
        const total = values.reduce((sum, item) => sum + Number(item ?? 0), 0);
        if (total <= 0) {
            return formatCurrency(numeric);
        }
        const percent = (numeric / total) * 100;
        return `${formatCurrency(numeric)} (${percent.toFixed(1)}%)`;
    };

    const chartConfigs = {
        doughnut: ({ labels, values }) => ({
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data: values,
                    backgroundColor: values.map((_, idx) => palette[idx % palette.length]),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: 12 },
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { usePointStyle: true, padding: 18, font: { size: 12 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: ctx => `${ctx.label}: ${formatCurrency(ctx.parsed ?? 0)}`
                        }
                    },
                    datalabels: {
                        display: context => Number(context.dataset.data?.[context.dataIndex] ?? 0) > 0,
                        formatter: pieLabelFormatter,
                        color: '#0f172a',
                        font: { weight: '600', size: 10 },
                        anchor: 'end',
                        align: 'end',
                        offset: 6,
                        clamp: true
                    }
                }
            }
        }),
        pie: ({ labels, values }) => ({
            type: 'pie',
            data: {
                labels,
                datasets: [{
                    data: values,
                    backgroundColor: values.map((_, idx) => palette[idx % palette.length]),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: 12 },
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { usePointStyle: true, padding: 18, font: { size: 12 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: ctx => `${ctx.label}: ${formatCurrency(ctx.parsed ?? 0)}`
                        }
                    },
                    datalabels: {
                        display: context => Number(context.dataset.data?.[context.dataIndex] ?? 0) > 0,
                        formatter: pieLabelFormatter,
                        color: '#0f172a',
                        font: { weight: '600', size: 10 },
                        anchor: 'end',
                        align: 'end',
                        offset: 6,
                        clamp: true
                    }
                }
            }
        }),
        bar: ({ labels, values, label = '', color = '#4e73df', formatter = formatCurrency, indexAxis = 'x' }) => {
            const isHorizontal = indexAxis === 'y';
            return {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label,
                        data: values,
                        backgroundColor: color,
                        borderRadius: 6,
                        maxBarThickness: 42
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis,
                    layout: { padding: { top: 14, right: 16, bottom: 14, left: 16 } },
                    scales: isHorizontal
                        ? {
                            x: {
                                beginAtZero: true,
                                ticks: { callback: value => formatter(value), font: { size: 11 } },
                                grid: { color: '#e5e7eb' }
                            },
                            y: {
                                ticks: { autoSkip: false, font: { size: 11 } },
                                grid: { display: false }
                            }
                        }
                        : {
                            x: {
                                ticks: { autoSkip: true, maxTicksLimit: 8, maxRotation: 0, font: { size: 11 } },
                                grid: { display: false }
                            },
                            y: {
                                beginAtZero: true,
                                ticks: { callback: value => formatter(value), font: { size: 11 } },
                                grid: { color: '#e5e7eb' }
                            }
                        },
                    plugins: {
                        legend: {
                            display: Boolean(label),
                            position: 'top',
                            labels: { boxWidth: 12, font: { size: 11 } }
                        },
                        tooltip: {
                            callbacks: {
                                label: ctx => {
                                    const rawValue = isHorizontal ? (ctx.parsed?.x ?? 0) : (ctx.parsed?.y ?? 0);
                                    const prefix = ctx.dataset.label ? `${ctx.dataset.label}: ` : '';
                                    return `${prefix}${formatter(rawValue)}`;
                                }
                            }
                        },
                        datalabels: {
                            display: context => Number(context.dataset.data?.[context.dataIndex] ?? 0) > 0,
                            formatter: value => formatter(value),
                            color: '#0f172a',
                            font: { weight: '600', size: 10 },
                            anchor: isHorizontal ? 'end' : 'end',
                            align: isHorizontal ? 'right' : 'top',
                            offset: 4,
                            clamp: true,
                            clip: false
                        }
                    }
                }
            };
        },
        multiBar: ({ labels, revenue, expenses }) => ({
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Revenue',
                        data: revenue,
                        backgroundColor: '#1cc88a',
                        borderRadius: 6,
                        maxBarThickness: 42
                    },
                    {
                        label: 'Expenses',
                        data: expenses,
                        backgroundColor: '#e74a3b',
                        borderRadius: 6,
                        maxBarThickness: 42
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 16, right: 20, bottom: 16, left: 20 } },
                scales: {
                    x: {
                        ticks: { autoSkip: true, maxTicksLimit: 12, maxRotation: 0, font: { size: 11 } },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { callback: value => formatCurrency(value), font: { size: 11 } },
                        grid: { color: '#e5e7eb' }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { boxWidth: 12, font: { size: 11 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: ctx => `${ctx.dataset.label}: ${formatCurrency(ctx.parsed?.y ?? 0)}`
                        }
                    },
                    datalabels: {
                        display: context => Number(context.dataset.data?.[context.dataIndex] ?? 0) > 0,
                        formatter: value => formatCurrency(value),
                        color: '#0f172a',
                        font: { weight: '600', size: 10 },
                        anchor: 'end',
                        align: 'top',
                        offset: 4,
                        clamp: true
                    }
                }
            }
        }),
        collectionsTrend: ({ labels = [], billed = [], collected = [], collection_rate = [] }) => ({
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Billed',
                        data: billed,
                        backgroundColor: '#6366f1',
                        borderRadius: 6,
                        maxBarThickness: 40,
                        yAxisID: 'y'
                    },
                    {
                        type: 'bar',
                        label: 'Collected',
                        data: collected,
                        backgroundColor: '#10b981',
                        borderRadius: 6,
                        maxBarThickness: 40,
                        yAxisID: 'y'
                    },
                    {
                        type: 'line',
                        label: 'Collection %',
                        data: collection_rate.map(value => value === null ? null : Number(value)),
                        borderColor: '#f97316',
                        backgroundColor: '#f97316',
                        borderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 5,
                        tension: 0.35,
                        yAxisID: 'y1',
                        spanGaps: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 16, right: 20, bottom: 16, left: 20 } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: value => formatCurrency(value), font: { size: 11 } },
                        grid: { color: '#e5e7eb' }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        ticks: {
                            callback: value => (value == null || Number.isNaN(value) ? '' : `${value}%`),
                            font: { size: 11 }
                        },
                        grid: { drawOnChartArea: false }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { boxWidth: 12, font: { size: 11 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                if (ctx.dataset.yAxisID === 'y1') {
                                    const percent = ctx.parsed?.y;
                                    if (percent == null || Number.isNaN(percent)) {
                                        return `${ctx.dataset.label}: —`;
                                    }
                                    return `${ctx.dataset.label}: ${percent}%`;
                                }
                                return `${ctx.dataset.label}: ${formatCurrency(ctx.parsed?.y ?? 0)}`;
                            }
                        }
                    },
                    datalabels: {
                        display: context => {
                            const value = Number(context.dataset.data?.[context.dataIndex] ?? 0);
                            return Number.isFinite(value) && value > 0;
                        },
                        formatter: (value, context) => {
                            if (context.dataset.yAxisID === 'y1') {
                                return `${Number(value ?? 0).toFixed(1)}%`;
                            }
                            return formatCurrency(value);
                        },
                        color: context => context.dataset.yAxisID === 'y1' ? '#b45309' : '#0f172a',
                        font: { weight: '600', size: 10 },
                        anchor: 'end',
                        align: 'top',
                        offset: 4,
                        clamp: true,
                        clip: false
                    }
                }
            }
        })
    };

    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) { el.textContent = value; }
    };

    function renderReceivables(aging) {
        const tbody = document.getElementById('aging-table-body');
        const totalEl = document.getElementById('aging-total-due');
        if (!tbody || !totalEl) return;

        tbody.innerHTML = '';
        const buckets = Array.isArray(aging?.buckets) ? aging.buckets : [];
        const totalDue = aging?.total_due ?? 0;
        if (!buckets.length || totalDue <= 0) {
            tbody.innerHTML = '<tr><td colspan="3">No pending receivables 🎉</td></tr>';
        } else {
            buckets.forEach(bucket => {
                if (!bucket) return;
                const row = document.createElement('tr');
                row.innerHTML = `<td>${bucket.label}</td><td>${formatNumber(bucket.count || 0)}</td><td>${formatCurrency(bucket.amount || 0)}</td>`;
                tbody.appendChild(row);
            });
        }
        totalEl.textContent = formatCurrency(totalDue);
    }

    function renderCashflowPulse(pulse) {
        const data = pulse || {};
        const today = data.today || {};
        const yesterday = data.yesterday || {};
        setText('cashflow-collections-today', formatCurrency(today.collections));
        setText('cashflow-expenses-today', formatCurrency(today.expenses));
        setText('cashflow-payouts-today', formatCurrency(today.payouts));
        setText('cashflow-net-today', formatCurrency(today.net));
        setText('cashflow-net-yesterday', formatCurrency(yesterday.net));

        const deltaEl = document.getElementById('cashflow-day-delta');
        if (deltaEl) {
            const change = data.change ?? 0;
            const absChange = Math.abs(change);
            const deltaText = `${change >= 0 ? '+' : '-'} ${formatCurrency(absChange)} vs yesterday`;
            deltaEl.textContent = deltaText;
            deltaEl.classList.toggle('positive', change >= 0);
            deltaEl.classList.toggle('negative', change < 0);
        }
    }

    function renderPendingExpenses(rows) {
        const list = document.getElementById('pending-expense-list');
        if (!list) return;
        list.innerHTML = '';
        const data = Array.isArray(rows) ? rows : [];
        if (!data.length) {
            const li = document.createElement('li');
            li.innerHTML = '<span>No pending expenses awaiting approval.</span>';
            list.appendChild(li);
            return;
        }

        data.forEach(item => {
            const li = document.createElement('li');
            const status = (item.status || 'Pending').toLowerCase();
            let tagClass = 'warning';
            if (status === 'approved' || status === 'paid') tagClass = 'success';
            if (status === 'rejected') tagClass = 'danger';
            const statusLabel = (item.status || 'Pending').replace(/_/g, ' ');
            li.innerHTML = `
                <div>
                    <strong>${item.expense_type || 'General Expense'}</strong>
                    <span>Raised ${formatDate(item.created_at)}</span>
                </div>
                <div class="amounts">
                    <span>${formatCurrency(item.amount)}</span>
                    <span class="tag ${tagClass}">${statusLabel}</span>
                </div>
            `;
            list.appendChild(li);
        });
    }

    function renderReceivablesWatchlist(rows) {
        const list = document.getElementById('receivables-watchlist');
        if (!list) return;
        list.innerHTML = '';
        const data = Array.isArray(rows) ? rows : [];
        if (!data.length) {
            const li = document.createElement('li');
            li.innerHTML = '<span>All patient balances are clear.</span>';
            list.appendChild(li);
            return;
        }

        data.forEach(item => {
            const li = document.createElement('li');
            const days = Number(item.days_outstanding ?? 0);
            const tagClass = days > 30 ? 'danger' : 'warning';
            const dayLabel = days === 1 ? '1 day' : `${days} days`;
            li.innerHTML = `
                <div>
                    <strong>#${item.bill_id} • ${item.patient_name || 'Unknown'}</strong>
                    <span>${dayLabel} outstanding</span>
                </div>
                <div class="amounts">
                    <span>${formatCurrency(item.balance_due)}</span>
                    <span class="tag ${tagClass}">${days > 30 ? 'Escalate' : 'Follow Up'}</span>
                </div>
            `;
            list.appendChild(li);
        });
    }

    function renderExpenseAnomalies(anomalies) {
        const tbody = document.getElementById('expense-anomalies-body');
        if (!tbody) return;
        tbody.innerHTML = '';
        const rows = Array.isArray(anomalies) ? anomalies : [];
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="5">No anomalies detected. Everything looks good.</td></tr>';
            return;
        }
        rows.forEach(item => {
            const changeAmount = item.change_amount ?? 0;
            let formattedDelta;
            if (Math.abs(changeAmount) < 0.005) {
                formattedDelta = formatCurrency(0);
            } else {
                const amountSymbol = changeAmount >= 0 ? '+' : '−';
                formattedDelta = `${amountSymbol} ${formatCurrency(Math.abs(changeAmount))}`;
            }
            const changePercent = item.change_percent;
            const directionClass = item.direction === 'up' ? 'up' : 'down';
            const arrow = item.direction === 'up' ? '▲' : '▼';
            const percentMarkup = changePercent === null || Number.isNaN(changePercent)
                ? '—'
                : `<span class="status-pill ${directionClass}">${arrow} ${Math.abs(changePercent)}%</span>`;

            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${item.expense_type}</td>
                <td>${formatCurrency(item.current_total ?? 0)}</td>
                <td>${formatCurrency(item.previous_total ?? 0)}</td>
                <td>${formattedDelta}</td>
                <td>${percentMarkup}</td>
            `;
            tbody.appendChild(row);
        });
    }

    function renderDoctorSettlement(snapshot) {
        const summary = snapshot?.summary || {};
        setText('doctor-total-payable', formatCurrency(summary.total_payable ?? 0));
        setText('doctor-total-settled', formatCurrency(summary.total_settled ?? 0));
        setText('doctor-total-pending', formatCurrency(summary.total_pending ?? 0));
        setText('doctor-total-overdue', formatCurrency(summary.total_overdue ?? 0));

        const list = document.getElementById('doctor-pending-list');
        if (!list) return;
        list.innerHTML = '';
        const entries = Array.isArray(snapshot?.top_pending) ? snapshot.top_pending : [];
        if (!entries.length) {
            const li = document.createElement('li');
            li.innerHTML = '<span>No pending payouts 🎉</span>';
            list.appendChild(li);
            return;
        }
        entries.forEach(entry => {
            const li = document.createElement('li');
            const pending = entry.pending ?? 0;
            const overdue = entry.overdue ?? 0;
            const showOverdue = overdue > 0 && overdue >= pending * 0.1;
            const overdueTag = showOverdue ? `<span class="tag danger">Overdue ${formatCurrency(overdue)}</span>` : '';
            li.innerHTML = `
                <div>
                    <strong>${entry.doctor_name}</strong>
                    <span>${formatCurrency(entry.payable ?? 0)} payable • ${formatCurrency(entry.settled ?? 0)} settled</span>
                </div>
                <div class="amounts">
                    <span>${formatCurrency(pending)} pending</span>
                    ${overdueTag}
                </div>
            `;
            list.appendChild(li);
        });
    }

    function renderTestProfitability(rows) {
        const tbody = document.getElementById('test-profitability-body');
        if (!tbody) return;
        tbody.innerHTML = '';
        const data = Array.isArray(rows) ? rows : [];
        if (!data.length) {
            tbody.innerHTML = '<tr><td colspan="6">Not enough billing data for this range.</td></tr>';
            return;
        }
        data.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${item.main_test_name}</td>
                <td>${formatNumber(item.tests_performed ?? 0)}</td>
                <td>${formatCurrency(item.gross_total ?? 0)}</td>
                <td>${formatCurrency(item.discount_total ?? 0)}</td>
                <td>${formatCurrency(item.doctor_payable ?? 0)}</td>
                <td>${formatCurrency(item.net_margin ?? 0)}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    function updateDashboard(data) {
        const metrics = data.metrics || {};
        document.getElementById('kpi-total-earnings').textContent = formatCurrency(metrics.total_earnings);
        document.getElementById('kpi-total-discounts').textContent = formatCurrency(metrics.total_discounts);
        document.getElementById('kpi-total-payouts').textContent = formatCurrency(metrics.total_payouts);
        document.getElementById('kpi-package-revenue').textContent = formatCurrency(metrics.package_revenue);
        document.getElementById('kpi-package-sales').textContent = formatNumber(metrics.package_sales_count ?? 0);
        document.getElementById('kpi-package-discount-impact').textContent = formatCurrency(metrics.package_discount_impact);
        const pendingEl = document.getElementById('kpi-pending-payouts');
        pendingEl.textContent = formatCurrency(metrics.pending_payouts);
        pendingEl.style.color = (metrics.pending_payouts ?? 0) > 0 ? '#e74a3b' : '#1cc88a';

        renderCashflowPulse(data.cashflow_pulse);
        renderReceivables(data.receivables_aging);
        renderExpenseAnomalies(data.expense_anomalies);
        renderDoctorSettlement(data.doctor_settlement);
        renderTestProfitability(data.test_profitability);
        renderPendingExpenses(data.pending_expenses);
        renderReceivablesWatchlist(data.receivables_watchlist);

        // Update Charts
        if (data.collections_trend) {
            if (charts.collectionsTrend) charts.collectionsTrend.destroy();
            charts.collectionsTrend = new Chart(
                document.getElementById('collectionsTrendChart'),
                chartConfigs.collectionsTrend(data.collections_trend)
            );
        }

        if (data.revenue_vs_expenses) {
            if (charts.revenueVsExpenses) charts.revenueVsExpenses.destroy();
            charts.revenueVsExpenses = new Chart(
                document.getElementById('revenueVsExpensesChart'),
                chartConfigs.multiBar(data.revenue_vs_expenses)
            );
        }

        if (data.expense_breakdown) {
            if (charts.expenseBreakdown) charts.expenseBreakdown.destroy();
            charts.expenseBreakdown = new Chart(
                document.getElementById('expenseBreakdownChart'),
                chartConfigs.doughnut(data.expense_breakdown)
            );
        }

        if (data.doctor_payouts) {
            if (charts.topDoctorsPayouts) charts.topDoctorsPayouts.destroy();
            charts.topDoctorsPayouts = new Chart(
                document.getElementById('topDoctorsPayoutsChart'),
                chartConfigs.bar({
                    labels: data.doctor_payouts.labels || [],
                    values: data.doctor_payouts.values || [],
                    label: 'Payable',
                    color: '#3b82f6',
                    indexAxis: 'y'
                })
            );
        }

        if (data.payment_modes) {
            if (charts.paymentMode) charts.paymentMode.destroy();
            charts.paymentMode = new Chart(
                document.getElementById('paymentModeChart'),
                chartConfigs.pie(data.payment_modes)
            );
        }
    }

    function fetchData() {
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        const params = new URLSearchParams({
            action: 'getAccountantDashboardData',
            start_date: startDate,
            end_date: endDate
        });

        fetch(`ajax_handler.php?${params.toString()}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Request failed with status ${response.status}`);
                }
                return response.json();
            })
            .then(updateDashboard)
            .catch(error => {
                console.error('Error fetching dashboard data:', error);
                updateDashboard({
                    metrics: {},
                    receivables_aging: {},
                    expense_anomalies: [],
                    doctor_settlement: {},
                    test_profitability: [],
                    collections_trend: null,
                    revenue_vs_expenses: null,
                    expense_breakdown: null,
                    payment_modes: null,
                    doctor_payouts: null,
                    cashflow_pulse: {},
                    pending_expenses: [],
                    receivables_watchlist: []
                });
            });
    }

    document.getElementById('date-filter-form').addEventListener('submit', function(e) {
        e.preventDefault();
        document.querySelectorAll('.quick-date-pills .btn-action').forEach(btn => btn.classList.remove('active'));
        fetchData();
    });

    document.querySelectorAll('.quick-date-pills .btn-action').forEach(button => {
        button.addEventListener('click', function() {
            document.querySelectorAll('.quick-date-pills .btn-action').forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            const range = this.dataset.range;
            const today = new Date();
            let startDate = new Date();
            let endDate = new Date();

            switch(range) {
                case 'today':
                    // No change needed
                    break;
                case 'week':
                    const dayOfWeek = today.getDay();
                    startDate.setDate(today.getDate() - dayOfWeek + (dayOfWeek === 0 ? -6 : 1));
                    break;
                case 'month':
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                    break;
                case 'last_month':
                    startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                    endDate = new Date(today.getFullYear(), today.getMonth(), 0);
                    break;
            }
            
            document.getElementById('start_date').value = startDate.toISOString().slice(0, 10);
            document.getElementById('end_date').value = endDate.toISOString().slice(0, 10);
            fetchData();
        });
    });

    // --- NEW: Add double-click navigation to KPI cards ---
    document.querySelectorAll('.summary-card.clickable-card').forEach(card => {
        card.addEventListener('dblclick', function() {
            const url = this.dataset.url;
            if (url) {
                // This will redirect the user to the specified page
                window.location.href = url;
            }
        });
    });

    // --- NEW: Enable smooth scroll for dashboard container ---
    if (typeof window.enableSmoothScroll === 'function') {
        window.enableSmoothScroll({
            speed: 0.92,
            ease: 0.12,
            progressIndicator: false,
            enableOnTouch: false
        });
    }
    
    // Initial fetch for the default date range ('Today')
    document.querySelector('.quick-date-pills .btn-action[data-range="today"]').click();
});
</script>

<?php require_once '../includes/footer.php'; ?>