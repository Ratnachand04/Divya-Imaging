<?php
$page_title = "Print Reports";
$required_role = "manager"; // Set required role
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_package_management_schema($conn);

$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';
$test_packages_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'test_packages', 'tp') : '`test_packages` tp';

$patient_identifier_expr = function_exists('get_patient_identifier_expression')
    ? get_patient_identifier_expression($conn, 'p')
    : 'CAST(p.id AS CHAR)';

// --- Handle Filters ---
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d'); // Default to today
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // Default to today

// --- Build Query ---
$sql = "SELECT
            bi.id as bill_item_id,
            b.id as bill_id,
            {$patient_identifier_expr} as patient_uid,
            p.name as patient_name,
            p.age as patient_age,
            p.sex as patient_sex,
            t.main_test_name,
            t.sub_test_name,
            COALESCE(NULLIF(bi.package_name, ''), tp.package_name) AS package_name,
            bi.report_status,
            b.created_at as bill_date
        FROM {$bill_items_source}
        JOIN {$bills_source} ON bi.bill_id = b.id
        JOIN {$patients_source} ON b.patient_id = p.id
        JOIN {$tests_source} ON bi.test_id = t.id
        LEFT JOIN {$test_packages_source} ON tp.id = bi.package_id
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
                        <?php $report_download_link = $report_link . "&download=1"; ?>
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
                                          <a href="<?php echo htmlspecialchars($report_download_link); ?>"
                                              class="btn-action btn-primary"
                                              target="_blank"
                                              rel="noopener">
                                              Print Report
                                          </a>
                                </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php
$stmt->close();
require_once '../includes/footer.php';