<?php
$page_title = "Employee Management";
// FIX: The required role must be set to "manager"
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$users_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'users', 'u') : '`users` u';

$feedback = isset($_SESSION['feedback']) ? $_SESSION['feedback'] : '';
unset($_SESSION['feedback']);

// --- HANDLE ADD NEW EMPLOYEE FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $role = trim($_POST['role']);

    if (empty($username) || empty($password) || empty($role)) {
        $feedback = "<div class='error-banner'>Username, password, and role are required.</div>";
    } elseif ($role === 'superadmin') {
        $feedback = "<div class='error-banner'>Managers cannot create Superadmin accounts.</div>";
    } else {
        $stmt_check = $conn->prepare("SELECT u.id FROM {$users_source} WHERE u.username = ?");
        $stmt_check->bind_param("s", $username);
        $stmt_check->execute();
        $stmt_check->store_result();

        if ($stmt_check->num_rows > 0) {
            $feedback = "<div class='error-banner'>Error: Username '{$username}' already exists.</div>";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt_insert = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
            $stmt_insert->bind_param("sss", $username, $hashed_password, $role);

            if ($stmt_insert->execute()) {
                $new_user_id = $stmt_insert->insert_id;
                $feedback = "<div class='success-banner'>Employee '{$username}' added successfully.</div>";

                require_once '../includes/functions.php';
                $details = "Manager created new user '{$username}' (ID: {$new_user_id}) with role '{$role}'.";
                log_system_action($conn, 'USER_CREATED', $new_user_id, $details);
            } else {
                $feedback = "<div class='error-banner'>Error: Could not add the employee.</div>";
            }
            $stmt_insert->close();
        }
        $stmt_check->close();
    }
}

// Fetch users for the table (excluding privileged roles)
$users_result = $conn->query("SELECT u.id, u.username, u.role, u.is_active, u.created_at FROM {$users_source} WHERE u.role NOT IN ('superadmin', 'platform_admin', 'developer') ORDER BY u.username ASC");

require_once '../includes/header.php';
?>
<div class="page-container">
    <div class="dashboard-header">
        <h1>Employee Management</h1>
        <p>Add, view, and manage employee accounts in the system.</p>
    </div>
    
    <?php if($feedback) echo $feedback; ?>

    <div class="filter-form compact-filters">
        <h3 style="width: 100%; margin-bottom: 15px;">Add New Employee</h3>
        <form action="manage_employees.php" method="POST" style="display: contents;">
            <input type="hidden" name="add_employee" value="1">
            
            <div class="filter-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required placeholder="Enter username">
            </div>
            
            <div class="filter-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required placeholder="Enter password">
            </div>

            <div class="filter-group" style="align-self: flex-end;">
                <label style="display: flex; align-items: center; gap: 6px; font-weight: normal;">
                    <input type="checkbox" id="togglePassword">
                    Show password
                </label>
            </div>
            
            <div class="filter-group">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="">-- Select Role --</option>
                    <option value="receptionist">Receptionist</option>
                    <option value="accountant">Accountant</option>
                    <option value="writer">Writer</option>
                    <option value="manager">Manager</option>
                </select>
            </div>
            
            <div class="filter-actions" style="margin-left: 0;">
                <button type="submit" class="btn-submit">Add Employee</button>
            </div>
        </form>
    </div>

    <div class="table-container">
        <h3>Existing Employees</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users_result->num_rows > 0): while($user = $users_result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $user['id']; ?></td>
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo ucfirst($user['role']); ?></td>
                    <td><?php echo $user['is_active'] ? '<span class="status-paid">Active</span>' : '<span class="status-pending">Inactive</span>'; ?></td>
                    <td><?php echo date('d-m-Y', strtotime($user['created_at'])); ?></td>
                    <td>
                        <a href="edit_employee.php?id=<?php echo $user['id']; ?>" class="btn-action btn-edit">Edit</a>
                        <a href="delete_employee.php?id=<?php echo $user['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Are you sure you want to permanently delete user <?php echo htmlspecialchars($user['username']); ?>?');">Delete</a>
                    </td>
                </tr>
                <?php endwhile; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    </div>
    <script>
    (function () {
        const passwordField = document.getElementById('password');
        const toggle = document.getElementById('togglePassword');

        if (!passwordField || !toggle) {
            return;
        }

        toggle.addEventListener('change', function () {
            passwordField.type = this.checked ? 'text' : 'password';
        });
    })();
    </script>
    <?php require_once '../includes/footer.php'; ?>