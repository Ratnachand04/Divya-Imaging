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

    const initNavCollapseOnScroll = () => {
        const header = document.querySelector('.main-header');
        const navbar = document.querySelector('.main-navbar');
        if (!header || !navbar) return;

        const collapseOffset = () => header.getBoundingClientRect().height + 40;
        const mq = window.matchMedia('(max-width: 768px)');
        let ticking = false;

        const updateState = () => {
            const shouldCollapse = !mq.matches && window.scrollY > collapseOffset();
            document.body.classList.toggle('nav-collapsed', shouldCollapse);
            ticking = false;
        };

        window.addEventListener('scroll', () => {
            if (!ticking) {
                window.requestAnimationFrame(updateState);
                ticking = true;
            }
        }, { passive: true });

        mq.addEventListener ? mq.addEventListener('change', updateState) : mq.addListener(updateState);
        updateState();
    };

    // initNavCollapseOnScroll(); // Disabled to prevent blinking/glitches

    const initFloatingNavCollision = () => {
        // Disabled to prevent blinking/glitches
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
                case 'this_week': // Last 7 days including today
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

    // --- Initialize Select2 for searchable doctor dropdown ---
    if ($('#referral_doctor_id').length) {
        $('#referral_doctor_id').select2({
            placeholder: "Select or search for a doctor",
            allowClear: true,
            dropdownParent: $('#referral_doctor_id').parent()
        });
    }

    // --- Referral Dropdown Logic (for generate_bill.php) ---
    const referralTypeSelect = document.getElementById('referral_type');
    if (referralTypeSelect) {
        const doctorSelectGroup = document.getElementById('doctor-select-group');
        const otherDoctorNameGroup = document.getElementById('other-doctor-name-group');
        const otherSourceGroup = document.getElementById('other-source-group');
        const referralDoctorSelect = document.getElementById('referral_doctor_id');

        function toggleReferralFields() {
            const selectedValue = referralTypeSelect.value;
            if(doctorSelectGroup) doctorSelectGroup.style.display = 'none';
            if(otherDoctorNameGroup) otherDoctorNameGroup.style.display = 'none';
            if(otherSourceGroup) otherSourceGroup.style.display = 'none';

            if (selectedValue === 'Doctor') {
                if (doctorSelectGroup) doctorSelectGroup.style.display = 'block';
                if (referralDoctorSelect && referralDoctorSelect.value === 'other') {
                    if (otherDoctorNameGroup) otherDoctorNameGroup.style.display = 'block';
                }
            } else if (selectedValue === 'Other') {
                if (otherSourceGroup) otherSourceGroup.style.display = 'block';
            }
        }

        referralTypeSelect.addEventListener('change', toggleReferralFields);
        if (referralDoctorSelect) {
            referralDoctorSelect.addEventListener('change', function() {
                if (this.value === 'other') {
                    if (otherDoctorNameGroup) otherDoctorNameGroup.style.display = 'block';
                } else {
                    if (otherDoctorNameGroup) otherDoctorNameGroup.style.display = 'none';
                }
            });
        }
        toggleReferralFields();
    }

    // --- Test Selection & Bill Calculation ---
    const mainTestSelect = document.getElementById('main-test-select');
    const subTestSelect = document.getElementById('sub-test-select');
    
    if (mainTestSelect && subTestSelect && typeof testsData !== 'undefined') {
        mainTestSelect.addEventListener('change', function() {
            const selectedCategory = this.value;
            subTestSelect.innerHTML = '<option value="">-- Select Specific Test --</option>';
            if (selectedCategory && testsData[selectedCategory]) {
                subTestSelect.disabled = false;
                testsData[selectedCategory].forEach(test => {
                    const option = document.createElement('option');
                    option.value = test.id;
                    option.textContent = `${test.sub_test_name} (₹ ${test.price})`;
                    option.dataset.price = test.price;
                    option.dataset.name = `${test.main_test_name} - ${test.sub_test_name}`;
                    subTestSelect.appendChild(option);
                });
            } else {
                subTestSelect.disabled = true;
                subTestSelect.innerHTML = '<option value="">-- Select Category First --</option>';
            }
        });

        subTestSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (!selectedOption.value) return;
            // Use the globally accessible function to add the test
            if (typeof window.addTestToList === 'function') {
                window.addTestToList(selectedOption.value, selectedOption.dataset.name, selectedOption.dataset.price);
            }
            this.selectedIndex = 0;
            mainTestSelect.selectedIndex = 0;
            this.disabled = true;
        });
    }

    // --- Bill Calculation and List Management Logic ---
    const billForm = document.getElementById('bill-form');
    if (billForm) {
        const selectedTestsList = document.getElementById('selected-tests-list');
        const grossAmountInput = document.getElementById('gross_amount');
        const discountInput = document.getElementById('discount');
        if (discountInput) {
            discountInput.setAttribute('readonly', 'readonly');
        }
        const netAmountInput = document.getElementById('net_amount');
        const selectedTestsJsonInput = document.getElementById('selected_tests_json');

        // Payment Status Logic Elements
        const paymentStatusSelect = document.getElementById('payment_status');
        const halfPaidDetails = document.getElementById('half-paid-details');
        const amountPaidInput = document.getElementById('amount_paid');
        const balanceAmountInput = document.getElementById('balance_amount');

        const submitBtnForSimpleForm = billForm.querySelector('.btn-submit');
        const isDetailedBillForm = !!(grossAmountInput && netAmountInput && selectedTestsJsonInput);

        if (!isDetailedBillForm) {
            if (submitBtnForSimpleForm) {
                submitBtnForSimpleForm.disabled = false;
            }
        } else {
            let selectedTests = {};

        function highlightButtons(entry, activeButton) {
            if (!entry || !entry.ui) return;
            entry.ui.fixedButtons.forEach(btn => btn.classList.remove('active'));
            if (entry.ui.customButton) {
                entry.ui.customButton.classList.remove('active');
            }
            if (activeButton) {
                activeButton.classList.add('active');
            }
            if (entry.ui) {
                entry.ui.activeButton = activeButton || null;
            }
        }

        function toggleCustomVisibility(entry, shouldShow) {
            if (!entry || !entry.ui || !entry.ui.customContainer) return;
            entry.ui.customContainer.style.display = shouldShow ? 'flex' : 'none';
        }

        function updateScreeningSummary(entry) {
            if (!entry || !entry.ui || !entry.ui.summarySpan) return;
            const amount = entry.screeningAmount || 0;
            entry.ui.summarySpan.textContent = `Screening: ₹${amount.toFixed(2)}`;
        }

        function updateDiscountSummary(entry) {
            if (!entry || !entry.ui || !entry.ui.discountSummary) return;
            const amount = entry.discountAmount || 0;
            entry.ui.discountSummary.textContent = `Discount: ₹${amount.toFixed(2)}`;
        }

        function updateChargeSummary(entry) {
            if (!entry || !entry.ui || !entry.ui.chargeSummary) return;
            const base = parseFloat(entry.price) || 0;
            const screening = parseFloat(entry.screeningAmount) || 0;
            const gross = base + screening;
            const net = Math.max(gross - (parseFloat(entry.discountAmount) || 0), 0);
            entry.ui.chargeSummary.textContent = `Applied Amount: ₹${net.toFixed(2)}`;
        }

        function clampDiscountToGross(entry) {
            if (!entry) return;
            const gross = (parseFloat(entry.price) || 0) + (parseFloat(entry.screeningAmount) || 0);
            if (entry.discountAmount > gross) {
                entry.discountAmount = gross;
                if (entry.ui && entry.ui.discountInput) {
                    entry.ui.discountInput.value = gross.toFixed(2);
                }
            }
            updateDiscountSummary(entry);
        }

        function setScreeningAmount(testId, amount, activeButton) {
            const entry = selectedTests[testId];
            if (!entry) return;
            let normalizedAmount = parseFloat(amount);
            if (!isFinite(normalizedAmount) || normalizedAmount < 0) {
                normalizedAmount = 0;
            }

            const isToggleOff = activeButton && entry.ui && entry.ui.activeButton === activeButton && normalizedAmount === entry.screeningAmount;
            if (isToggleOff) {
                entry.screeningAmount = 0;
                if (entry.ui.customInput) {
                    entry.ui.customInput.value = '';
                }
                highlightButtons(entry, null);
                toggleCustomVisibility(entry, false);
            } else {
                entry.screeningAmount = normalizedAmount;
                highlightButtons(entry, activeButton);
                toggleCustomVisibility(entry, activeButton === entry.ui.customButton);
            }
            updateScreeningSummary(entry);
            clampDiscountToGross(entry);
            updateBill();
        }

        // Make addTestToList globally available so it can be called from edit_bill.php as well
        window.addTestToList = function(testId, testName, testPrice) {
            if (!testId || selectedTests[testId]) return;

            const basePrice = parseFloat(testPrice) || 0;

            const listItem = document.createElement('li');
            listItem.setAttribute('data-id', testId);

            const leftContainer = document.createElement('div');
            leftContainer.className = 'selected-test-left';

            const nameSpan = document.createElement('span');
            nameSpan.className = 'test-name';
            const displayPrice = isFinite(basePrice) ? basePrice.toFixed(2) : '0.00';
            nameSpan.textContent = `${testName} - ₹${displayPrice}`;
            leftContainer.appendChild(nameSpan);

            const buttonsWrap = document.createElement('div');
            buttonsWrap.className = 'screening-buttons';
            leftContainer.appendChild(buttonsWrap);

            const customContainer = document.createElement('div');
            customContainer.className = 'screening-custom-container';

            const customLabel = document.createElement('span');
            customLabel.className = 'screening-custom-label';
            customLabel.textContent = 'Enter amount:';
            customContainer.appendChild(customLabel);

            const customInput = document.createElement('input');
            customInput.type = 'number';
            customInput.min = '0';
            customInput.step = '0.01';
            customInput.placeholder = '0.00';
            customInput.className = 'screening-custom-input';
            customContainer.appendChild(customInput);

            leftContainer.appendChild(customContainer);

            const rightContainer = document.createElement('div');
            rightContainer.className = 'selected-test-right';

            const summarySpan = document.createElement('span');
            summarySpan.className = 'screening-summary';
            summarySpan.textContent = 'Screening: ₹0.00';
            rightContainer.appendChild(summarySpan);

            const discountSummary = document.createElement('span');
            discountSummary.className = 'discount-summary';
            discountSummary.textContent = 'Discount: ₹0.00';
            rightContainer.appendChild(discountSummary);

            const chargeSummary = document.createElement('span');
            chargeSummary.className = 'charge-summary';
            chargeSummary.textContent = 'Applied Amount: ₹0.00';
            rightContainer.appendChild(chargeSummary);

            const discountWrapper = document.createElement('div');
            discountWrapper.className = 'discount-input-wrapper';

            const discountLabel = document.createElement('label');
            discountLabel.textContent = 'Discount';
            discountLabel.setAttribute('for', `test-discount-${testId}`);
            discountWrapper.appendChild(discountLabel);

            const discountField = document.createElement('input');
            discountField.type = 'number';
            discountField.min = '0';
            discountField.step = '0.01';
            discountField.placeholder = 'Enter discount';
            discountField.value = '';
            discountField.id = `test-discount-${testId}`;
            discountField.className = 'test-discount-input';
            discountWrapper.appendChild(discountField);

            rightContainer.appendChild(discountWrapper);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.textContent = 'Remove';
            removeBtn.className = 'btn-remove';
            removeBtn.addEventListener('click', function() {
                delete selectedTests[testId];
                listItem.remove();
                updateBill();
            });
            rightContainer.appendChild(removeBtn);

            listItem.appendChild(leftContainer);
            listItem.appendChild(rightContainer);

            const screeningOptions = [
                { label: 'Screening 2000', amount: 2000 },
                { label: 'Screening 3000', amount: 3000 },
                { label: 'Screening 4000', amount: 4000 }
            ];

            const fixedButtons = [];

            screeningOptions.forEach(option => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'screening-button';
                btn.textContent = option.label;
                btn.addEventListener('click', () => {
                    const entry = selectedTests[testId];
                    if (!entry) return;
                    entry.ui.customInput.value = '';
                    toggleCustomVisibility(entry, false);
                    setScreeningAmount(testId, option.amount, btn);
                });
                buttonsWrap.appendChild(btn);
                fixedButtons.push(btn);
            });

            const customButton = document.createElement('button');
            customButton.type = 'button';
            customButton.className = 'screening-button';
            customButton.textContent = 'Customise';
            customButton.addEventListener('click', () => {
                const entry = selectedTests[testId];
                if (!entry) return;
                highlightButtons(entry, customButton);
                toggleCustomVisibility(entry, true);
                customInput.value = entry.screeningAmount > 0 ? entry.screeningAmount : '';
                customInput.focus();
            });
            buttonsWrap.appendChild(customButton);

            const testEntry = {
                name: testName,
                price: basePrice,
                screeningAmount: 0,
                discountAmount: 0,
                ui: {
                    listItem,
                    summarySpan,
                    discountSummary,
                    chargeSummary,
                    discountInput: discountField,
                    fixedButtons,
                    customButton,
                    customContainer,
                    customInput,
                    activeButton: null
                }
            };

            customInput.addEventListener('input', () => {
                const entry = selectedTests[testId];
                if (!entry) return;
                let value = parseFloat(customInput.value);
                if (!isFinite(value) || value < 0) {
                    value = 0;
                }
                setScreeningAmount(testId, value, customButton);
            });

            discountField.addEventListener('input', () => {
                const entry = selectedTests[testId];
                if (!entry) return;
                let value = parseFloat(discountField.value);
                if (!isFinite(value) || value < 0) {
                    value = 0;
                }
                entry.discountAmount = value;
                clampDiscountToGross(entry);
                updateBill();
            });

            customContainer.style.display = 'none';

            selectedTests[testId] = testEntry;

            if (selectedTestsList) {
                selectedTestsList.appendChild(listItem);
            }
            updateScreeningSummary(testEntry);
            updateDiscountSummary(testEntry);
            updateChargeSummary(testEntry);
            updateBill();
        };

        function updateBill() {
            let grossAmount = 0;
            let totalDiscount = 0;

            Object.values(selectedTests).forEach(test => {
                const base = parseFloat(test.price) || 0;
                const screening = parseFloat(test.screeningAmount) || 0;
                const itemGross = base + screening;
                test.discountAmount = Math.min(parseFloat(test.discountAmount) || 0, itemGross);
                grossAmount += itemGross;
                totalDiscount += test.discountAmount;
                updateScreeningSummary(test);
                updateDiscountSummary(test);
                updateChargeSummary(test);
            });

            if (grossAmount < 0) grossAmount = 0;
            if (totalDiscount > grossAmount) totalDiscount = grossAmount;

            const netAmount = grossAmount - totalDiscount;

            if (grossAmountInput) {
                grossAmountInput.value = grossAmount.toFixed(2);
            }
            if (discountInput) {
                discountInput.value = totalDiscount.toFixed(2);
            }
            if (netAmountInput) {
                netAmountInput.value = Math.max(netAmount, 0).toFixed(2);
            }

            // Update half-paid calculation whenever the bill total changes
            updateHalfPaid();

            const testPayload = Object.entries(selectedTests).map(([id, data]) => ({
                id: parseInt(id, 10) || 0,
                screening: parseFloat(data.screeningAmount) || 0,
                discount: parseFloat(data.discountAmount) || 0
            }));
            if (selectedTestsJsonInput) {
                selectedTestsJsonInput.value = JSON.stringify(testPayload);
            }
            const submitBtn = billForm.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.disabled = testPayload.length === 0;
            }
        }
        
        function updateHalfPaid() {
            // Ensure all required elements exist before proceeding
            if (!paymentStatusSelect || !halfPaidDetails || !netAmountInput || !amountPaidInput || !balanceAmountInput) return;
            
            if (paymentStatusSelect.value === 'Half Paid') {
                halfPaidDetails.style.display = 'flex'; // Use 'flex' to show the row
                let netAmount = parseFloat(netAmountInput.value) || 0;
                let amountPaid = parseFloat(amountPaidInput.value) || 0;
                
                // Prevent paying more than the net amount
                if (amountPaid > netAmount) {
                    amountPaid = netAmount;
                    amountPaidInput.value = amountPaid.toFixed(2);
                }

                let balance = netAmount - amountPaid;
                balanceAmountInput.value = balance.toFixed(2);
                amountPaidInput.max = netAmount.toFixed(2); // Set max attribute for validation
            } else {
                halfPaidDetails.style.display = 'none'; // Hide the section
                amountPaidInput.value = ''; // Clear the values when not visible
                balanceAmountInput.value = '';
            }
        }

        // Attach event listeners
        if(paymentStatusSelect) {
            paymentStatusSelect.addEventListener('change', updateHalfPaid);
        }
        if(amountPaidInput) {
            amountPaidInput.addEventListener('input', updateHalfPaid);
        }

        // Initial call to set the correct state when the page loads
        updateBill();
        }
    }


    // --- Bill History Live Search Logic ---
    const billSearchInput = document.getElementById('bill-search');
    const billHistoryTableBody = document.getElementById('bill-history-table-body');
    const paginationContainer = document.querySelector('.pagination');

    if (billSearchInput && billHistoryTableBody) {
        billSearchInput.addEventListener('keyup', function() {
            const searchTerm = this.value;
            if (paginationContainer) {
                paginationContainer.style.display = searchTerm ? 'none' : 'block';
            }
            // Use the correct path for the AJAX handler
            fetch(`ajax_handler.php?search=${encodeURIComponent(searchTerm)}`)
                .then(response => response.text())
                .then(data => {
                    billHistoryTableBody.innerHTML = data;
                })
                .catch(error => {
                    console.error('Error:', error);
                    billHistoryTableBody.innerHTML = '<tr><td colspan="6">Error loading data.</td></tr>';
                });
        });
    }

    // --- Manager Analytics: Dynamic Filters (No changes needed here) ---
    const analyticsReferralType = document.getElementById('analytics_referral_type');
    const analyticsDoctorFilter = document.getElementById('analytics_doctor_filter');
    const analyticsMainTest = document.getElementById('analytics_main_test');
    const analyticsSubTest = document.getElementById('analytics_sub_test');

    function toggleAnalyticsDoctorFilter() {
        if (analyticsReferralType && analyticsDoctorFilter) {
            analyticsDoctorFilter.style.display = (analyticsReferralType.value === 'Doctor') ? 'block' : 'none';
        }
    }

    function populateSubTests() {
        if (analyticsMainTest && analyticsSubTest && typeof allTestsData !== 'undefined') {
            const selectedCategory = analyticsMainTest.value;
            analyticsSubTest.innerHTML = '<option value="all">All Tests</option>';
            if (selectedCategory && allTestsData[selectedCategory]) {
                allTestsData[selectedCategory].forEach(test => {
                    const option = document.createElement('option');
                    option.value = test.id;
                    option.textContent = test.name;
                    if (test.id == currentSubTestId) {
                        option.selected = true;
                    }
                    analyticsSubTest.appendChild(option);
                });
            }
        }
    }

    if (analyticsReferralType) {
        analyticsReferralType.addEventListener('change', toggleAnalyticsDoctorFilter);
        toggleAnalyticsDoctorFilter();
    }

    if (analyticsMainTest) {
        analyticsMainTest.addEventListener('change', populateSubTests);
        populateSubTests();
    }

    const bodyElement = document.body;
    const roleMatch = bodyElement ? bodyElement.className.match(/role-([a-z]+)/i) : null;
    const currentRole = roleMatch ? roleMatch[1] : '';
    const NOTIFICATION_API = (typeof SITE_BASE_URL !== 'undefined' ? SITE_BASE_URL : '') + '/api/notification_status.php';

    const POPUP_HOST_ID = 'global-popup-container';
    const HALF_PAID_STORAGE_KEY = 'dc_half_paid_notice';
    const LAST_REQUEST_STORAGE_KEY = 'dc_manager_last_request_id';

    function ensurePopupHost() {
        let host = document.getElementById(POPUP_HOST_ID);
        if (!host) {
            host = document.createElement('div');
            host.id = POPUP_HOST_ID;
            host.setAttribute('role', 'alert');
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }
        return host;
    }

    function showPopup({ title, lines = [], actions = [] }) {
        const host = ensurePopupHost();
        const wrapper = document.createElement('div');
        wrapper.className = 'global-popup';

        const header = document.createElement('div');
        header.className = 'global-popup-header';
        const titleEl = document.createElement('h4');
        titleEl.textContent = title;
        header.appendChild(titleEl);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'global-popup-close';
        closeBtn.setAttribute('aria-label', 'Dismiss notification');
        closeBtn.innerHTML = '&times;';
        header.appendChild(closeBtn);
        wrapper.appendChild(header);

        const body = document.createElement('div');
        body.className = 'global-popup-body';
        lines.forEach(line => {
            if (!line) return;
            if (typeof line === 'string') {
                const p = document.createElement('p');
                p.textContent = line;
                body.appendChild(p);
            } else if (line instanceof HTMLElement) {
                body.appendChild(line);
            }
        });
        wrapper.appendChild(body);

        const actionsToRender = actions.length ? actions : [{ label: 'Close' }];
        const actionsContainer = document.createElement('div');
        actionsContainer.className = 'global-popup-actions';

        const closePopup = () => {
            if (wrapper.parentNode) {
                wrapper.parentNode.removeChild(wrapper);
            }
        };

        closeBtn.addEventListener('click', closePopup);

        actionsToRender.forEach(action => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = action.label || 'Close';
            btn.className = 'popup-action-btn';
            if (action.variant) {
                btn.classList.add(`variant-${action.variant}`);
            }
            btn.addEventListener('click', () => {
                let shouldClose = true;
                if (typeof action.onClick === 'function') {
                    const result = action.onClick();
                    if (result === false) {
                        shouldClose = false;
                    }
                }
                if (shouldClose) {
                    closePopup();
                }
            });
            actionsContainer.appendChild(btn);
        });

        wrapper.appendChild(actionsContainer);
        host.appendChild(wrapper);
        return closePopup;
    }

    function formatCurrency(amount) {
        const numeric = parseFloat(amount) || 0;
        return `₹${numeric.toFixed(2)}`;
    }

    function formatTimestamp(ts) {
        if (!ts) return '';
        const normalized = ts.replace(' ', 'T');
        const date = new Date(normalized);
        if (Number.isNaN(date.getTime())) {
            return ts;
        }
        return date.toLocaleString();
    }

    function computeHalfPaidSignature(bills) {
        if (!Array.isArray(bills) || bills.length === 0) {
            return 'none';
        }
        return bills.map(bill => `${bill.id}:${parseFloat(bill.balance_amount || bill.balanceAmount || 0)}`).join('|');
    }

    function shouldShowHalfPaid(signature) {
        try {
            const raw = sessionStorage.getItem(HALF_PAID_STORAGE_KEY);
            if (raw) {
                const data = JSON.parse(raw);
                if (data.signature === signature && data.timestamp && (Date.now() - data.timestamp) < (55 * 60 * 1000)) {
                    return false;
                }
            }
        } catch (error) {
            console.warn('Unable to read half-paid notification state', error);
        }
        return true;
    }

    function markHalfPaidShown(signature) {
        try {
            sessionStorage.setItem(HALF_PAID_STORAGE_KEY, JSON.stringify({ signature, timestamp: Date.now() }));
        } catch (error) {
            console.warn('Unable to persist half-paid notification state', error);
        }
    }

    function presentHalfPaidNotification(payload) {
        if (!payload || !Array.isArray(payload.bills) || !payload.bills.length) return;
        const count = payload.count || payload.bills.length;
        const list = document.createElement('ul');
        list.className = 'global-popup-list';
        payload.bills.forEach(bill => {
            const item = document.createElement('li');
            const balance = formatCurrency(bill.balance_amount ?? bill.balanceAmount ?? 0);
            item.textContent = `Bill #${bill.id} • ${bill.patient_name} • Balance ${balance}`;
            list.appendChild(item);
        });

        const summary = count === 1
            ? 'There is 1 half-paid bill awaiting attention.'
            : `There are ${count} half-paid bills awaiting attention.`;

        const todayStr = new Date().toISOString().slice(0, 10);
        const earliestDate = '2000-01-01';
        const actionLink = currentRole === 'manager'
            ? `${typeof SITE_BASE_URL !== 'undefined' ? SITE_BASE_URL : ''}/manager/view_due_bills.php?all_dates=1&start_date=${earliestDate}&end_date=${todayStr}&status=pending`
            : `${typeof SITE_BASE_URL !== 'undefined' ? SITE_BASE_URL : ''}/receptionist/bill_history.php?all_dates=1&start_date=${earliestDate}&end_date=${todayStr}&payment_status=pending`;

        const actions = [
            {
                label: 'Review Bills',
                variant: 'primary',
                onClick: () => {
                    window.location.href = actionLink;
                }
            },
            { label: 'Dismiss', variant: 'secondary' }
        ];

        showPopup({
            title: 'Half-Paid Bills Reminder',
            lines: [summary, list],
            actions
        });
    }

    function performHalfPaidCheck() {
        fetch(`${NOTIFICATION_API}?action=half_paid`, { cache: 'no-store' })
            .then(response => response.json())
            .then(data => {
                if (!data.success || !data.data) return;
                const { bills, count } = data.data;
                if (!count) return;
                const signature = computeHalfPaidSignature(bills);
                if (shouldShowHalfPaid(signature)) {
                    presentHalfPaidNotification({ bills, count });
                    markHalfPaidShown(signature);
                }
            })
            .catch(error => console.error('Half-paid check failed:', error));
    }

    function scheduleHalfPaidChecks() {
        performHalfPaidCheck();
        setInterval(performHalfPaidCheck, 60 * 60 * 1000); // hourly
    }

    function updateNavBadge(key, value) {
        const badge = document.querySelector(`.nav-badge[data-nav-count="${key}"]`);
        if (!badge) return;
        const count = Math.max(0, parseInt(value, 10) || 0);
        badge.textContent = count;
        if (count === 0) {
            badge.classList.add('is-hidden');
        } else {
            badge.classList.remove('is-hidden');
        }
    }

    function refreshNavCounts() {
        fetch(`${NOTIFICATION_API}?action=manager_nav_counts`, { cache: 'no-store' })
            .then(response => response.json())
            .then(data => {
                if (!data.success || !data.counts) return;
                updateNavBadge('requests', data.counts.requests);
                updateNavBadge('pending-bills', data.counts.pending_bills);
                updateNavBadge('pending-reports', data.counts.pending_reports);
            })
            .catch(error => console.error('Failed to refresh nav counts:', error));
    }

    function scheduleNavCounts() {
        refreshNavCounts();
        setInterval(refreshNavCounts, 60 * 1000);
    }

    function getStoredRequestId() {
        const raw = sessionStorage.getItem(LAST_REQUEST_STORAGE_KEY);
        return raw ? parseInt(raw, 10) || 0 : 0;
    }

    function setStoredRequestId(id) {
        try {
            sessionStorage.setItem(LAST_REQUEST_STORAGE_KEY, String(id));
        } catch (error) {
            console.warn('Unable to persist latest request id', error);
        }
    }

    function presentNewRequestNotification(request) {
        if (!request) return;
        const lines = [
            `Receptionist ${request.receptionist} submitted a new edit request for Bill #${request.bill_id}.`,
            `Reason: ${request.reason}`,
            `Received at: ${formatTimestamp(request.created_at)}`
        ];
        const actions = [
            {
                label: 'Open Requests',
                variant: 'primary',
                onClick: () => {
                    window.location.href = (typeof SITE_BASE_URL !== 'undefined' ? SITE_BASE_URL : '') + '/manager/requests.php';
                }
            },
            { label: 'Dismiss', variant: 'secondary' }
        ];
        showPopup({ title: 'New Bill Edit Request', lines, actions });
    }

    function checkForNewRequests(initial = false) {
        const lastSeen = getStoredRequestId();
        fetch(`${NOTIFICATION_API}?action=latest_request&last_request_id=${lastSeen}`, { cache: 'no-store' })
            .then(response => response.json())
            .then(data => {
                if (!data.success || !data.latest) {
                    return;
                }
                const latestId = parseInt(data.latest.id, 10) || 0;
                if (initial && lastSeen === 0) {
                    setStoredRequestId(latestId);
                    return;
                }
                if (data.hasNew && latestId > lastSeen) {
                    setStoredRequestId(latestId);
                    presentNewRequestNotification(data.latest);
                    refreshNavCounts();
                } else if (initial && latestId > lastSeen) {
                    setStoredRequestId(latestId);
                }
            })
            .catch(error => console.error('Latest request check failed:', error));
    }

    function scheduleRequestWatcher() {
        checkForNewRequests(true);
        setInterval(() => checkForNewRequests(false), 30 * 1000);
    }

    if (currentRole === 'manager' || currentRole === 'receptionist') {
        scheduleHalfPaidChecks();
    }

    if (currentRole === 'manager') {
        scheduleNavCounts();
        scheduleRequestWatcher();
    }
});

