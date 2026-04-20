<section class="sa-scans-page">
    <div class="sa-scans-head">
        <h1>Scans Analytics</h1>
        <p>Major and sub-test metrics from system start till today.</p>
    </div>

    <form id="sa-scans-filter-form" class="sa-scans-filter-form" autocomplete="off">
        <div class="sa-filter-field">
            <label for="sa-scan-start-date">Start Date</label>
            <input type="date" id="sa-scan-start-date" value="<?php echo htmlspecialchars($scanStartDate ?? date('Y-m-01')); ?>">
        </div>
        <div class="sa-filter-field">
            <label for="sa-scan-end-date">End Date</label>
            <input type="date" id="sa-scan-end-date" value="<?php echo htmlspecialchars($scanEndDate ?? date('Y-m-d')); ?>">
        </div>
    </form>

    <div id="sa-major-test-grid" class="sa-major-test-grid"></div>

    <section id="sa-major-test-detail" class="sa-major-test-detail" hidden>
        <div class="sa-detail-summary">
            <div class="sa-detail-title-wrap">
                <h2 id="sa-detail-title">Major Test</h2>
                <p id="sa-detail-subtitle">Detailed metrics</p>
            </div>
            <div id="sa-detail-stats" class="sa-detail-stats"></div>
        </div>

        <div class="sa-subtest-table-wrap">
            <div class="sa-subtest-controls">
                <label>
                    Show
                    <select id="sa-subtest-page-size">
                        <option value="10">10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    entries
                </label>
                <label>
                    Search
                    <input type="search" id="sa-subtest-search" placeholder="Search sub test...">
                </label>
            </div>
            <table class="sa-subtest-table">
                <thead>
                    <tr>
                        <th>Sub Test Name</th>
                        <th>Revenue</th>
                        <th>Billed Count</th>
                        <th>Performed Count</th>
                        <th>Done Count</th>
                    </tr>
                </thead>
                <tbody id="sa-subtest-table-body"></tbody>
            </table>
            <div class="sa-subtest-pagination">
                <button type="button" id="sa-subtest-prev">Prev</button>
                <span id="sa-subtest-page-info">Page 1 of 1</span>
                <button type="button" id="sa-subtest-next">Next</button>
            </div>
        </div>
    </section>
</section>
