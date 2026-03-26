document.addEventListener('DOMContentLoaded', function() {
    const initNavbarIndicator = () => {
        const navbar = document.querySelector('.main-navbar');
        if (!navbar) return;
        const navList = navbar.querySelector('ul');
        const navLinks = navList ? Array.from(navList.querySelectorAll('a')) : [];
        if (!navList || navLinks.length === 0) return;

        let indicator = navList.querySelector('.nav-pill-indicator');
        if (!indicator) {
            indicator = document.createElement('span');
            indicator.className = 'nav-pill-indicator';
            indicator.setAttribute('aria-hidden', 'true');
            navList.appendChild(indicator);
        }

        const moveIndicator = (targetLink, state = 'active') => {
            if (!targetLink || !indicator) return;
            window.requestAnimationFrame(() => {
                const listRect = navList.getBoundingClientRect();
                const linkRect = targetLink.getBoundingClientRect();
                const offsetLeft = linkRect.left - listRect.left;
                const offsetTop = linkRect.top - listRect.top;

                indicator.style.width = `${linkRect.width}px`;
                indicator.style.height = `${linkRect.height}px`;
                indicator.style.transform = `translate(${offsetLeft}px, ${offsetTop}px)`;
                indicator.style.opacity = '1';
                indicator.dataset.state = state;
            });
        };

        let activeLink = navLinks.find(link => link.classList.contains('active')) || navLinks[0];
        if (activeLink) {
            moveIndicator(activeLink, 'active');
        }

        navLinks.forEach(link => {
            link.addEventListener('mouseenter', () => moveIndicator(link, link === activeLink ? 'active' : 'hover'));
            link.addEventListener('focus', () => moveIndicator(link, link === activeLink ? 'active' : 'hover'));
            link.addEventListener('click', () => {
                activeLink = link;
                moveIndicator(activeLink, 'active');
            });
            link.addEventListener('mouseleave', () => moveIndicator(activeLink, 'active'));
            link.addEventListener('blur', () => moveIndicator(activeLink, 'active'));
        });

        window.addEventListener('resize', () => moveIndicator(activeLink, 'active'));
        if ('ResizeObserver' in window) {
            const resizeObserver = new ResizeObserver(() => moveIndicator(activeLink, 'active'));
            resizeObserver.observe(navList);
        }
    };

    initNavbarIndicator();

    const initFloatingNavCollision = () => {
        const body = document.body;
        if (!body.classList.contains('role-superadmin')) return;
        const navbar = document.querySelector('.main-navbar');
        if (!navbar) return;
        const content = navbar.nextElementSibling;
        if (!content) return;

        let ticking = false;

        const evaluateCollision = () => {
            if (body.classList.contains('nav-collapsed')) {
                body.classList.remove('manager-nav-colliding');
                ticking = false;
                return;
            }

            const navRect = navbar.getBoundingClientRect();
            const contentRect = content.getBoundingClientRect();
            const overlap = navRect.bottom - contentRect.top;
            const isColliding = overlap > 4; // ignore simple adjacency to avoid hiding nav on load
            body.classList.toggle('manager-nav-colliding', isColliding);
            ticking = false;
        };

        const onScrollOrResize = () => {
            if (!ticking) {
                window.requestAnimationFrame(evaluateCollision);
                ticking = true;
            }
        };

        window.addEventListener('scroll', onScrollOrResize, { passive: true });
        window.addEventListener('resize', onScrollOrResize);
        document.addEventListener('smoothScrollTick', onScrollOrResize);
        evaluateCollision();
    };

    initFloatingNavCollision();

    const initQuickDateShortcuts = () => {
        const quickButtons = document.querySelectorAll('.btn-quick-date');
        if (!quickButtons.length) {
            return;
        }

        const formatDate = (date) => {
            if (!(date instanceof Date)) {
                return '';
            }
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        };

        const getMonthBounds = (year, monthIndex) => {
            const start = new Date(year, monthIndex, 1);
            const end = new Date(year, monthIndex + 1, 0);
            return { start, end };
        };

        const computeRange = (token) => {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            let start;
            let end;

            switch (token) {
                case 'today':
                    start = new Date(today);
                    end = new Date(today);
                    break;
                case 'yesterday':
                    start = new Date(today);
                    start.setDate(start.getDate() - 1);
                    end = new Date(start);
                    break;
                case 'this_week':
                    end = new Date(today);
                    start = new Date(today);
                    start.setDate(start.getDate() - 6);
                    break;
                case 'this_month': {
                    const bounds = getMonthBounds(today.getFullYear(), today.getMonth());
                    start = bounds.start;
                    end = bounds.end;
                    break;
                }
                case 'last_month': {
                    const year = today.getMonth() === 0 ? today.getFullYear() - 1 : today.getFullYear();
                    const monthIndex = today.getMonth() === 0 ? 11 : today.getMonth() - 1;
                    const bounds = getMonthBounds(year, monthIndex);
                    start = bounds.start;
                    end = bounds.end;
                    break;
                }
                case 'this_year':
                    start = new Date(today.getFullYear(), 0, 1);
                    end = new Date(today.getFullYear(), 11, 31);
                    break;
                default:
                    return null;
            }

            return {
                start: formatDate(start),
                end: formatDate(end)
            };
        };

        quickButtons.forEach((btn) => {
            btn.addEventListener('click', (event) => {
                const range = computeRange(btn.dataset.range);
                if (!range) {
                    return;
                }

                const targetForm = btn.closest('form');
                const startInput = targetForm ? targetForm.querySelector('input[name="start_date"]') : null;
                const endInput = targetForm ? targetForm.querySelector('input[name="end_date"]') : null;

                if (!startInput || !endInput) {
                    return;
                }

                startInput.value = range.start;
                endInput.value = range.end;

                const changeEvent = new Event('change', { bubbles: true });
                startInput.dispatchEvent(changeEvent);
                endInput.dispatchEvent(changeEvent);

                if (targetForm && btn.dataset.autosubmit !== 'false') {
                    if (typeof targetForm.requestSubmit === 'function') {
                        targetForm.requestSubmit();
                    } else {
                        targetForm.submit();
                    }
                }

                event.preventDefault();
            });
        });
    };

    initQuickDateShortcuts();
    
    const dashboardForm = document.getElementById('analytics-filter-form');
    let currentFilterParams = ''; // Store current filter parameters globally

    const formatCurrency = (value) => {
        const num = parseFloat(value) || 0;
        return `₹${num.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    const updateDashboard = () => {
        if (!dashboardForm) return;

        const reportHeader = document.getElementById('report-table-header');
        const reportBody = document.getElementById('report-table-body');
        
        // Show loading spinner
        reportBody.innerHTML = `<tr><td colspan="100%" style="text-align: center; padding: 40px;"><div class="spinner"></div><p>Loading report data...</p></td></tr>`;
        reportHeader.innerHTML = ''; // Clear old header

        const params = new URLSearchParams(new FormData(dashboardForm)).toString();
        currentFilterParams = params; // Store for later use
        
        fetch(`ajax_dashboard_analytics.php?${params}`)
            .then(res => res.json())
            .then(data => {
                // --- 1. Dynamically Build Table Header ---
                let headerHtml = '<tr>';
                headerHtml += '<th>Doctor Name</th>';
                data.main_test_headers.forEach(testName => {
                    headerHtml += `<th colspan="2" style="text-align: center;">${testName}</th>`;
                });
                headerHtml += '<th>Gross Amount</th>';
                headerHtml += '<th>Total Discount</th>';
                headerHtml += '<th>Net Amount</th>';
                headerHtml += '<th>Total Payable</th>';
                headerHtml += '</tr>';

                // Add sub-headers
                let subHeaderHtml = '<tr><th></th>'; // Empty cell for Doctor Name
                data.main_test_headers.forEach(() => {
                    subHeaderHtml += '<th>Total Tests</th><th>Revenue</th>';
                });
                subHeaderHtml += '<th></th><th></th><th></th><th></th>'; // Empty cells for trailing columns
                subHeaderHtml += '</tr>';

                reportHeader.innerHTML = headerHtml + subHeaderHtml;

                // --- 2. Populate Table Body ---
                reportBody.innerHTML = ''; // Clear loading spinner
                if (data.doctor_data.length === 0) {
                    reportBody.innerHTML = `<tr><td colspan="100%" style="text-align: center; padding: 40px;">No data found for the selected filters.</td></tr>`;
                    return;
                }

                // Prepare totals
                let totals = {};
                data.main_test_headers.forEach(testName => {
                    const alias = testName.toLowerCase().replace(/[\s\/-]/g, '_');
                    totals[alias + '_count'] = 0;
                    totals[alias + '_revenue'] = 0;
                });
                totals.gross_amount = 0;
                totals.total_discount = 0;
                totals.net_amount = 0;
                totals.total_payable = 0;

                data.doctor_data.forEach(doctor => {
                    let rowHtml = '<tr class="doctor-row" data-doctor-id="' + doctor.doctor_id + '">';
                    rowHtml += `<td class="doctor-name-cell" style="cursor: pointer; color: #4e73df; font-weight: 500;">
                                    <span class="expand-icon" style="margin-right: 8px; font-size: 14px;">▶</span>
                                    Dr. ${doctor.doctor_name}
                                </td>`;

                    data.main_test_headers.forEach(testName => {
                        const alias = testName.toLowerCase().replace(/[\s\/-]/g, '_');
                        const count = doctor[alias + '_count'] || 0;
                        const revenue = doctor[alias + '_revenue'] || 0;
                        rowHtml += `<td style="text-align: center;">${count}</td>`;
                        rowHtml += `<td>${formatCurrency(revenue)}</td>`;
                        totals[alias + '_count'] += parseFloat(count);
                        totals[alias + '_revenue'] += parseFloat(revenue);
                    });

                    totals.gross_amount += parseFloat(doctor.gross_amount);
                    totals.total_discount += parseFloat(doctor.total_discount);
                    totals.net_amount += parseFloat(doctor.net_amount);
                    totals.total_payable += parseFloat(doctor.total_payable);

                    rowHtml += `<td>${formatCurrency(doctor.gross_amount)}</td>`;
                    rowHtml += `<td>${formatCurrency(doctor.total_discount)}</td>`;
                    rowHtml += `<td><strong>${formatCurrency(doctor.net_amount)}</strong></td>`;
                    rowHtml += `<td>${formatCurrency(doctor.total_payable)}</td>`;
                    rowHtml += '</tr>';
                    
                    // Add placeholder for detail row (will be populated on click)
                    rowHtml += `<tr class="detail-row" data-doctor-id="${doctor.doctor_id}" style="display: none;">
                                    <td colspan="${data.main_test_headers.length * 2 + 5}" style="padding: 0;">
                                        <div class="detail-content"></div>
                                    </td>
                                </tr>`;
                    
                    reportBody.innerHTML += rowHtml;
                });

                // Add totals row
                let totalRow = '<tr class="totals-row" style="font-weight:bold; background:#f8f9fc;">';
                totalRow += '<td>Total</td>';
                data.main_test_headers.forEach(testName => {
                    const alias = testName.toLowerCase().replace(/[\s\/-]/g, '_');
                    totalRow += `<td style="text-align: center;">${totals[alias + '_count']}</td>`;
                    totalRow += `<td>${formatCurrency(totals[alias + '_revenue'])}</td>`;
                });
                totalRow += `<td>${formatCurrency(totals.gross_amount)}</td>`;
                totalRow += `<td>${formatCurrency(totals.total_discount)}</td>`;
                totalRow += `<td><strong>${formatCurrency(totals.net_amount)}</strong></td>`;
                totalRow += `<td>${formatCurrency(totals.total_payable)}</td>`;
                totalRow += '</tr>';
                reportBody.innerHTML += totalRow;

                // Initialize the subtest feature with filter params AND main test headers
                if (typeof window.initializeDoctorSubtestFeature === 'function') {
                    window.initializeDoctorSubtestFeature(params, data.main_test_headers);
                }
            })
            .catch(error => {
                console.error("Error fetching dashboard data:", error);
                reportBody.innerHTML = `<tr><td colspan="100%" style="text-align: center; padding: 40px; color: red;">An error occurred while loading the report.</td></tr>`;
            });
    };
    
    if (dashboardForm) {
        dashboardForm.addEventListener('submit', (e) => {
            e.preventDefault();
            updateDashboard();
        });
        updateDashboard(); // Initial load
    }
    
    // --- Event Notification Logic ---
    const formatEventLine = (event) => {
        if (!event) return '';
        const safeTitle = (event.title || 'Event').trim();
        const safeType = (event.event_type || 'Event').trim();
        const rawDate = event.event_date || event.start || '';
        if (!rawDate) {
            return `${safeTitle} - ${safeType}`.trim();
        }
        const safeDate = rawDate.includes('T') ? rawDate : `${rawDate}T00:00:00`;
        const eventDate = new Date(safeDate);
        const formattedDate = isNaN(eventDate) ? rawDate : eventDate.toLocaleDateString('en-IN', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });
        return `${safeTitle} - ${safeType} - ${formattedDate}`;
    };

    const showCornerNotifications = (events) => {
        if (sessionStorage.getItem('cornerNotificationsShown')) return;
        const container = document.getElementById('notification-corner');
        if (!container) return;
        events.forEach((event, index) => {
            setTimeout(() => {
                const notification = document.createElement('div');
                notification.className = 'notification-item';
                let icon = '✨';
                switch (event.event_type.toLowerCase()) {
                    case 'birthday': icon = '🎂'; break;
                    case 'anniversary': icon = '💍'; break;
                    case 'holiday': icon = '🌴'; break;
                }
                notification.innerHTML = `<div class="notification-header"><span class="notification-title">${icon} ${event.event_type}</span><button class="notification-close">&times;</button></div><div class="notification-body">${formatEventLine(event)}</div>`;
                container.appendChild(notification);
                notification.querySelector('.notification-close').addEventListener('click', () => {
                    notification.classList.add('fade-out');
                    setTimeout(() => notification.remove(), 500);
                });
            }, index * 400);
        });
        sessionStorage.setItem('cornerNotificationsShown', 'true');
    };

    const checkForEvents = () => {
        fetch('ajax_check_events_popup.php?scope=dashboard')
            .then(response => response.json())
            .then(events => {
                if (events && events.length > 0) {
                    const today = new Date().toISOString().slice(0, 10);
                    const lastPopupDate = localStorage.getItem('lastEventPopupDate');
                    if (lastPopupDate !== today) {
                        let eventHtml = '<ul style="list-style: none; padding: 0; margin: 0; text-align: left;">';
                        events.forEach(event => {
                             let icon = '✨';
                            switch (event.event_type.toLowerCase()) { case 'birthday': icon = '🎂'; break; case 'anniversary': icon = '💍'; break; case 'holiday': icon = '🌴'; break; }
                            eventHtml += `<li style="padding: 10px; border-bottom: 1px solid #eee; display: flex; align-items: center;"><span style="font-size: 1.5rem; margin-right: 15px;">${icon}</span><strong>${formatEventLine(event)}</strong></li>`;
                        });
                        eventHtml += '</ul>';
                        const monthLabel = new Date().toLocaleString('en-IN', { month: 'long', year: 'numeric' });
                        Swal.fire({
                            title: `<strong>${monthLabel} Events (${events.length})</strong>`,
                            html: eventHtml,
                            showCloseButton: true,
                            allowOutsideClick: true
                        });
                        localStorage.setItem('lastEventPopupDate', today);
                    } else {
                        showCornerNotifications(events);
                    }
                }
            });
    };

    if (document.body.classList.contains('role-superadmin') && window.location.pathname.includes('dashboard.php')) {
        checkForEvents();
    }

    // #############################################################################
    // ### NEW: Doctor Comparison Page Logic (Replaces old chart-based logic) ###
    // #############################################################################
    const compareForms = document.querySelectorAll('.compare-form');
    const commonFiltersForm = document.getElementById('common-filters-form');

    if (compareForms.length > 0 && commonFiltersForm) {

        // Function to fetch and render the comparison data for one panel
        const runComparison = (panelId) => {
            const resultsContainer = document.getElementById(`compare-results-${panelId}`);
            const form = document.getElementById(`panel-${panelId}`).querySelector('.compare-form');
            const doctorSelect = form.querySelector('[name="doctor_id"]');

            if (!doctorSelect.value) {
                resultsContainer.innerHTML = '<p class="placeholder-text error">Please select a doctor.</p>';
                return;
            }
            
            resultsContainer.innerHTML = '<div class="spinner"></div><p style="text-align:center;">Loading analysis...</p>';

            // Combine common filters with the specific doctor ID
            const commonFormData = new FormData(commonFiltersForm);
            const params = new URLSearchParams(commonFormData);
            params.set('doctor_id', doctorSelect.value);

            fetch(`ajax_compare_handler.php?${params.toString()}`)
                .then(res => res.text()) // We expect HTML now, not JSON
                .then(html => {
                    resultsContainer.innerHTML = html;
                })
                .catch(err => {
                    resultsContainer.innerHTML = '<p class="placeholder-text error">Could not load data.</p>';
                    console.error('Comparison fetch error:', err);
                });
        };

        // Attach submit event listener to each "Analyze" button's form
        compareForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const panelId = this.dataset.panelId;
                runComparison(panelId);
            });
        });

        // Add event listeners to common filters to auto-update both panels if doctors are selected
        ['common_start', 'common_end', 'common_test', 'common_subtest'].forEach(filterId => {
            const element = document.getElementById(filterId);
            if (element) {
                element.addEventListener('change', function() {
                    // Trigger analysis for any panel that has a doctor selected
                    compareForms.forEach(form => {
                        const doctorSelect = form.querySelector('[name="doctor_id"]');
                        if (doctorSelect && doctorSelect.value) {
                            runComparison(form.dataset.panelId);
                        }
                    });
                });
            }
        });
        
        // Handle main test change to populate sub-tests in common filters
        const commonTestSelect = document.getElementById('common_test');
        const commonSubTestSelect = document.getElementById('common_subtest');
        
        if (commonTestSelect && commonSubTestSelect) {
            commonTestSelect.addEventListener('change', function() {
                const mainTest = this.value;
                commonSubTestSelect.innerHTML = '<option value="">-- All Sub Tests --</option>';
                
                if (mainTest) {
                    fetch(`ajax_get_subtests.php?main_test=${encodeURIComponent(mainTest)}`)
                        .then(res => res.json())
                        .then(subtests => {
                            subtests.forEach(subtest => {
                                const option = document.createElement('option');
                                option.value = subtest.sub_test_name;
                                option.textContent = subtest.sub_test_name;
                                commonSubTestSelect.appendChild(option);
                            });
                        })
                        .catch(err => console.error('Error loading sub-tests:', err));
                }
            });
        }
    }

    // --- NEW: Deep Analysis Page Logic ---
    const deepAnalysisForm = document.getElementById('deep-analysis-form');
    if (deepAnalysisForm) {
        let deepAnalysisChart;

        deepAnalysisForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const params = new URLSearchParams(formData).toString();
            const placeholder = document.getElementById('deep-analysis-placeholder');
            const chartContainer = document.getElementById('deep-analysis-chart-container');

            placeholder.innerHTML = '<div class="spinner"></div><p>Generating analysis...</p>';

            fetch(`ajax_deep_analysis.php?${params}`)
                .then(res => res.json())
                .then(data => {
                    placeholder.style.display = 'none';
                    if (deepAnalysisChart) {
                        deepAnalysisChart.destroy();
                    }
                    renderDeepAnalysisCharts(data, formData.get('metric'));
                })
                .catch(err => {
                    placeholder.innerHTML = '<p class="placeholder-text error">Could not generate chart.</p>';
                    console.error(err);
                });
        });
    }

    // --- Advanced Deep Analysis Chart Rendering ---
    function renderDeepAnalysisCharts(data, metric) {
        const ctx = document.getElementById('deep-analysis-chart').getContext('2d');
        if (!data || data.length === 0) {
            document.getElementById('deep-analysis-placeholder').innerHTML = 'No data found for selected filters.';
            return;
        }

        // Prepare data for stacked column and pareto chart
        const months = data.map(item => item.month);
        const values = data.map(item => parseFloat(item.value));

        // Calculate percentage changes month-over-month for VALUES
        const valuePercentChanges = values.map((val, idx) => {
            if (idx === 0) return 0;
            const prev = values[idx - 1];
            if (prev === 0) return val > 0 ? 100 : 0;
            return ((val - prev) / prev) * 100;
        });

        // Pareto calculation (cumulative percentage)
        const total = values.reduce((a, b) => a + b, 0);
        let cumulative = 0;
        const pareto = values.map(val => {
            cumulative += val;
            return (cumulative / total) * 100;
        });

        // Calculate percentage changes for PARETO line (bar to bar)
        const paretoPercentChanges = pareto.map((val, idx) => {
            if (idx === 0) return 0;
            const prev = pareto[idx - 1];
            if (prev === 0) return val > 0 ? 100 : 0;
            return ((val - prev) / prev) * 100;
        });

        // Color coding for bar values based on percentage changes
        const barColors = valuePercentChanges.map((pct, idx) => {
            if (idx === 0) return 'rgba(108,117,125,0.7)'; // gray for first bar
            if (pct > 0) return 'rgba(40,167,69,0.8)'; // green for increase
            if (pct < 0) return 'rgba(220,53,69,0.8)'; // red for decrease
            return 'rgba(108,117,125,0.7)'; // gray for no change
        });

        // Color coding for pareto line points based on percentage changes
        const paretoPointColors = paretoPercentChanges.map((pct, idx) => {
            if (idx === 0) return '#36b9cc'; // default blue for first point
            if (pct > 0) return '#28a745'; // green for increase
            if (pct < 0) return '#dc3545'; // red for decrease
            return '#6c757d'; // gray for no change
        });

        // Create segment colors for pareto line
        const paretoSegmentColors = paretoPercentChanges.map((pct, idx) => {
            if (idx === 0) return '#36b9cc'; // default blue for first segment
            if (pct > 0) return '#28a745'; // green for increase
            if (pct < 0) return '#dc3545'; // red for decrease
            return '#6c757d'; // gray for no change
        });

        deepAnalysisChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: months.map(m => {
                    const d = new Date(m + '-02');
                    return d.toLocaleString('default', { month: 'short', year: '2-digit' });
                }),
                datasets: [
                    {
                        label: metric.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()),
                        data: values,
                        backgroundColor: barColors,
                        borderColor: barColors.map(color => color.replace('0.8', '1')),
                        borderWidth: 2,
                        yAxisID: 'y',
                    },
                    {
                        label: 'Pareto (%)',
                        data: pareto,
                        type: 'line',
                        borderColor: paretoSegmentColors,
                        backgroundColor: 'rgba(54,185,204,0.1)',
                        fill: false,
                        yAxisID: 'y1',
                        tension: 0.3,
                        pointRadius: 6,
                        pointBackgroundColor: paretoPointColors,
                        pointBorderColor: paretoPointColors,
                        pointBorderWidth: 2,
                        segment: {
                            borderColor: function(ctx) {
                                const idx = ctx.p0DataIndex + 1;
                                if (idx < paretoSegmentColors.length) {
                                    return paretoSegmentColors[idx];
                                }
                                return '#36b9cc';
                            }
                        }
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Deep Analysis - Values & Pareto with Percentage Changes',
                        font: { size: 18 }
                    },
                    legend: { 
                        position: 'bottom',
                        labels: {
                            generateLabels: function(chart) {
                                const original = Chart.defaults.plugins.legend.labels.generateLabels(chart);
                                // Add color legend
                                original.push(
                                    { text: '🟢 Increase', fillStyle: '#28a745', strokeStyle: '#28a745' },
                                    { text: '🔴 Decrease', fillStyle: '#dc3545', strokeStyle: '#dc3545' },
                                    { text: '⚪ No Change/First', fillStyle: '#6c757d', strokeStyle: '#6c757d' }
                                );
                                return original;
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                if (context.dataset.label === 'Pareto (%)') {
                                    const idx = context.dataIndex;
                                    const paretoChange = paretoPercentChanges[idx];
                                    let paretoChangeLabel = '';
                                    if (idx > 0) {
                                        const sign = paretoChange > 0 ? '+' : '';
                                        paretoChangeLabel = ` (${sign}${paretoChange.toFixed(1)}% vs prev)`;
                                    }
                                    return `Pareto: ${context.parsed.y.toFixed(1)}%${paretoChangeLabel}`;
                                } else {
                                    const idx = context.dataIndex;
                                    const valueChange = valuePercentChanges[idx];
                                    let valueChangeLabel = '';
                                    if (idx > 0) {
                                        const sign = valueChange > 0 ? '+' : '';
                                        valueChangeLabel = ` (${sign}${valueChange.toFixed(1)}% vs prev)`;
                                    }
                                    return `${context.dataset.label}: ${context.parsed.y}${valueChangeLabel}`;
                                }
                            }
                        }
                    },
                    datalabels: {
                        display: true,
                        color: function(context) {
                            if (context.dataset.label === 'Pareto (%)') return '#000';
                            const idx = context.dataIndex;
                            if (idx === 0) return '#000';
                            const pct = valuePercentChanges[idx];
                            return pct > 0 ? '#28a745' : pct < 0 ? '#dc3545' : '#000';
                        },
                        font: { weight: 'bold', size: 10 },
                        formatter: function(value, context) {
                            if (context.dataset.label === 'Pareto (%)') {
                                const idx = context.dataIndex;
                                if (idx > 0) {
                                    const pct = paretoPercentChanges[idx];
                                    const sign = pct > 0 ? '+' : '';
                                    return `${sign}${pct.toFixed(1)}%`;
                                }
                                return '';
                            } else {
                                const idx = context.dataIndex;
                                if (idx > 0) {
                                    const pct = valuePercentChanges[idx];
                                    const sign = pct > 0 ? '+' : '';
                                    return `${value}\n(${sign}${pct.toFixed(1)}%)`;
                                }
                                return value;
                            }
                        },
                        anchor: function(context) {
                            return context.dataset.label === 'Pareto (%)' ? 'start' : 'end';
                        },
                        align: function(context) {
                            return context.dataset.label === 'Pareto (%)' ? 'top' : 'top';
                        },
                        offset: 4
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { 
                            display: true, 
                            text: metric.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()),
                            color: '#495057'
                        },
                        position: 'left',
                        grid: { drawOnChartArea: true, color: 'rgba(0,0,0,0.1)' }
                    },
                    y1: {
                        beginAtZero: true,
                        title: { 
                            display: true, 
                            text: 'Pareto Cumulative (%)',
                            color: '#495057'
                        },
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        min: 0,
                        max: 100,
                        ticks: { 
                            callback: value => value + '%',
                            color: '#495057'
                        }
                    }
                }
            }
        });
    }
});
