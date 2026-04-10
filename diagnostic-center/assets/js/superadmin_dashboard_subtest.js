/**
 * Doctor Subtest Details Functionality (v2 - Single Source of Truth)
 * Handles expandable rows, ensuring data consistency with the main table.
 */

(function() {
    'use strict';
    
    // Stores the current filter parameters (e.g., dates) for AJAX calls
    let currentFilterParams = '';
    
    // Helper function to format numbers as Indian Rupees
    const formatCurrency = (value) => {
        const num = parseFloat(value) || 0;
        return `₹${num.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    /**
     * Initializes the feature by setting the filter context and attaching event listeners.
     * This function is called from the main dashboard PHP file.
     * CRITICAL: This clears all previously loaded subtest data when filters change.
     * @param {string} filterParams - URL-encoded filter parameters
     * @param {Array} mainTestHeaders - Array of main test category names for column mapping
     */
    window.initializeDoctorSubtestFeature = function(filterParams, mainTestHeaders) {
        currentFilterParams = filterParams;
        // Store main test headers to correctly map table columns to data
        window.mainTestHeaders = mainTestHeaders || [];
        
        // CRITICAL FIX: Clear all previously loaded subtest data when filters change
        // This ensures that when user changes filters, the subtest data will reload with new filters
        document.querySelectorAll('.detail-content[data-loaded]').forEach(content => {
            content.removeAttribute('data-loaded');
            content.innerHTML = '';
        });
        
        // Reset all expanded rows to collapsed state
        document.querySelectorAll('.detail-row').forEach(row => {
            row.style.display = 'none';
        });
        document.querySelectorAll('.expand-icon').forEach(icon => {
            icon.textContent = '▶';
        });
        
        attachClickHandlers();
    };

    /**
     * Attaches click event listeners to all doctor name cells in the table.
     * It clones and replaces the cells to avoid conflicting event listeners.
     */
    function attachClickHandlers() {
        const doctorNameCells = document.querySelectorAll('.doctor-name-cell');
        
        doctorNameCells.forEach((cell) => {
            // Clone the cell to safely add a new, isolated event listener
            const newCell = cell.cloneNode(true);
            cell.parentNode.replaceChild(newCell, cell);
            
            newCell.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const doctorRow = this.closest('.doctor-row');
                if (doctorRow) {
                    toggleDoctorDetails(doctorRow);
                }
            });
            
            newCell.style.cursor = 'pointer';
        });
    }

    /**
     * Shows/hides the detail row and orchestrates data fetching.
     * THE CRITICAL CHANGE: Reads totals directly from the clicked table row.
     * @param {HTMLElement} doctorRow The main <tr> element of the doctor.
     */
    function toggleDoctorDetails(doctorRow) {
        const doctorId = doctorRow.getAttribute('data-doctor-id');
        const expandIcon = doctorRow.querySelector('.expand-icon');
        const detailRow = document.querySelector(`.detail-row[data-doctor-id="${doctorId}"]`);
        
        if (!detailRow) {
            console.error('Detail row not found for doctor ID:', doctorId);
            return;
        }

        const detailContent = detailRow.querySelector('.detail-content');
        const isExpanded = detailRow.style.display !== 'none';

        if (isExpanded) {
            detailRow.style.display = 'none';
            if (expandIcon) expandIcon.textContent = '▶';
        } else {
            detailRow.style.display = 'table-row';
            if (expandIcon) expandIcon.textContent = '▼';
            
            // Load data only on the first expansion
            if (!detailContent.hasAttribute('data-loaded')) {
                // **CRITICAL CHANGE**: Read totals directly from the clicked table row
                const categoryTotals = getTotalsFromTableRow(doctorRow);
                loadSubtestData(doctorId, detailContent, categoryTotals);
            }
        }
    }

    /**
     * Reads the pre-calculated totals for each test category directly from the main table cells.
     * This ensures the subtest breakdown uses the exact same totals as displayed in the main table.
     * @param {HTMLElement} tableRow The doctor's <tr> element.
     * @returns {object} An object mapping category names to their totals (e.g., { MRI: { count: 35, revenue: 745248.00 } }).
     */
    function getTotalsFromTableRow(tableRow) {
        const totals = {};
        const cells = tableRow.cells;
        
        // The first cell is the doctor's name, so data starts from the second cell (index 1).
        // Each category takes up 2 cells (count and revenue).
        if (window.mainTestHeaders && Array.isArray(window.mainTestHeaders)) {
            window.mainTestHeaders.forEach((header, index) => {
                const countCellIndex = 1 + (index * 2);
                const revenueCellIndex = 2 + (index * 2);
                
                const count = parseInt(cells[countCellIndex]?.textContent || '0', 10);
                const revenueText = cells[revenueCellIndex]?.textContent.replace(/[₹,]/g, '') || '0';
                const revenue = parseFloat(revenueText);
                
                totals[header] = { count, revenue };
            });
        }
        
        return totals;
    }

    /**
     * Fetches subtest data from the server via AJAX.
     * @param {string} doctorId - The ID of the doctor to fetch data for.
     * @param {HTMLElement} contentElement - The container where the results will be rendered.
     * @param {object} categoryTotals - The totals read from the main table row (single source of truth).
     */
    function loadSubtestData(doctorId, contentElement, categoryTotals) {
        // Display a loading spinner immediately
        contentElement.innerHTML = `
            <div style="text-align: center; padding: 30px; background: #f8f9fc;">
                <div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #4e73df; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 15px;"></div>
                <p style="color: #5a5c69; margin: 0;">Loading subtest details with current filters...</p>
            </div>
        `;

        const url = `ajax_doctor_subtest_details.php?${currentFilterParams}&doctor_id=${doctorId}`;
        
        // Debug logging to verify filters are being applied
        console.log('Loading subtest data with filters:', currentFilterParams);

        fetch(url)
            .then(response => {
                if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                return response.json();
            })
            .then(data => {
                if (data.error) throw new Error(data.error);
                
                // Pass both the AJAX data and the totals from the main table to the renderer
                renderSubtestDetails(data, contentElement, categoryTotals);
                contentElement.setAttribute('data-loaded', 'true');
            })
            .catch(error => {
                console.error('Error loading subtest details:', error);
                contentElement.innerHTML = `
                    <div style="padding: 30px; text-align: center; background: #fff3cd; border-left: 4px solid #ffc107;">
                        <p style="color: #856404; margin: 0; font-weight: 600;">⚠️ Failed to load subtest details</p>
                        <p style="color: #856404; margin: 10px 0 0 0; font-size: 13px;">${error.message}</p>
                        <button onclick="location.reload()" style="margin-top: 15px; padding: 8px 16px; background: #ffc107; border: none; border-radius: 4px; cursor: pointer;">Reload Page</button>
                    </div>
                `;
            });
    }

    /**
     * Renders the final HTML, using totals from the main table as the source of truth.
     * This ensures perfect data consistency between the main table and subtest breakdown.
     * @param {object} data - The JSON data from the server.
     * @param {HTMLElement} contentElement - The container for the rendered HTML.
     * @param {object} categoryTotals - The totals object passed from the main table row.
     */
    function renderSubtestDetails(data, contentElement, categoryTotals) {
        if (!data.subtest_data || Object.keys(data.subtest_data).length === 0) {
            contentElement.innerHTML = `
                <div style="padding: 30px; text-align: center; background: #f8f9fc; color: #858796;">
                    <p style="margin: 0; font-size: 15px;">📊 No subtest data available for this doctor in the selected period.</p>
                    <p style="margin: 10px 0 0 0; font-size: 13px;">Try selecting a different date range or filters.</p>
                </div>
            `;
            return;
        }

        // Extract active filters from currentFilterParams for display
        const params = new URLSearchParams(currentFilterParams);
        const startDate = params.get('start_date') || 'N/A';
        const endDate = params.get('end_date') || 'N/A';
        const receptionistId = params.get('receptionist_id');
        
        let html = '<div class="subtest-details-container" style="background: #f8f9fc; padding: 20px 30px; border-left: 5px solid #4e73df;">';
        html += '<h4 style="margin: 0 0 10px 0; color: #5a5c69; font-size: 18px; font-weight: 600;">📊 Subtest Breakdown by Category</h4>';
        
        // Add active filters display
        html += `<div style="margin-bottom: 15px; padding: 10px; background: #e7f3ff; border-left: 3px solid #4e73df; border-radius: 4px; font-size: 12px; color: #5a5c69;">
                    <strong>🔍 Active Filters:</strong> 
                    Date: ${startDate} to ${endDate}`;
        if (receptionistId) {
            html += ` | Receptionist ID: ${receptionistId}`;
        }
        html += ` | <span style="color: #1cc88a;">✓ Matches main table filters</span>
                 </div>`;
        
        html += '<div class="subtest-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px;">';
        
        // Use the mainTestHeaders from the window to maintain order and consistency with the main table
        if (window.mainTestHeaders && Array.isArray(window.mainTestHeaders)) {
            window.mainTestHeaders.forEach(mainTest => {
                // Only render a card if there is breakdown data or a total for this category
                const subtests = data.subtest_data[mainTest];
                const totals = categoryTotals[mainTest];

                if (!subtests && (!totals || totals.count === 0)) {
                    return; // Skip rendering a card for categories with no data at all
                }

                html += '<div class="subtest-category-card" style="background: white; border-radius: 10px; padding: 18px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border: 1px solid #e3e6f0;">';
                html += `<h5 style="margin: 0 0 15px 0; color: #4e73df; font-size: 15px; font-weight: 700; border-bottom: 3px solid #4e73df; padding-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px;">${mainTest}</h5>`;
                
                html += '<div class="subtest-list" style="max-height: 400px; overflow-y: auto;">';
                
                if (subtests && subtests.length > 0) {
                    subtests.forEach((subtest, idx) => {
                        // Calculate percentage based on the TRUE total from the main table (single source of truth)
                        const percentage = totals && totals.count > 0 ? ((subtest.test_count / totals.count) * 100).toFixed(1) : 0;
                        const isLast = idx === subtests.length - 1;
                        
                        html += `
                            <div class="subtest-item" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 8px; ${!isLast ? 'border-bottom: 1px solid #e3e6f0;' : ''} font-size: 13px; transition: background 0.2s;">
                                <div style="flex: 1; min-width: 0;">
                                    <div style="color: #5a5c69; font-weight: 600; margin-bottom: 4px; overflow: hidden; text-overflow: ellipsis;">${subtest.sub_test_name}</div>
                                    <div style="color: #858796; font-size: 11px;">
                                        <span style="background: #e7f3ff; padding: 2px 8px; border-radius: 10px; display: inline-block;">${percentage}% of category</span>
                                    </div>
                                </div>
                                <div style="text-align: right; margin-left: 15px; flex-shrink: 0;">
                                    <div style="color: #4e73df; font-weight: 700; font-size: 16px; margin-bottom: 4px;">${subtest.test_count}</div>
                                    <div style="color: #1cc88a; font-size: 12px; font-weight: 600;">${formatCurrency(subtest.revenue)}</div>
                                </div>
                            </div>
                        `;
                    });
                }
                
                // Display the final totals for this category card using the values from the main table
                if (totals) {
                    html += `
                        <div class="subtest-total" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 10px 10px 10px; margin-top: 10px; border-top: 3px solid #e3e6f0; background: linear-gradient(to bottom, #f8f9fc 0%, white 100%);">
                            <div style="color: #5a5c69; font-weight: 700; font-size: 14px;">⭐ Total ${mainTest}</div>
                            <div style="text-align: right;">
                                <div style="color: #4e73df; font-weight: 700; font-size: 17px;">${totals.count} tests</div>
                                <div style="color: #1cc88a; font-size: 13px; font-weight: 600;">${formatCurrency(totals.revenue)}</div>
                            </div>
                        </div>
                    `;
                }
                
                html += '</div></div>';
            });
        }
        
        html += '</div></div>';
        
        contentElement.innerHTML = html;
    }

    const style = document.createElement('style');
    style.textContent = `
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .subtest-item:hover {
            background: #f8f9fc !important;
            border-radius: 6px;
        }
        .subtest-category-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
            transition: all 0.3s ease;
        }
        .subtest-list::-webkit-scrollbar {
            width: 8px;
        }
        .subtest-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        .subtest-list::-webkit-scrollbar-thumb {
            background: #4e73df;
            border-radius: 4px;
        }
        .subtest-list::-webkit-scrollbar-thumb:hover {
            background: #2e59d9;
        }
    `;
    document.head.appendChild(style);
})();

