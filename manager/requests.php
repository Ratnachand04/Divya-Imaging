<?php
// manager/requests.php
$page_title = "Bill Edit Request History";
$required_role = "manager"; //
require_once '../includes/auth_check.php'; //
require_once '../includes/db_connect.php'; //

// --- Handle Filters ---
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$receptionist_filter = isset($_GET['receptionist_id']) && $_GET['receptionist_id'] !== 'all' ? (int)$_GET['receptionist_id'] : 'all';
$status_filter = isset($_GET['status']) && $_GET['status'] !== 'all' ? trim($_GET['status']) : 'all';

// --- Fetch Receptionists for Filter Dropdown ---
$receptionists_result = $conn->query("SELECT id, username FROM users WHERE role = 'receptionist' ORDER BY username");
$receptionists = $receptionists_result->fetch_all(MYSQLI_ASSOC);

// --- Fetch ALL requests, using LEFT JOINs and applying filters ---
$sql = "SELECT
            r.id, r.bill_id, r.reason_for_change, r.created_at, r.status,
            u.username AS receptionist,
            p.name as patient_name,
            p.uid as patient_uid
        FROM bill_edit_requests r
        JOIN users u ON r.receptionist_id = u.id
        LEFT JOIN bills b ON r.bill_id = b.id
        LEFT JOIN patients p ON b.patient_id = p.id
        "; // Base query joining necessary tables

$where_clauses = ["DATE(r.created_at) BETWEEN ? AND ?"];
$params = [$start_date, $end_date];
$types = 'ss';

if ($receptionist_filter !== 'all') {
    $where_clauses[] = "r.receptionist_id = ?";
    $params[] = $receptionist_filter;
    $types .= 'i';
}
if ($status_filter !== 'all') {
    $where_clauses[] = "r.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$sql .= " WHERE " . implode(' AND ', $where_clauses) . " ORDER BY r.created_at DESC";

$stmt = $conn->prepare($sql);
if ($stmt === false) { die("Error preparing query: " . $conn->error); }

// Bind parameters dynamically
$stmt->bind_param($types, ...$params);
$stmt->execute();
$requests = $stmt->get_result();

require_once '../includes/header.php'; //
?>
<div class="page-container">
    <div class="dashboard-header">
        <h1>Bill Edit Request History</h1>
        <p>Review all past and present requests submitted by receptionists to modify bills.</p>
    </div>
    <?php
        if (isset($_SESSION['feedback'])) {
            echo $_SESSION['feedback'];
            unset($_SESSION['feedback']); //
        }
    ?>

    <form action="requests.php" method="GET" class="filter-form compact-filters" style="margin-bottom: 2rem;">
        <div class="filter-group">
            <label for="start_date">Start Date</label>
            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>
        <div class="filter-group">
            <label for="end_date">End Date</label>
            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>
        <div class="filter-group">
            <label for="receptionist_id">Receptionist</label> 
            <select name="receptionist_id" id="receptionist_id">
                <option value="all">All Receptionists</option>
                <?php foreach ($receptionists as $rec): ?>
                    <option value="<?php echo $rec['id']; ?>" <?php if($receptionist_filter == $rec['id']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($rec['username']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="status">Status</label>
            <select name="status" id="status">
                <option value="all" <?php if($status_filter == 'all') echo 'selected'; ?>>All Statuses</option>
                <option value="pending" <?php if($status_filter == 'pending') echo 'selected'; ?>>Pending</option>
                <option value="approved" <?php if($status_filter == 'approved') echo 'selected'; ?>>Approved</option>
                <option value="rejected" <?php if($status_filter == 'rejected') echo 'selected'; ?>>Rejected</option>
                <option value="completed" <?php if($status_filter == 'completed') echo 'selected'; ?>>Completed</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-submit">Filter</button>
            <a href="requests.php" class="btn-cancel" style="text-decoration:none;">Reset</a>
        </div>
    </form>
    <div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Request ID</th>
                <th>Bill ID</th>
                <th>Patient ID</th>
                <th>Patient Name</th>
                <th>Receptionist</th>
                <th>Reason</th>
                <th>Requested At</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($requests && $requests->num_rows > 0): while($req = $requests->fetch_assoc()): ?>
            <tr>
                <td><?php echo $req['id']; ?></td>
                <td><?php echo $req['bill_id']; ?></td>
                <td><span style="font-size:0.82rem;color:#666;"><?php echo $req['patient_uid'] ? htmlspecialchars($req['patient_uid']) : '—'; ?></span></td>
                <td><?php echo $req['patient_name'] ? htmlspecialchars($req['patient_name']) : '<em style="color:red;">[Bill Deleted]</em>'; ?></td>
                <td><?php echo htmlspecialchars($req['receptionist']); ?></td>
                <td><?php echo htmlspecialchars($req['reason_for_change']); ?></td>
                <td><?php echo date('d-m-Y H:i', strtotime($req['created_at'])); ?></td>
                <td>
                    <span class="status-<?php echo strtolower($req['status']); ?>">
                        <?php echo ucfirst($req['status']); ?>
                    </span>
                </td>
                <td>
                    <a href="view_request_details.php?request_id=<?php echo $req['id']; ?>" class="btn-action btn-view">
                        View Details
                    </a>
                </td>
            </tr>
            <?php endwhile; endif; ?>
        </tbody>
    </table>
    </div>
</div>



<?php $stmt->close(); require_once '../includes/footer.php'; ?>