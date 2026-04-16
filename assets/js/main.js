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
    const packageSelect = document.getElementById('package-select');
    
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

    if (packageSelect && typeof packagesData !== 'undefined') {
        packageSelect.addEventListener('change', function() {
            const selectedPackageId = this.value;
            if (!selectedPackageId || !packagesData[selectedPackageId]) {
                return;
            }

            if (typeof window.addPackageToList === 'function') {
                window.addPackageToList(selectedPackageId, packagesData[selectedPackageId]);
            }

            this.selectedIndex = 0;
        });
    }

    // --- Bill Calculation and List Management Logic ---
    const billForm = document.getElementById('bill-form');
    if (billForm) {
        const selectedTestsList = document.getElementById('selected-tests-list');
        const selectedPackagesList = document.getElementById('selected-packages-list');
        const selectedTestsSection = document.getElementById('selected-tests');
        const selectedPackagesSection = document.getElementById('selected-packages');
        const grossAmountInput = document.getElementById('gross_amount');
        const discountInput = document.getElementById('discount');
        if (discountInput) {
            discountInput.setAttribute('readonly', 'readonly');
        }
        const netAmountInput = document.getElementById('net_amount');
        const selectedTestsJsonInput = document.getElementById('selected_tests_json');

        // Payment Status Logic Elements
        const paymentStatusSelect = document.getElementById('payment_status');
        const partialPaidDetails = document.getElementById('partial-paid-details');
        const amountPaidInput = document.getElementById('amount_paid');
        const balanceAmountInput = document.getElementById('balance_amount');
        const paymentModeSelect = document.getElementById('payment_mode');
        const discountBySelect = document.getElementById('discount_by');
        const splitPaymentDetails = document.getElementById('split-payment-details');
        const splitPaymentNote = document.getElementById('split-payment-note');
        const splitTotalDisplay = document.getElementById('split-total-display');
        const splitRequiredDisplay = document.getElementById('split-required-display');
        const splitCashGroup = document.getElementById('split-cash-group');
        const splitCardGroup = document.getElementById('split-card-group');
        const splitUpiGroup = document.getElementById('split-upi-group');
        const splitCashInput = document.getElementById('split_cash_amount');
        const splitCardInput = document.getElementById('split_card_amount');
        const splitUpiInput = document.getElementById('split_upi_amount');

        const submitBtnForSimpleForm = billForm.querySelector('.btn-submit');
        const isDetailedBillForm = !!(grossAmountInput && netAmountInput && selectedTestsJsonInput);

        if (!isDetailedBillForm) {
            if (submitBtnForSimpleForm) {
                submitBtnForSimpleForm.disabled = false;
            }
        } else {
            let selectedTests = {};
            let selectedPackages = {};

        function updateSelectionSectionVisibility() {
            if (selectedTestsSection) {
                selectedTestsSection.classList.toggle('is-empty', Object.keys(selectedTests).length === 0);
            }

            if (selectedPackagesSection) {
                const hasPackages = Object.keys(selectedPackages).length > 0;
                selectedPackagesSection.style.display = hasPackages ? 'block' : 'none';
                selectedPackagesSection.setAttribute('aria-hidden', hasPackages ? 'false' : 'true');
            }
        }

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

        // Package selection list for bundled tests.
        window.addPackageToList = function(packageId, packageData) {
            const normalizedId = String(packageId || '');
            if (!normalizedId || selectedPackages[normalizedId] || !packageData) {
                return;
            }

            const packageName = packageData.package_name || 'Package';
            const packageCode = packageData.package_code || '';
            const packagePrice = parseFloat(packageData.package_price) || 0;
            const baseTotal = parseFloat(packageData.total_base_price) || 0;
            const discountAmount = Math.max(baseTotal - packagePrice, 0);
            const tests = Array.isArray(packageData.tests) ? packageData.tests : [];

            const listItem = document.createElement('li');
            listItem.className = 'selected-package-item';
            listItem.setAttribute('data-package-id', normalizedId);

            const leftContainer = document.createElement('div');
            leftContainer.className = 'selected-test-left';

            const titleSpan = document.createElement('span');
            titleSpan.className = 'test-name';
            titleSpan.textContent = `${packageName} (PACKAGE${packageCode ? ' - ' + packageCode : ''})`;
            leftContainer.appendChild(titleSpan);

            const packageMeta = document.createElement('span');
            packageMeta.className = 'discount-summary';
            packageMeta.textContent = `Original: ₹${baseTotal.toFixed(2)} | Package: ₹${packagePrice.toFixed(2)} | Discount: ₹${discountAmount.toFixed(2)}`;
            leftContainer.appendChild(packageMeta);

            if (tests.length > 0) {
                const includeList = document.createElement('div');
                includeList.className = 'package-includes';
                const includeLabel = document.createElement('span');
                includeLabel.className = 'screening-summary';
                includeLabel.textContent = 'Included Tests:';
                includeList.appendChild(includeLabel);

                const includeUl = document.createElement('ul');
                includeUl.className = 'package-includes-list';
                tests.forEach(test => {
                    const li = document.createElement('li');
                    const label = test.test_name || 'Unnamed Test';
                    const testPrice = parseFloat(test.package_test_price) || 0;
                    li.textContent = `${label} - ₹${testPrice.toFixed(2)}`;
                    includeUl.appendChild(li);
                });
                includeList.appendChild(includeUl);
                leftContainer.appendChild(includeList);
            }

            const rightContainer = document.createElement('div');
            rightContainer.className = 'selected-test-right';

            const amountBadge = document.createElement('span');
            amountBadge.className = 'charge-summary';
            amountBadge.textContent = `Package Amount: ₹${packagePrice.toFixed(2)}`;
            rightContainer.appendChild(amountBadge);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.textContent = 'Remove';
            removeBtn.className = 'btn-remove';
            removeBtn.addEventListener('click', function() {
                delete selectedPackages[normalizedId];
                listItem.remove();
                updateBill();
            });
            rightContainer.appendChild(removeBtn);

            listItem.appendChild(leftContainer);
            listItem.appendChild(rightContainer);

            selectedPackages[normalizedId] = {
                id: parseInt(normalizedId, 10) || 0,
                name: packageName,
                code: packageCode,
                packagePrice,
                baseTotal,
                discountAmount,
                tests
            };

            if (selectedPackagesList) {
                selectedPackagesList.appendChild(listItem);
            }

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

            Object.values(selectedPackages).forEach(pkg => {
                const baseTotal = parseFloat(pkg.baseTotal) || 0;
                const packagePrice = parseFloat(pkg.packagePrice) || 0;
                const packageDiscount = Math.max(baseTotal - packagePrice, 0);

                if (packagePrice <= baseTotal) {
                    grossAmount += baseTotal;
                    totalDiscount += packageDiscount;
                } else {
                    grossAmount += packagePrice;
                }
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
            if (discountBySelect) {
                const hasDiscount = totalDiscount > 0.0001;
                discountBySelect.required = hasDiscount;
                if (!hasDiscount) {
                    discountBySelect.setCustomValidity('');
                }
            }
            if (netAmountInput) {
                netAmountInput.value = Math.max(netAmount, 0).toFixed(2);
            }

            // Update partial-paid calculation whenever the bill total changes
            updatePartialPaid();

            const testPayload = Object.entries(selectedTests).map(([id, data]) => ({
                id: parseInt(id, 10) || 0,
                item_type: 'test',
                screening: parseFloat(data.screeningAmount) || 0,
                discount: parseFloat(data.discountAmount) || 0
            }));

            const packagePayload = Object.entries(selectedPackages).map(([id]) => ({
                id: parseInt(id, 10) || 0,
                item_type: 'package'
            }));

            const finalPayload = testPayload.concat(packagePayload);
            if (selectedTestsJsonInput) {
                selectedTestsJsonInput.value = JSON.stringify(finalPayload);
            }

            updateSelectionSectionVisibility();

            const submitBtn = billForm.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.disabled = finalPayload.length === 0;
            }
        }

        function parseAmount(value) {
            const parsed = parseFloat(value);
            if (!isFinite(parsed) || parsed < 0) {
                return 0;
            }
            return parsed;
        }

        function isCombinedMode(mode) {
            return mode === 'Cash + Card' || mode === 'UPI + Cash' || mode === 'Card + UPI';
        }

        const splitFieldMap = {
            cash: { group: splitCashGroup, input: splitCashInput, label: 'Cash Amount' },
            card: { group: splitCardGroup, input: splitCardInput, label: 'Card Amount' },
            upi: { group: splitUpiGroup, input: splitUpiInput, label: 'UPI Amount' }
        };

        function getSplitModeConfig(mode) {
            if (mode === 'Cash + Card') {
                return { keys: ['cash', 'card'] };
            }
            if (mode === 'UPI + Cash') {
                return { keys: ['upi', 'cash'] };
            }
            if (mode === 'Card + UPI') {
                return { keys: ['card', 'upi'] };
            }
            return null;
        }

        function getSplitModeFields(mode) {
            const config = getSplitModeConfig(mode);
            if (!config) {
                return null;
            }

            const first = splitFieldMap[config.keys[0]];
            const second = splitFieldMap[config.keys[1]];
            if (!first || !second || !first.input || !second.input) {
                return null;
            }

            return {
                keys: config.keys,
                first,
                second
            };
        }

        function getExpectedPaymentAmount() {
            const netAmount = parseAmount(netAmountInput ? netAmountInput.value : 0);
            if (!paymentStatusSelect) {
                return netAmount;
            }

            if (paymentStatusSelect.value === 'Paid') {
                return netAmount;
            }
            if (paymentStatusSelect.value === 'Partial Paid') {
                const entered = parseAmount(amountPaidInput ? amountPaidInput.value : 0);
                return Math.min(entered, netAmount);
            }
            return 0;
        }

        function resetSplitInput(inputEl) {
            if (!inputEl) return;
            inputEl.required = false;
            inputEl.value = '';
            inputEl.setCustomValidity('');
            inputEl.removeAttribute('max');
        }

        function toggleSplitField(groupEl, inputEl, shouldShow, expectedAmount) {
            if (!groupEl || !inputEl) return;
            groupEl.style.display = shouldShow ? 'block' : 'none';
            inputEl.required = shouldShow;
            inputEl.setCustomValidity('');
            if (shouldShow) {
                inputEl.max = expectedAmount.toFixed(2);
            } else {
                inputEl.value = '';
                inputEl.removeAttribute('max');
            }
        }

        function updateSplitTotalDisplay() {
            if (!splitTotalDisplay) return;

            const total = parseAmount(splitCashInput ? splitCashInput.value : 0)
                + parseAmount(splitCardInput ? splitCardInput.value : 0)
                + parseAmount(splitUpiInput ? splitUpiInput.value : 0);
            const expected = getExpectedPaymentAmount();

            splitTotalDisplay.textContent = `₹${total.toFixed(2)}`;
            if (splitRequiredDisplay) {
                splitRequiredDisplay.textContent = `₹${expected.toFixed(2)}`;
            } else {
                splitTotalDisplay.textContent = `₹${total.toFixed(2)} (Required: ₹${expected.toFixed(2)})`;
            }
        }

        function autoBalanceSplitFields(changedKey, showValidation = false) {
            const modeFields = getSplitModeFields(paymentModeSelect ? paymentModeSelect.value : '');
            if (!modeFields || !modeFields.keys.includes(changedKey)) {
                updateSplitTotalDisplay();
                return;
            }

            const expectedAmount = getExpectedPaymentAmount();
            if (expectedAmount <= 0.0001) {
                updateSplitTotalDisplay();
                return;
            }

            const sourceField = splitFieldMap[changedKey];
            const companionKey = modeFields.keys[0] === changedKey ? modeFields.keys[1] : modeFields.keys[0];
            const companionField = splitFieldMap[companionKey];
            if (!sourceField || !companionField || !sourceField.input || !companionField.input) {
                updateSplitTotalDisplay();
                return;
            }

            const raw = sourceField.input.value.trim();
            sourceField.input.setCustomValidity('');

            if (raw === '') {
                companionField.input.value = '';
                updateSplitTotalDisplay();
                return;
            }

            const entered = parseFloat(raw);
            if (!isFinite(entered) || entered < 0) {
                sourceField.input.setCustomValidity(`${sourceField.label} must be a valid non-negative amount.`);
                if (showValidation) {
                    sourceField.input.reportValidity();
                }
                companionField.input.value = '';
                updateSplitTotalDisplay();
                return;
            }

            if (entered > expectedAmount + 0.01) {
                sourceField.input.setCustomValidity(`${sourceField.label} cannot exceed the required payable amount.`);
                if (showValidation) {
                    sourceField.input.reportValidity();
                }
                companionField.input.value = '';
                updateSplitTotalDisplay();
                return;
            }

            const remaining = Math.max(expectedAmount - entered, 0);
            companionField.input.setCustomValidity('');
            companionField.input.value = remaining.toFixed(2);
            updateSplitTotalDisplay();
        }

        function syncSplitPaymentFields() {
            if (!paymentModeSelect || !splitPaymentDetails) return;

            const mode = paymentModeSelect.value;
            const expectedAmount = getExpectedPaymentAmount();
            const modeFields = getSplitModeFields(mode);
            const shouldShowCombined = !!modeFields && expectedAmount > 0.0001;

            if (!shouldShowCombined) {
                splitPaymentDetails.style.display = 'none';
                if (splitPaymentNote) {
                    splitPaymentNote.style.display = 'none';
                }
                Object.values(splitFieldMap).forEach(function(field) {
                    resetSplitInput(field.input);
                    if (field.group) {
                        field.group.style.display = 'none';
                    }
                });
                updateSplitTotalDisplay();
                return;
            }

            splitPaymentDetails.style.display = 'flex';
            if (splitPaymentNote) {
                splitPaymentNote.style.display = 'block';
            }

            const orderedKeys = modeFields.keys;
            Object.keys(splitFieldMap).forEach(function(key) {
                const field = splitFieldMap[key];
                const shouldShow = orderedKeys.includes(key);
                toggleSplitField(field.group, field.input, shouldShow, expectedAmount);
                if (shouldShow && field.group) {
                    field.group.style.order = String(orderedKeys.indexOf(key) + 1);
                }
            });

            const firstKey = orderedKeys[0];
            const secondKey = orderedKeys[1];
            const firstInput = splitFieldMap[firstKey] ? splitFieldMap[firstKey].input : null;
            const secondInput = splitFieldMap[secondKey] ? splitFieldMap[secondKey].input : null;
            const activeElement = document.activeElement;

            if (activeElement === firstInput) {
                autoBalanceSplitFields(firstKey, false);
            } else if (activeElement === secondInput) {
                autoBalanceSplitFields(secondKey, false);
            } else if (firstInput && firstInput.value.trim() !== '') {
                autoBalanceSplitFields(firstKey, false);
            } else if (secondInput && secondInput.value.trim() !== '') {
                autoBalanceSplitFields(secondKey, false);
            } else {
                updateSplitTotalDisplay();
            }
        }

        function validateSplitInputs() {
            if (!paymentModeSelect || !isCombinedMode(paymentModeSelect.value)) {
                return true;
            }

            const expectedAmount = getExpectedPaymentAmount();
            if (expectedAmount <= 0.0001) {
                return true;
            }

            const modeFields = getSplitModeFields(paymentModeSelect.value);
            if (!modeFields) {
                return true;
            }

            const firstInput = modeFields.first.input;
            const secondInput = modeFields.second.input;
            const firstLabel = modeFields.first.label;
            const secondLabel = modeFields.second.label;

            if (!firstInput || !secondInput) {
                return true;
            }

            firstInput.setCustomValidity('');
            secondInput.setCustomValidity('');

            const rawFirst = firstInput.value.trim();
            const rawSecond = secondInput.value.trim();

            if (rawFirst === '' || rawSecond === '') {
                secondInput.setCustomValidity(`Enter both ${firstLabel} and ${secondLabel} split amounts.`);
                secondInput.reportValidity();
                return false;
            }

            const firstVal = parseFloat(rawFirst);
            const secondVal = parseFloat(rawSecond);

            if (!isFinite(firstVal) || !isFinite(secondVal) || firstVal < 0 || secondVal < 0) {
                secondInput.setCustomValidity('Split amounts must be valid non-negative numbers.');
                secondInput.reportValidity();
                return false;
            }

            if (firstVal <= 0 || secondVal <= 0) {
                secondInput.setCustomValidity(`${firstLabel} and ${secondLabel} must be greater than zero.`);
                secondInput.reportValidity();
                return false;
            }

            if (firstVal > expectedAmount + 0.01 || secondVal > expectedAmount + 0.01) {
                secondInput.setCustomValidity('Split amount in a single field cannot exceed the required payable amount.');
                secondInput.reportValidity();
                return false;
            }

            const total = firstVal + secondVal;
            if (total > expectedAmount + 0.01) {
                secondInput.setCustomValidity('Split total cannot exceed the payable amount.');
                secondInput.reportValidity();
                return false;
            }

            if (Math.abs(total - expectedAmount) > 0.01) {
                secondInput.setCustomValidity('Split total must exactly match the payable amount.');
                secondInput.reportValidity();
                return false;
            }

            return true;
        }
        
        function updatePartialPaid() {
            // Ensure all required elements exist before proceeding
            if (!paymentStatusSelect || !partialPaidDetails || !netAmountInput || !amountPaidInput || !balanceAmountInput) return;
            
            if (paymentStatusSelect.value === 'Partial Paid') {
                partialPaidDetails.style.display = 'flex'; // Use 'flex' to show the row
                let netAmount = parseFloat(netAmountInput.value) || 0;
                let amountPaid = parseFloat(amountPaidInput.value) || 0;
                
                // Prevent paying more than the net amount
                if (amountPaid > netAmount) {
                    amountPaid = netAmount;
                    amountPaidInput.value = amountPaid.toFixed(2);
                }

                if (amountPaid <= 0) {
                    amountPaidInput.setCustomValidity('Amount paid must be greater than zero for Partial Paid status.');
                } else {
                    amountPaidInput.setCustomValidity('');
                }

                let balance = netAmount - amountPaid;
                balanceAmountInput.value = balance.toFixed(2);
                amountPaidInput.max = netAmount.toFixed(2); // Set max attribute for validation
            } else {
                partialPaidDetails.style.display = 'none'; // Hide the section
                amountPaidInput.value = ''; // Clear the values when not visible
                balanceAmountInput.value = '';
                amountPaidInput.setCustomValidity('');
            }

            syncSplitPaymentFields();
        }

        // Attach event listeners
        if(paymentStatusSelect) {
            paymentStatusSelect.addEventListener('change', updatePartialPaid);
        }
        if(amountPaidInput) {
            amountPaidInput.addEventListener('input', updatePartialPaid);
        }
        if (paymentModeSelect) {
            paymentModeSelect.addEventListener('change', syncSplitPaymentFields);
        }
        Object.keys(splitFieldMap).forEach(function(key) {
            const field = splitFieldMap[key];
            if (!field || !field.input) return;
            field.input.addEventListener('input', function() {
                field.input.setCustomValidity('');
                autoBalanceSplitFields(key, true);
            });
            field.input.addEventListener('change', function() {
                autoBalanceSplitFields(key, true);
            });
        });

        billForm.addEventListener('submit', function(e) {
            if (discountBySelect && discountInput) {
                const hasDiscount = parseAmount(discountInput.value) > 0.0001;
                if (hasDiscount && !discountBySelect.value) {
                    discountBySelect.setCustomValidity('Please select who provided the discount.');
                    discountBySelect.reportValidity();
                    e.preventDefault();
                    return;
                }
                discountBySelect.setCustomValidity('');
            }

            if (!validateSplitInputs()) {
                e.preventDefault();
            }
        });

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
    const PARTIAL_PAID_STORAGE_KEY = 'dc_partial_paid_notice';
    const LAST_REQUEST_STORAGE_KEY = 'dc_manager_last_request_id';
    const PARTIAL_PAID_BELL_BUTTON_ID = 'notification-bell-btn';
    const PARTIAL_PAID_BADGE_ID = 'partial-paid-badge';
    const PARTIAL_PAID_DROPDOWN_ID = 'partial-paid-dropdown';
    const NOTIFICATION_OPEN_CLASS = 'notification-dropdown-open';

    const partialPaidBellButton = document.getElementById(PARTIAL_PAID_BELL_BUTTON_ID);
    const partialPaidBadge = document.getElementById(PARTIAL_PAID_BADGE_ID);
    const partialPaidDropdown = document.getElementById(PARTIAL_PAID_DROPDOWN_ID);

    let partialPaidState = {
        bills: [],
        count: 0,
        signature: 'none'
    };

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

    function computePartialPaidSignature(bills) {
        if (!Array.isArray(bills) || bills.length === 0) {
            return 'none';
        }
        return bills.map(bill => `${bill.id}:${parseFloat(bill.balance_amount || bill.balanceAmount || 0)}`).join('|');
    }

    function shouldShowPartialPaid(signature) {
        try {
            const raw = sessionStorage.getItem(PARTIAL_PAID_STORAGE_KEY);
            if (raw) {
                const data = JSON.parse(raw);
                if (data.signature === signature && data.timestamp && (Date.now() - data.timestamp) < (55 * 60 * 1000)) {
                    return false;
                }
            }
        } catch (error) {
            console.warn('Unable to read partial-paid notification state', error);
        }
        return true;
    }

    function markPartialPaidShown(signature) {
        try {
            sessionStorage.setItem(PARTIAL_PAID_STORAGE_KEY, JSON.stringify({ signature, timestamp: Date.now() }));
        } catch (error) {
            console.warn('Unable to persist partial-paid notification state', error);
        }
    }

    function setBackgroundScrollLock(locked) {
        const html = document.documentElement;
        if (html) {
            html.classList.toggle(NOTIFICATION_OPEN_CLASS, locked);
        }
        if (document.body) {
            document.body.classList.toggle(NOTIFICATION_OPEN_CLASS, locked);
        }
    }

    function containScrollWithinElement(scrollEl) {
        if (!scrollEl) {
            return;
        }

        scrollEl.addEventListener('wheel', (event) => {
            event.stopPropagation();

            const deltaY = event.deltaY || 0;
            if (deltaY === 0) {
                return;
            }

            const atTop = scrollEl.scrollTop <= 0;
            const atBottom = (scrollEl.scrollTop + scrollEl.clientHeight) >= (scrollEl.scrollHeight - 1);
            if ((deltaY < 0 && atTop) || (deltaY > 0 && atBottom)) {
                event.preventDefault();
            }
        }, { passive: false });

        scrollEl.addEventListener('touchmove', (event) => {
            event.stopPropagation();
        }, { passive: true });

        scrollEl.addEventListener('scroll', (event) => {
            event.stopPropagation();
        }, { passive: true });
    }

    function getPartialPaidActionLink() {
        const base = (typeof SITE_BASE_URL !== 'undefined' ? SITE_BASE_URL : '');
        const todayStr = new Date().toISOString().slice(0, 10);
        const earliestDate = '2000-01-01';
        if (currentRole === 'manager') {
            return `${base}/manager/view_due_bills.php?all_dates=1&start_date=${earliestDate}&end_date=${todayStr}&status=Partial%20Paid`;
        }
        if (currentRole === 'receptionist') {
            return `${base}/receptionist/bill_history.php?all_dates=1&start_date=${earliestDate}&end_date=${todayStr}&payment_status=pending#bill-review-section`;
        }
        return `${base}/manager/view_due_bills.php?all_dates=1&start_date=${earliestDate}&end_date=${todayStr}&status=Partial%20Paid`;
    }

    function setPartialPaidBadgeCount(value) {
        const count = Math.max(0, parseInt(value, 10) || 0);

        if (partialPaidBadge) {
            partialPaidBadge.textContent = count > 99 ? '99+' : String(count);
            partialPaidBadge.classList.toggle('is-hidden', count === 0);
        }

        if (partialPaidBellButton) {
            const label = count === 0
                ? 'Notifications'
                : `Notifications, ${count} partial paid bill${count === 1 ? '' : 's'} pending`;
            partialPaidBellButton.setAttribute('aria-label', label);
            partialPaidBellButton.setAttribute('title', label);
        }
    }

    function closePartialPaidDropdown() {
        setBackgroundScrollLock(false);
        if (!partialPaidDropdown) return;
        partialPaidDropdown.hidden = true;
        if (partialPaidBellButton) {
            partialPaidBellButton.setAttribute('aria-expanded', 'false');
        }
    }

    function openPartialPaidDropdown() {
        if (!partialPaidDropdown || !partialPaidDropdown.childElementCount) return;
        partialPaidDropdown.hidden = false;
        if (partialPaidBellButton) {
            partialPaidBellButton.setAttribute('aria-expanded', 'true');
        }
        setBackgroundScrollLock(true);
    }

    function renderPartialPaidDropdown(payload) {
        if (!partialPaidDropdown || !payload || !Array.isArray(payload.bills) || !payload.bills.length) {
            return;
        }

        const count = Math.max(0, parseInt(payload.count, 10) || payload.bills.length);
        const summary = count === 1
            ? 'There is 1 partial paid bill awaiting attention.'
            : `There are ${count} partial paid bills awaiting attention.`;

        partialPaidDropdown.innerHTML = '';

        const header = document.createElement('div');
        header.className = 'notification-dropdown__header';

        const titleWrap = document.createElement('div');
        titleWrap.className = 'notification-dropdown__title-wrap';

        const title = document.createElement('h4');
        title.className = 'notification-dropdown__title';
        title.textContent = 'Partial Paid Bills Reminder';

        const countLabel = document.createElement('p');
        countLabel.className = 'notification-dropdown__count';
        countLabel.textContent = `${count} pending bill${count === 1 ? '' : 's'}`;

        titleWrap.appendChild(title);
        titleWrap.appendChild(countLabel);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'notification-dropdown__close';
        closeBtn.setAttribute('aria-label', 'Dismiss notification');
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', () => {
            closePartialPaidDropdown();
            markPartialPaidShown(payload.signature || computePartialPaidSignature(payload.bills));
        });

        header.appendChild(titleWrap);
        header.appendChild(closeBtn);
        partialPaidDropdown.appendChild(header);

        const body = document.createElement('div');
        body.className = 'notification-dropdown__body';

        const summaryLine = document.createElement('p');
        summaryLine.className = 'notification-dropdown__summary';
        summaryLine.textContent = summary;
        body.appendChild(summaryLine);

        const list = document.createElement('ul');
        list.className = 'notification-dropdown__list';

        payload.bills.forEach(bill => {
            const item = document.createElement('li');
            item.className = 'notification-dropdown__item';

            const billTitle = document.createElement('strong');
            billTitle.textContent = `Bill #${bill.id} • ${bill.patient_name}`;

            const balance = document.createElement('span');
            balance.className = 'notification-dropdown__meta';
            balance.textContent = `Balance ${formatCurrency(bill.balance_amount ?? bill.balanceAmount ?? 0)}`;

            item.appendChild(billTitle);
            item.appendChild(balance);
            list.appendChild(item);
        });

        containScrollWithinElement(list);

        body.appendChild(list);
        partialPaidDropdown.appendChild(body);

        const actions = document.createElement('div');
        actions.className = 'notification-dropdown__actions';

        const reviewBtn = document.createElement('button');
        reviewBtn.type = 'button';
        reviewBtn.className = 'popup-action-btn variant-primary';
        reviewBtn.textContent = 'Review Bills';
        reviewBtn.addEventListener('click', () => {
            window.location.href = getPartialPaidActionLink();
        });

        const dismissBtn = document.createElement('button');
        dismissBtn.type = 'button';
        dismissBtn.className = 'popup-action-btn variant-secondary';
        dismissBtn.textContent = 'Dismiss';
        dismissBtn.addEventListener('click', () => {
            closePartialPaidDropdown();
            markPartialPaidShown(payload.signature || computePartialPaidSignature(payload.bills));
        });

        actions.appendChild(reviewBtn);
        actions.appendChild(dismissBtn);
        partialPaidDropdown.appendChild(actions);
    }

    function presentPartialPaidNotification(payload, { autoOpen = false } = {}) {
        if (!payload || !Array.isArray(payload.bills)) {
            return;
        }

        const count = Math.max(0, parseInt(payload.count, 10) || payload.bills.length);
        const signature = payload.signature || computePartialPaidSignature(payload.bills);
        partialPaidState = {
            bills: payload.bills,
            count,
            signature
        };

        setPartialPaidBadgeCount(count);

        if (count === 0) {
            closePartialPaidDropdown();
            if (partialPaidDropdown) {
                partialPaidDropdown.innerHTML = '';
            }
            return;
        }

        const wasOpen = partialPaidDropdown ? !partialPaidDropdown.hidden : false;
        renderPartialPaidDropdown(partialPaidState);
        if (autoOpen || wasOpen) {
            openPartialPaidDropdown();
        }
    }

    function bindPartialPaidDropdownEvents() {
        if (!partialPaidBellButton || !partialPaidDropdown) {
            return;
        }

        partialPaidDropdown.addEventListener('wheel', (event) => {
            event.stopPropagation();
        }, { passive: true });

        partialPaidDropdown.addEventListener('touchmove', (event) => {
            event.stopPropagation();
        }, { passive: true });

        partialPaidDropdown.addEventListener('scroll', (event) => {
            event.stopPropagation();
        }, { passive: true });

        partialPaidBellButton.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            if (partialPaidDropdown.hidden) {
                if (!partialPaidState.count) {
                    return;
                }
                renderPartialPaidDropdown(partialPaidState);
                openPartialPaidDropdown();
                return;
            }

            closePartialPaidDropdown();
        });

        document.addEventListener('click', (event) => {
            if (partialPaidDropdown.hidden) {
                return;
            }

            if (partialPaidDropdown.contains(event.target) || partialPaidBellButton.contains(event.target)) {
                return;
            }

            closePartialPaidDropdown();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closePartialPaidDropdown();
            }
        });

        window.addEventListener('beforeunload', () => {
            setBackgroundScrollLock(false);
        });
    }

    function performPartialPaidCheck() {
        fetch(`${NOTIFICATION_API}?action=partial_paid`, { cache: 'no-store' })
            .then(response => response.json())
            .then(data => {
                if (!data.success || !data.data) {
                    presentPartialPaidNotification({ bills: [], count: 0 });
                    return;
                }

                const bills = Array.isArray(data.data.bills) ? data.data.bills : [];
                const count = Math.max(0, parseInt(data.data.count, 10) || bills.length);
                const signature = computePartialPaidSignature(bills);
                const autoOpen = count > 0 && shouldShowPartialPaid(signature);

                presentPartialPaidNotification({ bills, count, signature }, { autoOpen });

                if (autoOpen) {
                    markPartialPaidShown(signature);
                }
            })
            .catch(error => console.error('Partial paid check failed:', error));
    }

    function schedulePartialPaidChecks() {
        bindPartialPaidDropdownEvents();
        performPartialPaidCheck();
        setInterval(performPartialPaidCheck, 60 * 60 * 1000); // hourly
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
        schedulePartialPaidChecks();
    }

    if (currentRole === 'manager') {
        scheduleNavCounts();
        scheduleRequestWatcher();
    }
});

