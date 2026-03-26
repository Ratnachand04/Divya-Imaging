<?php
require_once 'includes/header.php';

$users = $conn->query("SELECT * FROM users ORDER BY role, username");
?>

<div class="card">
    <h2>User Accounts</h2>
    <div class="table-responsive">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Role</th>
                <th>Active</th>
                <th>Created At</th>
                <th>Password Hash</th>
            </tr>
        </thead>
        <tbody>
            <?php while($u = $users->fetch_assoc()): ?>
            <tr>
                <td><?php echo $u['id']; ?></td>
                <td>
                    <?php echo htmlspecialchars($u['username']); ?>
                    <?php if((int) $u['id'] === (int) $_SESSION['user_id']): ?>
                        <span class="badge badge-info" style="margin-left:5px;">You</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge <?php echo in_array($u['role'], ['platform_admin', 'developer'], true) ? 'badge-info' : 'badge-inactive'; ?>">
                        <?php echo ucfirst($u['role']); ?>
                    </span>
                </td>
                <td>
                    <span class="badge <?php echo $u['is_active'] ? 'badge-active' : 'badge-danger'; ?>">
                        <?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                </td>
                <td><?php echo $u['created_at']; ?></td>
                <td><code style="color:var(--text-muted);"><?php echo substr($u['password'], 0, 10) . '...'; ?></code></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
