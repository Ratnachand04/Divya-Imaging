window.SuperadminScansComponents = (function () {
    function formatCurrency(amount) {
        return 'Rs. ' + Number(amount || 0).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function majorCard(test, isActive) {
        return `
            <article class="sa-major-card ${isActive ? 'is-active' : ''}" data-major-test="${escapeHtml(test.majorTestName)}">
                <h3 class="sa-major-title">${escapeHtml(test.majorTestName)}</h3>
                <div class="sa-major-metrics">
                    <p><strong>Total Count:</strong> ${Number(test.totalTestCount || 0).toLocaleString('en-IN')}</p>
                    <p><strong>Total Revenue:</strong> ${formatCurrency(test.totalRevenue)}</p>
                    <p><strong>Reports Done:</strong> ${Number(test.totalReportsDone || 0).toLocaleString('en-IN')}</p>
                </div>
            </article>
        `;
    }

    function detailPills(test) {
        return `
            <span class="sa-detail-pill">${escapeHtml(test.majorTestName)} Count: ${Number(test.totalTestCount || 0).toLocaleString('en-IN')}</span>
            <span class="sa-detail-pill">${escapeHtml(test.majorTestName)} Revenue: ${formatCurrency(test.totalRevenue)}</span>
            <span class="sa-detail-pill">${escapeHtml(test.majorTestName)} Reports Done: ${Number(test.totalReportsDone || 0).toLocaleString('en-IN')}</span>
        `;
    }

    function subTestRows(subTests) {
        if (!Array.isArray(subTests) || subTests.length === 0) {
            return '<tr><td colspan="5">No sub-test records found.</td></tr>';
        }

        return subTests.map(function (row) {
            return `
                <tr>
                    <td>${escapeHtml(row.subTestName || '-')}</td>
                    <td>${formatCurrency(row.revenue)}</td>
                    <td>${Number(row.billedCount || 0).toLocaleString('en-IN')}</td>
                    <td>${Number(row.performedCount || 0).toLocaleString('en-IN')}</td>
                    <td>${Number(row.doneCount || 0).toLocaleString('en-IN')}</td>
                </tr>
            `;
        }).join('');
    }

    function escapeHtml(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    return {
        majorCard: majorCard,
        detailPills: detailPills,
        subTestRows: subTestRows
    };
})();
