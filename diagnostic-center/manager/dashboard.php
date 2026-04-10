<?php
$page_title = "Manager Dashboard";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';
?>



<div class="main-content page-container">
    <div class="dashboard-header" >
        <div>
            <h1>Manager's Dashboard</h1>
            <p>Business intelligence and operational overview for the selected period.</p>
        </div>
    </div>

    <form id="date-filter-form" class="filter-form compact-filters">
        <div class="filter-group quick-dates-group">
            <label>Quick Dates</label>
            <div class="quick-date-pills">
                <button type="button" class="btn-action active" data-range="today">Today</button>
                <button type="button" class="btn-action" data-range="week">This Week</button>
                <button type="button" class="btn-action" data-range="month">This Month</button>
                <button type="button" class="btn-action" data-range="last_month">Last Month</button>
            </div>
        </div>
        <div class="filter-group date-field-group">
            <label for="start_date">Start</label>
            <input type="date" id="start_date" name="start_date" value="<?php echo date('Y-m-d'); ?>" style="color: #000 !important;">
        </div>
        <div class="filter-group date-field-group">
            <label for="end_date">End</label>
            <input type="date" id="end_date" name="end_date" value="<?php echo date('Y-m-d'); ?>" style="color: #000 !important;">
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-submit">Apply</button>
        </div>
    </form>


    <div class="summary-cards">
        <div class="summary-card">
            <a href="#" id="kpi-link-patients" class="kpi-link">
                <h3>Total Patients</h3>
                <p id="kpi-total-patients">0</p>
            </a>
        </div>
        <div class="summary-card">
            <a href="#" id="kpi-link-bills" class="kpi-link">
                <h3>Total Bills</h3>
                <p id="kpi-total-bills">0</p>
            </a>
        </div>
        
        <div class="summary-card summary-card-clickable" id="pending-bills-card" title="Double-click to view details">
            <h3>Pending Bills</h3>
            <p id="kpi-pending-bills" class="text-danger">0</p>
        </div>
        
        <div class="summary-card">
            <a href="#" id="kpi-link-tests" class="kpi-link">
                <h3>Tests Performed</h3>
                <p id="kpi-total-tests">0</p>
            </a>
        </div>
        <div class="summary-card">
            <a href="#" id="kpi-link-revenue" class="kpi-link">
                <h3>Total Revenue</h3>
                <p id="kpi-total-revenue">₹ 0.00</p>
            </a>
        </div>
    </div>

    <div class="charts-section">
        <div class="chart-container"><h3>Top 5 Test Categories</h3><canvas id="topTestCategoriesChart"></canvas></div>
        <div class="chart-container"><h3>Referral Sources</h3><canvas id="referralSourceChart"></canvas></div>
        <div class="chart-container"><h3>Top 5 Referring Doctors (by Patients)</h3><canvas id="topDoctorsChart"></canvas></div>
        <div class="chart-container"><h3>Revenue by Payment Method</h3><canvas id="paymentModeChart"></canvas></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    let charts = {};

    function handleChartClick(event, elements, chartInstance, filterParam) {
        if (elements.length === 0) return;
        const elementIndex = elements[0].index;
        let filterValue;
        if (filterParam === 'doctor_id' && chartInstance.data.ids) {
            filterValue = chartInstance.data.ids[elementIndex];
        } else {
            filterValue = chartInstance.data.labels[elementIndex];
        }
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        const url = `analytics.php?start_date=${startDate}&end_date=${endDate}&${filterParam}=${encodeURIComponent(filterValue)}`;
        window.location.href = url;
    }
    
    const formatNumber = value => Number(value ?? 0).toLocaleString('en-IN');
    const formatCurrency = value => `₹ ${Number(value ?? 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const palette = ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#fd7e14', '#20c997'];

    const chartConfigs = {
        doughnut: ({ labels, values }, onClickHandler) => ({
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
                onClick: onClickHandler || undefined,
                layout: { padding: 12 },
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { usePointStyle: true, padding: 18, font: { size: 12 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: context => `${context.label}: ${formatNumber(context.parsed ?? 0)}`
                        }
                    }
                }
            }
        }),
        pie: ({ labels, values }, onClickHandler) => ({
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
                onClick: onClickHandler || undefined,
                layout: { padding: 12 },
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { usePointStyle: true, padding: 18, font: { size: 12 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: context => `${context.label}: ${formatNumber(context.parsed ?? 0)}`
                        }
                    }
                }
            }
        }),
        bar: ({ labels, values, label = '', color = '#4e73df', indexAxis = 'x', formatter = formatNumber }, onClickHandler) => {
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
                    onClick: onClickHandler || undefined,
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
                                label: context => {
                                    const rawValue = isHorizontal ? (context.parsed?.x ?? 0) : (context.parsed?.y ?? 0);
                                    const prefix = context.dataset.label ? `${context.dataset.label}: ` : '';
                                    return `${prefix}${formatter(rawValue)}`;
                                }
                            }
                        }
                    }
                }
            };
        }
    };

    function updateKpiLinks() {
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        const baseUrl = `analytics.php?start_date=${startDate}&end_date=${endDate}`;
        document.getElementById('kpi-link-patients').href = `${baseUrl}&view=patients`;
        document.getElementById('kpi-link-bills').href = `${baseUrl}&view=bills`;
        document.getElementById('kpi-link-tests').href = `${baseUrl}&view=tests`;
        document.getElementById('kpi-link-revenue').href = `${baseUrl}&view=revenue`;
    }

    function updateDashboard(data) {
        // Update original KPIs
        document.getElementById('kpi-total-patients').textContent = data.kpis.total_patients || 0;
        document.getElementById('kpi-total-bills').textContent = data.kpis.total_bills || 0;
        document.getElementById('kpi-total-tests').textContent = data.kpis.tests_performed || 0;
        document.getElementById('kpi-total-revenue').textContent = `₹ ${parseFloat(data.kpis.total_revenue || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        
        // --- NEW: Update the Pending Bills KPI ---
        document.getElementById('kpi-pending-bills').textContent = data.kpis.pending_bills_count || 0;

        // Update original charts
        const topTests = data.charts.top_test_categories || { labels: [], data: [] };
        if (charts.topTestCategories) charts.topTestCategories.destroy();
        charts.topTestCategories = new Chart(
            document.getElementById('topTestCategoriesChart'),
            chartConfigs.doughnut({ labels: topTests.labels, values: topTests.data }, (e, els) => handleChartClick(e, els, charts.topTestCategories, 'main_test'))
        );

        const referralSources = data.charts.referral_sources || { labels: [], data: [] };
        if (charts.referralSource) charts.referralSource.destroy();
        charts.referralSource = new Chart(
            document.getElementById('referralSourceChart'),
            chartConfigs.pie({ labels: referralSources.labels, values: referralSources.data }, (e, els) => handleChartClick(e, els, charts.referralSource, 'referral_type'))
        );

        const topDoctors = data.charts.top_doctors || { labels: [], data: [], ids: [] };
        if (charts.topDoctors) charts.topDoctors.destroy();
        charts.topDoctors = new Chart(
            document.getElementById('topDoctorsChart'),
            chartConfigs.bar({ labels: topDoctors.labels, values: topDoctors.data, label: 'Patients', color: '#36b9cc', indexAxis: 'y', formatter: formatNumber }, (e, els) => handleChartClick(e, els, charts.topDoctors, 'doctor_id'))
        );
        charts.topDoctors.data.ids = topDoctors.ids;
        
        const paymentModes = data.charts.payment_modes || { labels: [], data: [] };
        if (charts.paymentMode) charts.paymentMode.destroy();
        charts.paymentMode = new Chart(
            document.getElementById('paymentModeChart'),
            chartConfigs.bar({ labels: paymentModes.labels, values: paymentModes.data, label: 'Revenue', color: '#4e73df', formatter: formatCurrency }, null)
        );
        
        updateKpiLinks();
    }

    function fetchData() {
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        fetch(`ajax_handler.php?start_date=${startDate}&end_date=${endDate}`)
            .then(response => { if (!response.ok) throw new Error(`HTTP ${response.status}`); return response.json(); })
            .then(data => { updateDashboard(data); })
            .catch(error => console.error('Error fetching dashboard data:', error));
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
            const now = new Date();
            let startDate = new Date(now);
            let endDate = new Date(now);

            switch(range) {
                case 'today':
                    break;
                case 'week': {
                    const dayOfWeek = startDate.getDay();
                    const diff = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
                    startDate.setDate(startDate.getDate() + diff);
                    break;
                }
                case 'month':
                    startDate = new Date(now.getFullYear(), now.getMonth(), 1);
                    break;
                case 'last_month':
                    startDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                    endDate = new Date(now.getFullYear(), now.getMonth(), 0);
                    break;
            }
            document.getElementById('start_date').value = startDate.toISOString().slice(0, 10);
            document.getElementById('end_date').value = endDate.toISOString().slice(0, 10);
            fetchData();
        });
    });
    
    // --- NEW: Double-click listener for the Pending Bills card ---
    document.getElementById('pending-bills-card').addEventListener('dblclick', function() {
        const todayStr = new Date().toISOString().slice(0, 10);
        window.location.href = `view_due_bills.php?all_dates=1&start_date=2000-01-01&end_date=${todayStr}&status=pending`;
    });
    
    document.querySelector('.quick-date-pills .btn-action[data-range="today"]').click();
});
</script>

<?php require_once '../includes/footer.php'; ?>