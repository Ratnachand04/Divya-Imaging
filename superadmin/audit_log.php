<?php
$page_title = "Audit Log";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/header.php';

$sa_active_page = 'global_settings.php';
$system_audit_log_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'system_audit_log', 'sal') : '`system_audit_log` sal';

// --- Handle Filters with Session Persistence ---
$filter_key = 'audit_log_filters';

if (isset($_GET['reset'])) {
    unset($_SESSION[$filter_key]);
    header("Location: audit_log.php");
    exit();
}
if (isset($_GET['start_date'])) {
    $_SESSION[$filter_key] = [
        'start_date' => $_GET['start_date'],
        'end_date' => $_GET['end_date'],
        'action_type' => $_GET['action_type'] ?? ''
    ];
}

$start_date = $_SESSION[$filter_key]['start_date'] ?? (isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days')));
$end_date = $_SESSION[$filter_key]['end_date'] ?? (isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'));
$filter_action = $_SESSION[$filter_key]['action_type'] ?? (isset($_GET['action_type']) ? $_GET['action_type'] : '');

// Fetch distinct action types for dropdown
$actions_query = "SELECT DISTINCT sal.action_type FROM {$system_audit_log_source} ORDER BY sal.action_type";
$actions_result = $conn->query($actions_query);

// Build Main Query
$sql = "SELECT sal.* FROM {$system_audit_log_source} WHERE DATE(sal.logged_at) BETWEEN ? AND ?";
$params = [$start_date, $end_date];
$types = "ss";

if (!empty($filter_action)) {
    $sql .= " AND sal.action_type = ?";
    $params[] = $filter_action;
    $types .= "s";
}

$sql .= " ORDER BY sal.logged_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Helper for Badge Colors
function getActionBadgeClass($action) {
    $action = strtoupper($action);
    if (strpos($action, 'DELETE') !== false || strpos($action, 'VOID') !== false || strpos($action, 'REJECT') !== false) {
        return 'badge-danger';
    } elseif (strpos($action, 'CREATE') !== false || strpos($action, 'ADD') !== false || strpos($action, 'APPROVE') !== false) {
        return 'badge-success';
    } elseif (strpos($action, 'UPDATE') !== false || strpos($action, 'EDIT') !== false) {
        return 'badge-warning';
    } elseif (strpos($action, 'LOGIN') !== false || strpos($action, 'LOGOUT') !== false) {
        return 'badge-info';
    }
    return 'badge-secondary';
}
?>

<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<div class="main-content page-container">
    <div class="dashboard-header">
        <div>
            <h1>System Audit Log</h1>
            <p class="text-muted">Track system usage and sensitive actions.</p>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-2"></i> Back</a>
    </div>

    <!-- Filter Form -->
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
                <label for="start_date">From Date</label>
                <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div class="form-group">
                <label for="end_date">To Date</label>
                <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <div class="form-group">
                <label for="action_type">Action Type</label>
                <select id="action_type" name="action_type">
                    <option value="">All Actions</option>
                    <?php while($act = $actions_result->fetch_assoc()): ?>
                        <option value="<?php echo $act['action_type']; ?>" <?php echo ($filter_action == $act['action_type']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($act['action_type']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-submit">Apply Filters</button>
            <a href="?reset=1" class="btn-cancel">Reset</a>
        </div>
    </form>

    <div class="filter-info-bar">
        <i class="fas fa-calendar-alt"></i>
        <span>Showing logs from <strong><?php echo date('d M Y', strtotime($start_date)); ?></strong> to <strong><?php echo date('d M Y', strtotime($end_date)); ?></strong></span>
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 data-table" style="width: 100%;">
                    <thead class="bg-light">
                        <tr>
                            <th style="width: 180px;">Time</th>
                            <th style="width: 150px;">User</th>
                            <th style="width: 150px;">Action</th>
                            <th>Details</th>
                            <th style="width: 100px;">Target ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo date('d M Y', strtotime($row['logged_at'])); ?></div>
                                        <div class="small text-muted"><?php echo date('h:i:s A', strtotime($row['logged_at'])); ?></div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar-circle-sm bg-light text-secondary d-flex align-items-center justify-content-center" style="width: 24px; height: 24px; border-radius: 50%; font-size: 0.7rem;">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <span class="text-dark"><?php echo htmlspecialchars($row['username']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo getActionBadgeClass($row['action_type']); ?>">
                                            <?php echo htmlspecialchars($row['action_type']); ?>
                                        </span>
                                    </td>
                                    <td class="text-wrap" style="max-width: 400px;">
                                        <?php echo htmlspecialchars($row['details']); ?>
                                    </td>
                                    <td class="text-center font-monospace">
                                        <?php echo $row['target_id'] ? '#' . $row['target_id'] : '-'; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        $.fn.dataTable.ext.errMode = 'none';
        $('.data-table').DataTable({
            "paging": true,
            "ordering": true,
            "order": [[ 0, "desc" ]], // Sort by Time (Column 0) Descending
            "info": true,
            "searching": true,
            "pageLength": 20,
            "lengthMenu": [10, 20, 50, 100],
            "language": {
                "search": "Search logs:",
                "lengthMenu": "Show _MENU_ entries",
                "emptyTable": "No logs found for the selected period."
            }
        });
    });
</script>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>
