<?php
$page_title = 'My Edit Requests';
$required_role = 'receptionist';
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_bill_edit_request_workflow_schema($conn);

$receptionist_id = (int)($_SESSION['user_id'] ?? 0);
$status_options = ['pending', 'query_raised', 'approved', 'rejected', 'completed'];

$has_explicit_date_filter = isset($_GET['start_date']) || isset($_GET['end_date']);
$default_start_date = date('Y-m-d', strtotime('-6 months'));
$default_end_date = date('Y-m-d');
$using_auto_range = !$has_explicit_date_filter;

if ($using_auto_range) {
    $range_stmt = $conn->prepare('SELECT DATE(MIN(created_at)) AS min_date, DATE(MAX(created_at)) AS max_date FROM bill_edit_requests WHERE receptionist_id = ?');
    if ($range_stmt) {
        $range_stmt->bind_param('i', $receptionist_id);
        $range_stmt->execute();
        $range_row = $range_stmt->get_result()->fetch_assoc();
        $range_stmt->close();

        if ($range_row && !empty($range_row['max_date'])) {
            $default_end_date = (string)$range_row['max_date'];
            $six_months_back = date('Y-m-d', strtotime($default_end_date . ' -6 months'));
            if (!empty($range_row['min_date']) && $six_months_back > (string)$range_row['min_date']) {
                $default_start_date = $six_months_back;
            } elseif (!empty($range_row['min_date'])) {
                $default_start_date = (string)$range_row['min_date'];
            }
        }
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

$status_filter = 'all';
if (isset($_GET['status']) && $_GET['status'] !== 'all') {
    $status_candidate = normalize_bill_edit_request_status($_GET['status']);
    if (in_array($status_candidate, $status_options, true)) {
        $status_filter = $status_candidate;
    }
}

$sql = "SELECT
            r.id,
            r.bill_id,
            r.reason_for_change,
            r.status,
            r.manager_comment,
            r.receptionist_response,
            r.created_at,
            r.updated_at,
            r.receptionist_unread,
            p.uid AS patient_uid,
            p.name AS patient_name
        FROM bill_edit_requests r
        LEFT JOIN bills b ON r.bill_id = b.id
        LEFT JOIN patients p ON b.patient_id = p.id
        WHERE r.receptionist_id = ?
          AND DATE(r.created_at) BETWEEN ? AND ?";

$params = [$receptionist_id, $start_date, $end_date];
$types = 'iss';

if ($status_filter !== 'all') {
    $sql .= ' AND r.status = ?';
    $params[] = $status_filter;
    $types .= 's';
}

$sql .= " ORDER BY r.receptionist_unread DESC,
               CASE r.status
                   WHEN 'query_raised' THEN 1
                   WHEN 'pending' THEN 2
                   WHEN 'approved' THEN 3
                   WHEN 'rejected' THEN 4
                   WHEN 'completed' THEN 5
                   ELSE 6
               END,
               r.updated_at DESC,
               r.id DESC";

$requests = [];
$unread_request_ids = [];

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare request list query: ' . $conn->error);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
    if (!empty($row['receptionist_unread'])) {
        $unread_request_ids[] = (int)$row['id'];
    }
}
$stmt->close();

if (!empty($unread_request_ids)) {
    $unique_ids = array_values(array_unique($unread_request_ids));
    $placeholders = implode(',', array_fill(0, count($unique_ids), '?'));
    $mark_sql = "UPDATE bill_edit_requests SET receptionist_unread = 0 WHERE id IN ({$placeholders})";
    $mark_stmt = $conn->prepare($mark_sql);
    if ($mark_stmt) {
        $mark_types = str_repeat('i', count($unique_ids));
        $mark_stmt->bind_param($mark_types, ...$unique_ids);
        $mark_stmt->execute();
        $mark_stmt->close();
    }
}

require_once '../includes/header.php';
?>

<div class="main-content page-container">
    <div class="dashboard-header">
        <div>
            <h1>My Edit Requests</h1>
            <p>Track manager response for all bill edit requests and reply when query is raised.</p>
        </div>
    </div>

    <?php if (isset($_SESSION['feedback'])): ?>
        <?php echo $_SESSION['feedback']; ?>
        <?php unset($_SESSION['feedback']); ?>
    <?php endif; ?>

    <?php if ($using_auto_range): ?>
    <div class="success-banner" style="margin-bottom: 1rem;">
        Smart default date range applied: <?php echo htmlspecialchars($start_date); ?> to <?php echo htmlspecialchars($end_date); ?>.
    </div>
    <?php endif; ?>

    <form action="requests.php" method="GET" class="filter-form compact-filters" style="margin-bottom: 1rem;">
        <div class="filter-group">
            <label for="start_date">Start Date</label>
            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>
        <div class="filter-group">
            <label for="end_date">End Date</label>
            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>
        <div class="filter-group">
            <label for="status">Status</label>
            <select name="status" id="status">
                <option value="all" <?php echo ($status_filter === 'all') ? 'selected' : ''; ?>>All Statuses</option>
                <option value="pending" <?php echo ($status_filter === 'pending') ? 'selected' : ''; ?>>Pending</option>
                <option value="query_raised" <?php echo ($status_filter === 'query_raised') ? 'selected' : ''; ?>>Query Raised</option>
                <option value="approved" <?php echo ($status_filter === 'approved') ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo ($status_filter === 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                <option value="completed" <?php echo ($status_filter === 'completed') ? 'selected' : ''; ?>>Completed</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-submit">Apply</button>
            <a href="requests.php" class="btn-cancel" style="text-decoration:none;">Reset</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Request</th>
                    <th>Bill</th>
                    <th>Patient</th>
                    <th>Your Request</th>
                    <th>Manager Remarks</th>
                    <th>Updated</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($requests)): ?>
                    <?php foreach ($requests as $req): ?>
                        <?php
                        $status_key = normalize_bill_edit_request_status($req['status'] ?? 'pending');
                        $status_label = get_bill_edit_request_status_label($status_key);
                        $status_class = get_bill_edit_request_status_class($status_key);
                        $request_reason = trim((string)($req['reason_for_change'] ?? ''));
                        $reason_preview = strlen($request_reason) > 120 ? substr($request_reason, 0, 120) . '...' : $request_reason;
                        $manager_comment = trim((string)($req['manager_comment'] ?? ''));
                        $manager_preview = strlen($manager_comment) > 100 ? substr($manager_comment, 0, 100) . '...' : $manager_comment;
                        ?>
                        <tr>
                            <td>
                                <strong>#<?php echo (int)$req['id']; ?></strong>
                                <?php if (!empty($req['receptionist_unread'])): ?>
                                    <div><span class="status-pending">New</span></div>
                                <?php endif; ?>
                            </td>
                            <td>#<?php echo (int)$req['bill_id']; ?></td>
                            <td>
                                <div><?php echo !empty($req['patient_name']) ? htmlspecialchars((string)$req['patient_name']) : '<em style="color:#9b1c1c;">Bill or patient missing</em>'; ?></div>
                                <small style="color:#64748b;"><?php echo !empty($req['patient_uid']) ? htmlspecialchars((string)$req['patient_uid']) : 'UID unavailable'; ?></small>
                            </td>
                            <td title="<?php echo htmlspecialchars($request_reason); ?>"><?php echo htmlspecialchars($reason_preview); ?></td>
                            <td title="<?php echo htmlspecialchars($manager_comment); ?>"><?php echo $manager_preview !== '' ? htmlspecialchars($manager_preview) : '—'; ?></td>
                            <td><?php echo date('d-m-Y H:i', strtotime((string)$req['updated_at'])); ?></td>
                            <td><span class="<?php echo htmlspecialchars($status_class); ?>"><?php echo htmlspecialchars($status_label); ?></span></td>
                            <td>
                                <a href="view_request_details.php?request_id=<?php echo (int)$req['id']; ?>" class="btn-action btn-view">View Details</a>
                                <?php if ($status_key === 'query_raised'): ?>
                                    <a href="view_request_details.php?request_id=<?php echo (int)$req['id']; ?>#query-response" class="btn-action btn-edit">Respond</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align:center;">No requests found for the selected filters.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
