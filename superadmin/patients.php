<?php
$page_title = "Patients";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

$sa_active_page = 'patients.php';

$patients = [];
$overallPatientCount = 0;
$overallTestCount = 0;

$sql = "
    SELECT
        p.id AS patient_id,
        COALESCE(NULLIF(p.uid, ''), '-') AS patient_uid,
        COALESCE(NULLIF(p.name, ''), '-') AS patient_name,
        COALESCE(NULLIF(p.city, ''), '-') AS city,
        COUNT(bi.id) AS total_test_count,
        COALESCE(
            NULLIF(
                GROUP_CONCAT(DISTINCT rd.doctor_name ORDER BY rd.doctor_name SEPARATOR ', '),
                ''
            ),
            'Self / Other'
        ) AS ref_doc
    FROM patients p
    LEFT JOIN bills b
        ON b.patient_id = p.id
       AND b.bill_status != 'Void'
    LEFT JOIN bill_items bi
        ON bi.bill_id = b.id
       AND bi.item_status = 0
    LEFT JOIN referral_doctors rd
        ON rd.id = b.referral_doctor_id
       AND b.referral_type = 'Doctor'
    GROUP BY p.id, p.uid, p.name, p.city
    HAVING COUNT(DISTINCT b.id) > 0
    ORDER BY MAX(b.created_at) DESC, p.name ASC
";

$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $overallPatientCount++;
        $overallTestCount += (int)$row['total_test_count'];
        $patients[] = [
            'patient_id' => (int)$row['patient_id'],
            'patient_uid' => $row['patient_uid'],
            'patient_name' => $row['patient_name'],
            'city' => $row['city'],
            'total_test_count' => (int)$row['total_test_count'],
            'ref_doc' => $row['ref_doc']
        ];
    }
}
?>

