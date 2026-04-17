<?php
$page_title = "Print Reports";
$required_role = "manager"; // Set required role
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_package_management_schema($conn);

// --- Handle Filters ---
$has_explicit_date_filter = isset($_GET['start_date']) || isset($_GET['end_date']);
$default_start_date = '2000-01-01';
$default_end_date = date('Y-m-d');

if ($range_stmt = $conn->prepare("SELECT DATE(MIN(b.created_at)) AS min_date, DATE(MAX(b.created_at)) AS max_date
                                 FROM bill_items bi
                                 JOIN bills b ON bi.bill_id = b.id
                                 JOIN tests t ON t.id = bi.test_id
                                 WHERE b.bill_status != 'Void'
                                   AND COALESCE(bi.report_status, 'Pending') = 'Completed'")) {
    $range_stmt->execute();
    $range_row = $range_stmt->get_result()->fetch_assoc();
    $range_stmt->close();

    if (!empty($range_row['min_date'])) {
        $default_start_date = (string)$range_row['min_date'];
    }
    if (!empty($range_row['max_date'])) {
        $default_end_date = (string)$range_row['max_date'];
    }
}

$start_date = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : $default_start_date;
$end_date = isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : $default_end_date;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = $default_start_date;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = $default_end_date;
}
if (strtotime($start_date) > strtotime($end_date)) {
    $start_date = $end_date;
}

// --- Build Query ---
$sql = "SELECT
            bi.id as bill_item_id,
            b.id as bill_id,
            p.uid as patient_uid,
            p.name as patient_name,
            p.age as patient_age,
            p.sex as patient_sex,
            t.main_test_name,
            t.sub_test_name,
            COALESCE(NULLIF(bi.package_name, ''), tp.package_name) AS package_name,
            bi.report_status,
            b.created_at as bill_date
        FROM bill_items bi
        JOIN bills b ON bi.bill_id = b.id
        JOIN patients p ON b.patient_id = p.id
        JOIN tests t ON bi.test_id = t.id
        LEFT JOIN test_packages tp ON tp.id = bi.package_id
        WHERE DATE(b.created_at) BETWEEN ? AND ?
                    AND b.bill_status != 'Void'
                    AND COALESCE(bi.report_status, 'Pending') = 'Completed'"; // Managers see reports only after writer upload

$params = [$start_date, $end_date];
$types = 'ss';

$sql .= " ORDER BY b.id DESC, bi.id ASC"; // Order by bill then item

$stmt = $conn->prepare($sql);
if ($stmt === false) { die("Error preparing query: " . $conn->error); }

$stmt->bind_param($types, ...$params);
$stmt->execute();
$report_items = $stmt->get_result();

require_once '../includes/header.php';
?>



<div class="page-container">
    <div class="dashboard-header">
        <h1>Print Patient Reports</h1>
        <p>View and print reports uploaded by writer.</p>
        <?php if (!$has_explicit_date_filter): ?>
            <small style="color:#64748b;">Showing full available date range by default: <?php echo htmlspecialchars($start_date); ?> to <?php echo htmlspecialchars($end_date); ?></small>
        <?php endif; ?>
    </div>

    <form action="print_reports.php" method="GET" class="filter-form compact-filters" style="margin-bottom: 2rem;">
        <div class="filter-group">
            <label for="start_date">Start Date</label>
            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>
        <div class="filter-group">
            <label for="end_date">End Date</label>
            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>
        <div class="filter-group">
            <label>Report Status</label>
            <input type="text" value="Uploaded" readonly>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-submit">Filter</button>
            <br>
            <a href="print_reports.php" class="btn-cancel" style="text-decoration:none;">Reset</a>
        </div>
    </form>
    <div class="table-container">
        <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Bill ID</th>
                    <th>Patient ID</th>
                    <th>Patient Details</th>
                    <th>Test</th>
                    <th>Report Status</th>
                    <th>View</th>
                    <th>Print</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($report_items && $report_items->num_rows > 0): ?>
                    <?php while($item = $report_items->fetch_assoc()): ?>
                        <?php $report_link = "../templates/print_report.php?item_id=" . $item['bill_item_id']; ?>
                        <tr>
                            <td><?php echo $item['bill_id']; ?></td>
                            <td><span style="font-size:0.82rem;color:#666;"><?php echo htmlspecialchars($item['patient_uid'] ?? ''); ?></span></td>
                            <td>
                                <?php echo htmlspecialchars($item['patient_name']); ?> /
                                <?php echo htmlspecialchars($item['patient_age']); ?> /
                                <?php echo htmlspecialchars($item['patient_sex']); ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($item['main_test_name']); ?> /
                                <?php echo htmlspecialchars($item['sub_test_name']); ?>
                                <?php if (!empty($item['package_name'])): ?>
                                    <br><small style="color:#64748b;">PACKAGE: <?php echo htmlspecialchars($item['package_name']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-<?php echo strtolower($item['report_status']); ?>">
                                    Uploaded
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo $report_link; ?>"
                                   class="btn-action btn-view"
                                   target="_blank">
                                   View Report
                                </a>
                            </td>
                             <td>
                                <button
                                   onclick="window.open('<?php echo $report_link; ?>');"
                                   class="btn-action btn-primary">
                                   Print Report
                                </button>
                                </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:20px; color:#64748b;">No printable reports found for the selected date range.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php
$stmt->close();
require_once '../includes/footer.php';