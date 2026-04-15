(function () {
    const state = {
        tests: [],
        selectedMajor: null,
        searchText: '',
        pageSize: 10,
        page: 1
    };

    const grid = document.getElementById('sa-major-test-grid');
    const detail = document.getElementById('sa-major-test-detail');
    const detailTitle = document.getElementById('sa-detail-title');
    const detailSubtitle = document.getElementById('sa-detail-subtitle');
    const detailStats = document.getElementById('sa-detail-stats');
    const tableBody = document.getElementById('sa-subtest-table-body');
    const startDateInput = document.getElementById('sa-scan-start-date');
    const endDateInput = document.getElementById('sa-scan-end-date');
    const filterForm = document.getElementById('sa-scans-filter-form');
    const searchInput = document.getElementById('sa-subtest-search');
    const pageSizeSelect = document.getElementById('sa-subtest-page-size');
    const prevBtn = document.getElementById('sa-subtest-prev');
    const nextBtn = document.getElementById('sa-subtest-next');
    const pageInfo = document.getElementById('sa-subtest-page-info');

    if (!grid || !detail || !detailTitle || !detailSubtitle || !detailStats || !tableBody) {
        return;
    }

    function renderMajorCards() {
        grid.innerHTML = state.tests.map(function (test) {
            return window.SuperadminScansComponents.majorCard(test, test.majorTestName === state.selectedMajor);
        }).join('');
    }

    function renderDetail() {
        const activeTest = state.tests.find(function (test) {
            return test.majorTestName === state.selectedMajor;
        });

        if (!activeTest) {
            detail.hidden = true;
            return;
        }

        detail.hidden = false;
        detailTitle.textContent = activeTest.majorTestName + ' Breakdown';
        detailSubtitle.textContent = 'Detailed analytics for selected major test';
        detailStats.innerHTML = window.SuperadminScansComponents.detailPills(activeTest);

        const allRows = Array.isArray(activeTest.subTests) ? activeTest.subTests : [];
        const filteredRows = allRows.filter(function (row) {
            if (!state.searchText) return true;
            return String(row.subTestName || '').toLowerCase().includes(state.searchText.toLowerCase());
        });

        const totalPages = Math.max(1, Math.ceil(filteredRows.length / state.pageSize));
        if (state.page > totalPages) {
            state.page = totalPages;
        }

        const startIndex = (state.page - 1) * state.pageSize;
        const endIndex = startIndex + state.pageSize;
        const pageRows = filteredRows.slice(startIndex, endIndex);

        tableBody.innerHTML = window.SuperadminScansComponents.subTestRows(pageRows);

        if (pageInfo) {
            pageInfo.textContent = 'Page ' + state.page + ' of ' + totalPages;
        }
        if (prevBtn) {
            prevBtn.disabled = state.page <= 1;
        }
        if (nextBtn) {
            nextBtn.disabled = state.page >= totalPages;
        }
    }

    function selectMajorTest(testName) {
        state.selectedMajor = testName;
        state.page = 1;
        renderMajorCards();
        renderDetail();
    }

    function bindEvents() {
        grid.addEventListener('click', function (event) {
            const card = event.target.closest('[data-major-test]');
            if (!card) return;
            selectMajorTest(card.getAttribute('data-major-test'));
        });

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                state.searchText = searchInput.value || '';
                state.page = 1;
                renderDetail();
            });
        }

        if (pageSizeSelect) {
            pageSizeSelect.addEventListener('change', function () {
                const nextSize = parseInt(pageSizeSelect.value, 10);
                state.pageSize = Number.isFinite(nextSize) && nextSize > 0 ? nextSize : 10;
                state.page = 1;
                renderDetail();
            });
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                if (state.page > 1) {
                    state.page -= 1;
                    renderDetail();
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                state.page += 1;
                renderDetail();
            });
        }

        function onDateChange() {
            state.page = 1;
            init();
        }

        if (startDateInput) {
            startDateInput.addEventListener('change', onDateChange);
        }
        if (endDateInput) {
            endDateInput.addEventListener('change', onDateChange);
        }

        if (filterForm) {
            filterForm.addEventListener('submit', function (event) {
                event.preventDefault();
            });
        }
    }

    async function fetchData() {
        const baseUrl = typeof window.SITE_BASE_URL === 'string' ? window.SITE_BASE_URL : '';
        const params = new URLSearchParams();
        params.set('v', String(Date.now()));

        if (startDateInput && startDateInput.value) {
            params.set('start_date', startDateInput.value);
        }
        if (endDateInput && endDateInput.value) {
            params.set('end_date', endDateInput.value);
        }

        const liveUrl = baseUrl + '/superadmin/scans_data.php?' + params.toString();

        const liveResponse = await fetch(liveUrl, { cache: 'no-store' });
        if (liveResponse.ok) {
            const payload = await liveResponse.json();
            return Array.isArray(payload.majorTests) ? payload.majorTests : [];
        }

        // Fallback for local-only/dev cases.
        const mockUrl = baseUrl + '/superadmin/data/scans.mock.json?v=' + Date.now();
        const mockResponse = await fetch(mockUrl, { cache: 'no-store' });
        if (!mockResponse.ok) {
            throw new Error('Unable to load scans data');
        }
        const mockPayload = await mockResponse.json();
        return Array.isArray(mockPayload.majorTests) ? mockPayload.majorTests : [];
    }

    async function init() {
        grid.innerHTML = '<div class="sa-loading">Loading scans analytics...</div>';
        try {
            state.tests = await fetchData();
            if (state.tests.length === 0) {
                grid.innerHTML = '<div class="sa-loading">No scan analytics found.</div>';
                return;
            }

            state.selectedMajor = state.tests[0].majorTestName;
            renderMajorCards();
            renderDetail();
            if (!init._eventsBound) {
                bindEvents();
                init._eventsBound = true;
            }
        } catch (error) {
            grid.innerHTML = '<div class="sa-error">Failed to load scans module data. Please try again.</div>';
        }
    }

    init();
})();