<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-patients-page { display: grid; gap: 1rem; }
.sa-patients-profile {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-patients-head h1 { margin: 0; color: #1e3a8a; font-size: 1.55rem; }
.sa-patients-head p { margin: 0.2rem 0 0; color: #64748b; }
.sa-patients-stats { margin-top: 0.8rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.65rem; }
.sa-patients-stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 0.7rem; }
.sa-patients-stat .k { color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700; }
.sa-patients-stat .v { margin-top: 0.2rem; color: #0f172a; font-size: 1rem; font-weight: 700; }
.sa-patients-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-patients-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}
.sa-toolbar-group {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: #475569;
    font-size: 0.86rem;
}
.sa-toolbar-group select,
.sa-toolbar-group input {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 0.35rem 0.5rem;
    color: #0f172a;
    background: #fff;
}
.sa-toolbar-group input { min-width: 220px; }
.sa-patients-table-wrap { overflow-x: auto; }
.sa-patients-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    min-width: 860px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}
.sa-patients-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    text-align: left;
    font-size: 0.78rem;
    color: #1e3a8a;
    background: #f8fafc;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border-bottom: 1px solid #e2e8f0;
    padding: 0.65rem 0.55rem;
}
.sa-patients-table tbody td {
    border-bottom: 1px solid #e2e8f0;
    padding: 0.68rem 0.55rem;
    color: #0f172a;
    vertical-align: middle;
}
.sa-patients-table tbody tr:nth-child(even) { background: #fcfdff; }
.sa-patients-table tbody tr:hover { background: #f8fafc; }
.sa-col-sno,
.sa-col-tests { text-align: center; white-space: nowrap; }
.sa-col-uid { white-space: nowrap; color: #334155; }
.sa-col-ref { color: #334155; }
.sa-pagination-wrap {
    margin-top: 0.8rem;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 0.65rem;
}
.sa-page-info {
    font-size: 0.85rem;
    color: #64748b;
}
.sa-page-controls {
    display: inline-flex;
    gap: 0.4rem;
}
.sa-page-btn {
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #1e3a8a;
    border-radius: 8px;
    padding: 0.36rem 0.65rem;
    font-weight: 700;
    cursor: pointer;
}
.sa-page-btn[disabled] {
    opacity: 0.45;
    cursor: not-allowed;
}
.sa-patient-link {
    color: #1d4ed8;
    font-weight: 700;
    text-decoration: none;
}
.sa-patient-link:hover { text-decoration: underline; }
.sa-empty {
    margin: 0;
    color: #64748b;
}
@media (max-width: 760px) {
    .sa-toolbar-group input { min-width: 170px; }
    .sa-patients-table thead th,
    .sa-patients-table tbody td { font-size: 0.82rem; }
}
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<section class="sa-patients-page">
    <article class="sa-patients-profile">
        <div class="sa-patients-head">
            <h1>Patients</h1>
            <p>Click a patient name to view bill and report details.</p>
        </div>

        <div class="sa-patients-stats">
            <div class="sa-patients-stat">
                <div class="k">Total Patients</div>
                <div class="v"><?php echo number_format($overallPatientCount); ?></div>
            </div>
            <div class="sa-patients-stat">
                <div class="k">Total Tests</div>
                <div class="v"><?php echo number_format($overallTestCount); ?></div>
            </div>
        </div>
    </article>

    <article class="sa-patients-card">
        <?php if (count($patients) === 0): ?>
            <p class="sa-empty">No patient data found.</p>
        <?php else: ?>
            <div class="sa-patients-toolbar">
                <div class="sa-toolbar-group">
                    <span>Show</span>
                    <select id="sa-patients-page-size">
                        <option value="10" selected>10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <span>entries</span>
                </div>
                <div class="sa-toolbar-group">
                    <label for="sa-patients-search">Search</label>
                    <input type="text" id="sa-patients-search" placeholder="UID, name, city, ref doc">
                </div>
            </div>

            <div class="sa-patients-table-wrap">
                <table class="sa-patients-table" id="sa-patients-table">
                    <thead>
                        <tr>
                            <th class="sa-col-sno">S.No</th>
                            <th>UID</th>
                            <th>Name</th>
                            <th>City</th>
                            <th class="sa-col-tests">Total No. of Test Count</th>
                            <th>Ref Doc</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $index => $patient): ?>
                            <tr data-row-search="<?php echo htmlspecialchars(strtolower($patient['patient_uid'] . ' ' . $patient['patient_name'] . ' ' . $patient['city'] . ' ' . $patient['ref_doc'])); ?>">
                                <td class="sa-col-sno"><?php echo $index + 1; ?></td>
                                <td class="sa-col-uid"><?php echo htmlspecialchars($patient['patient_uid']); ?></td>
                                <td>
                                    <a class="sa-patient-link" href="patient_details.php?patient_id=<?php echo (int)$patient['patient_id']; ?>">
                                        <?php echo htmlspecialchars($patient['patient_name']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($patient['city']); ?></td>
                                <td class="sa-col-tests"><?php echo number_format($patient['total_test_count']); ?></td>
                                <td class="sa-col-ref"><?php echo htmlspecialchars($patient['ref_doc']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="sa-pagination-wrap">
                <div class="sa-page-info" id="sa-page-info">Showing 0 to 0 of 0 entries</div>
                <div class="sa-page-controls">
                    <button type="button" class="sa-page-btn" id="sa-page-prev">Previous</button>
                    <button type="button" class="sa-page-btn" id="sa-page-next">Next</button>
                </div>
            </div>
        <?php endif; ?>
    </article>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const table = document.getElementById('sa-patients-table');
    const searchInput = document.getElementById('sa-patients-search');
    const pageSizeSelect = document.getElementById('sa-patients-page-size');
    const pageInfo = document.getElementById('sa-page-info');
    const prevBtn = document.getElementById('sa-page-prev');
    const nextBtn = document.getElementById('sa-page-next');

    if (!table || !searchInput || !pageSizeSelect || !pageInfo || !prevBtn || !nextBtn) {
        return;
    }

    const allRows = Array.from(table.querySelectorAll('tbody tr'));
    let currentPage = 1;

    function applyTableState(resetPage) {
        if (resetPage) currentPage = 1;

        const query = searchInput.value.trim().toLowerCase();
        const pageSize = parseInt(pageSizeSelect.value, 10) || 10;
        const filteredRows = allRows.filter(function (row) {
            const hay = (row.getAttribute('data-row-search') || '').toLowerCase();
            return hay.indexOf(query) !== -1;
        });

        const total = filteredRows.length;
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;

        const startIndex = (currentPage - 1) * pageSize;
        const endIndex = Math.min(startIndex + pageSize, total);

        allRows.forEach(function (row) {
            row.style.display = 'none';
        });

        filteredRows.slice(startIndex, endIndex).forEach(function (row, index) {
            row.style.display = '';
            const snoCell = row.querySelector('td');
            if (snoCell) snoCell.textContent = String(startIndex + index + 1);
        });

        const shownStart = total === 0 ? 0 : startIndex + 1;
        const shownEnd = total === 0 ? 0 : endIndex;
        pageInfo.textContent = 'Showing ' + shownStart + ' to ' + shownEnd + ' of ' + total + ' entries';

        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages || total === 0;
    }

    searchInput.addEventListener('input', function () { applyTableState(true); });
    pageSizeSelect.addEventListener('change', function () { applyTableState(true); });
    prevBtn.addEventListener('click', function () {
        if (currentPage > 1) {
            currentPage -= 1;
            applyTableState(false);
        }
    });
    nextBtn.addEventListener('click', function () {
        const pageSize = parseInt(pageSizeSelect.value, 10) || 10;
        const query = searchInput.value.trim().toLowerCase();
        const total = allRows.filter(function (row) {
            const hay = (row.getAttribute('data-row-search') || '').toLowerCase();
            return hay.indexOf(query) !== -1;
        }).length;
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (currentPage < totalPages) {
            currentPage += 1;
            applyTableState(false);
        }
    });

    applyTableState(true);
});
</script>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>
