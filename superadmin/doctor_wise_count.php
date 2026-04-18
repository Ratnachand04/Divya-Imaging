<?php
$page_title = "Doctor Wise Test Count";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

// --- Handle Filters with Session Persistence ---
$filter_key = 'doctor_wise_filters';
if (isset($_GET['reset'])) {
    unset($_SESSION[$filter_key]);
    header("Location: doctor_wise_count.php");
    exit();
}

if (isset($_GET['start_date'])) {
    $_SESSION[$filter_key]['start_date'] = $_GET['start_date'];
    $_SESSION[$filter_key]['end_date'] = $_GET['end_date'];
}

$start_date = $_SESSION[$filter_key]['start_date'] ?? (isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-01-01'));
$end_date = $_SESSION[$filter_key]['end_date'] ?? (isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'));

// Fetch doctor wise test counts and patient counts with Date Filter
$sql = "SELECT rd.id, rd.doctor_name, 
        COUNT(DISTINCT b.id) AS patient_count,
        COUNT(bi.id) AS test_count
        FROM referral_doctors rd
        LEFT JOIN bills b ON rd.id = b.referral_doctor_id 
            AND b.bill_status != 'Void'
            AND DATE(b.created_at) BETWEEN ? AND ?
        LEFT JOIN bill_items bi ON b.id = bi.bill_id AND bi.item_status = 0
        GROUP BY rd.id, rd.doctor_name
        ORDER BY patient_count DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();
?>

<div class="main-content page-container">
    <div class="dashboard-header">
        <div>
            <h1>Doctor Wise Count</h1>
            <p class="text-muted">Analyze doctor performance and referrals.</p>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-2"></i> Back</a>
    </div>

    <!-- Date Filter -->
    <form method="GET" class="filter-form compact-filters">
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
        <span>Showing analytics from <strong><?php echo date('d M Y', strtotime($start_date)); ?></strong> to <strong><?php echo date('d M Y', strtotime($end_date)); ?></strong></span>
    </div>

    <div class="table-container">
        <div class="table-scroll">
        <table id="doctorWiseTable" class="data-table custom-table" style="width: 100%;">
            <thead>
                <tr>
                    <th>Doctor Name</th>
                    <th>Total Patients</th>
                    <th>Total Tests Prescribed</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <tr onclick="window.location.href='view_doctor_details.php?doctor_id=<?php echo $row['id']; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>'" style="cursor: pointer;">
                            <td><?php echo htmlspecialchars($row['doctor_name']); ?></td>
                            <td><?php echo $row['patient_count']; ?></td>
                            <td><?php echo $row['test_count']; ?></td>
                            <td>
                                <a href="view_doctor_details.php?doctor_id=<?php echo $row['id']; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn-action btn-edit" onclick="event.stopPropagation();">View Details</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

<script>
    $(document).ready(function() {
        $.fn.dataTable.ext.errMode = 'none'; // Suppress DataTables error alerts
        $('#doctorWiseTable').DataTable({
            "paging": true,
            "ordering": true,
            "order": [[ 1, "desc" ]], // Sort by Total Patients (Column 1) Descending
            "columnDefs": [
                { "orderable": false, "targets": 3 } // Disable sorting on Action column
            ],
            "info": true,
            "searching": true,
            "pageLength": 20,
            "lengthMenu": [10, 20, 50, 100],
            "language": {
                "search": "Search records:",
                "lengthMenu": "Show _MENU_ entries",
                "emptyTable": "No data found for the selected period."
            }
        });
    });
</script>
