<?php
require_once 'includes/header.php';

// --- Handling Filters ---
$where_clauses = [];
$params = [];
$types = "";

// Filter by Action Type
$action_filter = isset($_GET['action_type']) ? $_GET['action_type'] : '';
if (!empty($action_filter)) {
    $where_clauses[] = "action_type = ?";
    $params[] = $action_filter;
    $types .= "s";
}

// Filter by User Name
$user_filter = isset($_GET['username']) ? $_GET['username'] : '';
if (!empty($user_filter)) {
    $where_clauses[] = "username LIKE ?";
    $params[] = "%" . $user_filter . "%";
    $types .= "s";
}

// Filter by Search (Details)
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
if (!empty($search_query)) {
    $where_clauses[] = "details LIKE ?";
    $params[] = "%" . $search_query . "%";
    $types .= "s";
}

$sql = "SELECT * FROM system_audit_log";
if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}
$sql .= " ORDER BY id DESC LIMIT 500";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get unique action types for dropdown
$actions_list = $conn->query("SELECT DISTINCT action_type FROM system_audit_log ORDER BY action_type");
?>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
        <div>
            <h2>System Audit Log</h2>
            <p style="color:var(--text-muted); margin:0;">Tracking all critical system activities.</p>
        </div>
        
        <form method="GET" style="display:flex; gap:10px; align-items:center;">
            <select name="action_type" class="form-control" style="width:auto;">
                <option value="">All Actions</option>
                <?php while($ac = $actions_list->fetch_assoc()): ?>
                    <option value="<?php echo htmlspecialchars($ac['action_type']); ?>" <?php if($action_filter === $ac['action_type']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($ac['action_type']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <input type="text" name="username" class="form-control" placeholder="User..." value="<?php echo htmlspecialchars($user_filter); ?>" style="width:120px;">
            <input type="text" name="search" class="form-control" placeholder="Search details..." value="<?php echo htmlspecialchars($search_query); ?>" style="width:150px;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <?php if(!empty($action_filter) || !empty($user_filter) || !empty($search_query)): ?>
                <a href="audit_log.php" class="btn btn-danger btn-sm" style="padding: 0.6rem 0.8rem;"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-responsive">
    <table>
        <thead>
            <tr>
                <th width="50">ID</th>
                <th width="150">User</th>
                <th width="150">Action</th>
                <th>Details</th>
                <th width="180">Timestamp</th>
            </tr>
        </thead>
        <tbody>
            <?php if($result && $result->num_rows > 0): while($row = $result->fetch_assoc()): 
                // Determine Badge Color
                $atype = strtoupper($row['action_type']);
                $badge = 'badge-info'; // Default blue
                if (strpos($atype, 'DELETE') !== false || strpos($atype, 'REJECT') !== false || strpos($atype, 'FAIL') !== false) {
                    $badge = 'badge-danger'; // Red
                } elseif (strpos($atype, 'UPDATE') !== false || strpos($atype, 'EDIT') !== false || strpos($atype, 'CHANGE') !== false) {
                    $badge = 'badge-warning'; // Yellow
                } elseif (strpos($atype, 'CREATE') !== false || strpos($atype, 'ADD') !== false || strpos($atype, 'APPROVE') !== false || strpos($atype, 'SUCCESS') !== false) {
                    $badge = 'badge-active'; // Green (Success)
                }
            ?>
            <tr>
                <td style="color:var(--text-muted); font-size:0.85em;">#<?php echo $row['id']; ?></td>
                <td>
                    <div style="font-weight:600; display:flex; align-items:center; gap:5px;">
                        <i class="fas fa-user-circle" style="color:#ccc;"></i> 
                        <?php echo htmlspecialchars($row['username']); ?>
                    </div>
                </td>
                <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($row['action_type']); ?></span></td>
                <td>
                    <?php echo htmlspecialchars($row['details']); ?>
                    <?php if($row['target_id']): ?>
                        <span style="display:inline-block; margin-left:5px; font-family:monospace; background:#f1f5f9; padding:2px 4px; border-radius:3px; font-size:0.8em; color:#64748b;">
                            ID: <?php echo $row['target_id']; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--text-muted); font-size:0.9em;">
                    <i class="far fa-clock" style="margin-right:4px;"></i> 
                    <?php echo date('M d, Y h:i A', strtotime(isset($row['created_at']) ? $row['created_at'] : (isset($row['timestamp']) ? $row['timestamp'] : 'now'))); ?>
                </td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="5" style="text-align:center; padding:3rem; color:var(--text-muted);">
                <i class="fas fa-search" style="font-size:2rem; margin-bottom:1rem; display:block; opacity:0.3;"></i>
                No logs found matching your criteria.
            </td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
