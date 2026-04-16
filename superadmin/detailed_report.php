<?php
$page_title = "Detailed Reports";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

// --- Handle Filters with Session Persistence ---
$filter_key = 'detailed_report_filters';
if (isset($_GET['reset'])) {
    unset($_SESSION[$filter_key]);
    header("Location: detailed_report.php");
    exit();
}

if (isset($_GET['start_date'])) {
    $_SESSION[$filter_key]['start_date'] = $_GET['start_date'];
    $_SESSION[$filter_key]['end_date'] = $_GET['end_date'];
}

$start_date = $_SESSION[$filter_key]['start_date'] ?? (isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01')); // Default to first day of current month
$end_date = $_SESSION[$filter_key]['end_date'] ?? (isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d')); // Default to today

// --- Build Query ---
$sql = "SELECT
            bi.id as bill_item_id,
            b.id as bill_id,
            p.uid as patient_uid,
            p.name as patient_name,
            p.sex as patient_sex,
            t.sub_test_name,
            bi.report_status,
            b.created_at as bill_date
        FROM bill_items bi
        JOIN bills b ON bi.bill_id = b.id
        JOIN patients p ON b.patient_id = p.id
        JOIN tests t ON bi.test_id = t.id
        WHERE DATE(b.created_at) BETWEEN ? AND ?
          AND b.bill_status != 'Void'
        ORDER BY b.created_at DESC, b.id DESC";

$stmt = $conn->prepare($sql);
if ($stmt === false) { die("Error preparing query: " . $conn->error); }

$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

require_once '../includes/header.php';
?>

<div class="page-container">
    <div class="dashboard-header">
        <div>
            <h1>Detailed Reports</h1>
            <p class="text-muted">View detailed breakdown of tests and reports.</p>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-2"></i> Back</a>
    </div>

    <form method="GET" action="" class="filter-form compact-filters">
        <div class="quick-filters">
            <button type="button" class="btn-quick-date" data-range="today">Today</button>
            <button type="button" class="btn-quick-date" data-range="yesterday">Yesterday</button>
            <button type="button" class="btn-quick-date" data-range="this_week">Last 7 Days</button>
            <button type="button" class="btn-quick-date" data-range="this_month">This Month</button>
            <button type="button" class="btn-quick-date" data-range="last_month">Last Month</button>
            <button type="button" class="btn-quick-date" data-range="this_year">This Year</button>
        </div>
        <div class="filter-group">
            <div class="form-group">
                <label for="start_date">Start Date</label>
                <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" required>
            </div>
            <div class="form-group">
                <label for="end_date">End Date</label>
                <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" required>
            </div>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-submit">Filter</button>
            <a href="?reset=1" class="btn-reset">Reset</a>
        </div>
    </form>

    <div class="filter-info-bar">
        <i class="fas fa-calendar-alt"></i>
        <span>Showing reports from <strong><?php echo date('d M Y', strtotime($start_date)); ?></strong> to <strong><?php echo date('d M Y', strtotime($end_date)); ?></strong></span>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Patient ID</th>
                    <th>Name</th>
                    <th>Gender</th>
                    <th>Test Name</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <?php
                            $is_completed = ($row['report_status'] == 'Completed');
                            $report_link = $is_completed ? "../templates/print_report.php?item_id=" . $row['bill_item_id'] : '#';
                            $edit_link = "../writer/fill_report.php?item_id=" . $row['bill_item_id'];
                            $status_class = $is_completed ? 'status-paid' : 'status-pending'; // Using paid/pending classes for consistency
                            $status_label = $is_completed ? 'Uploaded' : ($row['report_status'] ?: 'Pending');
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['patient_uid']); ?></td>
                            <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['patient_sex']); ?></td>
                            <td><?php echo htmlspecialchars($row['sub_test_name']); ?></td>
                            <td>
                                <span class="<?php echo $status_class; ?>">
                                    <?php echo htmlspecialchars($status_label); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($is_completed): ?>
                                    <a href="<?php echo $report_link; ?>" target="_blank" class="btn-action btn-view">View Report</a>
                                    <a href="<?php echo $edit_link; ?>" class="btn-action btn-view" style="margin-left:6px;">Open Editor</a>
                                <?php else: ?>
                                    <a href="<?php echo $edit_link; ?>" class="btn-action btn-view">Open Editor</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$stmt->close();
require_once '../includes/footer.php';
?>