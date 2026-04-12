<?php
$page_title = "Print Reports";
$required_role = "manager"; // Set required role
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

// --- Handle Filters ---
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d'); // Default to today
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // Default to today
$status_filter = isset($_GET['status']) && $_GET['status'] !== 'all' ? $_GET['status'] : 'all'; // Default to all

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
            bi.report_status,
            b.created_at as bill_date
        FROM bill_items bi
        JOIN bills b ON bi.bill_id = b.id
        JOIN patients p ON b.patient_id = p.id
        JOIN tests t ON bi.test_id = t.id
        WHERE DATE(b.created_at) BETWEEN ? AND ?
          AND b.bill_status != 'Void'"; // Fetch items within date range, exclude voided bills

$params = [$start_date, $end_date];
$types = 'ss';

if ($status_filter !== 'all') {
    $sql .= " AND bi.report_status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

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
        <p>View report statuses and print completed reports.</p>
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
            <label for="status">Report Status</label>
            <select name="status" id="status">
                <option value="all" <?php if($status_filter == 'all') echo 'selected'; ?>>All Statuses</option>
                <option value="Completed" <?php if($status_filter == 'Completed') echo 'selected'; ?>>Completed</option>
                <option value="Pending" <?php if($status_filter == 'Pending') echo 'selected'; ?>>Pending</option>
            </select>
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
                        <?php
                            $is_completed = ($item['report_status'] == 'Completed');
                            $report_link = $is_completed ? "../templates/print_report.php?item_id=" . $item['bill_item_id'] : '#';
                            $button_class_view = $is_completed ? 'btn-view' : 'btn-disabled';
                            $button_class_print = $is_completed ? 'btn-primary' : 'btn-disabled'; // Use a different style for print
                            $target_blank = $is_completed ? 'target="_blank"' : '';
                            $onclick_print = $is_completed ? "window.open('{$report_link}');" : "return false;"; // Open in new tab for print
                        ?>
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
                            </td>
                            <td>
                                <span class="status-<?php echo strtolower($item['report_status']); ?>">
                                    <?php echo $item['report_status']; ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo $report_link; ?>"
                                   class="btn-action <?php echo $button_class_view; ?>"
                                   <?php echo $target_blank; ?>
                                   <?php if (!$is_completed) echo ' title="Report not yet completed"'; ?>>
                                   View Report
                                </a>
                            </td>
                             <td>
                                <button
                                   onclick="<?php echo $onclick_print; ?>"
                                   class="btn-action <?php echo $button_class_print; ?>"
                                   <?php if (!$is_completed) echo ' disabled title="Report not yet completed"'; ?>>
                                   Print Report
                                </button>
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