<?php
$page_title = "Employee Management";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/header.php';

$sa_active_page = 'global_settings.php';
$feedback = isset($_SESSION['feedback']) ? $_SESSION['feedback'] : '';
unset($_SESSION['feedback']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee_access'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? '');

    $allowedRoles = ['receptionist', 'accountant', 'writer', 'manager'];

    if ($username === '' || $password === '' || $role === '') {
        $feedback = "<div class='error-banner'>Username, password, and role are required.</div>";
    } elseif (!in_array($role, $allowedRoles, true)) {
        $feedback = "<div class='error-banner'>Invalid role selected.</div>";
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt_check->bind_param('s', $username);
        $stmt_check->execute();
        $stmt_check->store_result();

        if ($stmt_check->num_rows > 0) {
            $feedback = "<div class='error-banner'>Username already exists.</div>";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt_insert = $conn->prepare("INSERT INTO users (username, password, role, is_active) VALUES (?, ?, ?, 1)");
            $stmt_insert->bind_param('sss', $username, $hash, $role);
            if ($stmt_insert->execute()) {
                $newId = $stmt_insert->insert_id;
                log_system_action($conn, 'USER_CREATED', $newId, "Superadmin created login account '{$username}' with role '{$role}'.");
                $feedback = "<div class='success-banner'>Employee access account created.</div>";
            } else {
                $feedback = "<div class='error-banner'>Could not create account.</div>";
            }
            $stmt_insert->close();
        }
        $stmt_check->close();
    }
}

$accounts = $conn->query("SELECT id, username, role, is_active, created_at FROM users WHERE role IN ('receptionist', 'accountant', 'writer', 'manager') AND COALESCE(NULLIF(full_name, ''), '') = '' ORDER BY username ASC");
?>

<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-access-page { display: grid; gap: 1rem; }
.sa-access-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-access-head h1 { margin: 0; color: #1e3a8a; font-size: 1.45rem; }
.sa-access-head p { margin: 0.2rem 0 0; color: #64748b; }
.sa-access-form {
    display: grid;
    grid-template-columns: repeat(4, minmax(180px, 1fr));
    gap: 0.75rem;
    align-items: start;
}
.sa-access-form .form-group { margin: 0; }
.sa-access-form label { display: block; margin-bottom: 0.3rem; color: #475569; font-weight: 700; }
.sa-access-form .wide { grid-column: 1 / -1; }
.sa-access-form input,
.sa-access-form select {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 0.55rem 0.65rem;
}
.sa-show-pass {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: #64748b;
    font-weight: 600;
    margin-top: 0.15rem;
}
.sa-access-form label.sa-show-pass {
    display: inline-flex;
    margin-bottom: 0;
}
.sa-show-pass input[type="checkbox"] {
    width: 16px;
    height: 16px;
    margin: 0;
}
.sa-access-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}
.sa-access-table th {
    background: #f8fafc;
    color: #1e3a8a;
    padding: 0.62rem 0.55rem;
    border-bottom: 1px solid #e2e8f0;
    text-transform: uppercase;
    font-size: 0.78rem;
}
.sa-access-table td {
    padding: 0.62rem 0.55rem;
    border-bottom: 1px solid #e2e8f0;
}
.sa-access-table tr:nth-child(even) { background: #fcfdff; }
@media (max-width: 1200px) {
    .sa-access-form {
        grid-template-columns: repeat(2, minmax(220px, 1fr));
    }
}
@media (max-width: 700px) {
    .sa-access-form {
        grid-template-columns: 1fr;
    }
}
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<section class="sa-access-page">
    <article class="sa-access-card sa-access-head">
        <h1>Employee Management</h1>
        <p>Manage employee access accounts separately from employee master details.</p>
    </article>

    <?php if ($feedback) echo $feedback; ?>

    <article class="sa-access-card">
        <h3>Add Employee Access</h3>
        <form method="POST" class="sa-access-form" autocomplete="off">
            <input type="hidden" name="add_employee_access" value="1">

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <div class="form-group">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="">-- Select Role --</option>
                    <option value="receptionist">Receptionist</option>
                    <option value="accountant">Accountant</option>
                    <option value="writer">Writer</option>
                    <option value="manager">Manager</option>
                </select>
            </div>

            <div class="form-group wide">
                <label class="sa-show-pass" for="show_password">
                    <input type="checkbox" id="show_password"> Show password
                </label>
            </div>

            <div class="form-group">
                <button type="submit" class="btn-submit">Add Access</button>
            </div>
        </form>
    </article>

    <article class="sa-access-card">
        <h3>Existing Employee Access Accounts</h3>
        <div class="table-responsive">
            <table class="data-table custom-table sa-access-table">
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
                    <?php if ($accounts && $accounts->num_rows > 0): while($row = $accounts->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo (int)$row['id']; ?></td>
                        <td><?php echo htmlspecialchars($row['username']); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($row['role'])); ?></td>
                        <td><?php echo (int)$row['is_active'] ? '<span class="status-paid">Active</span>' : '<span class="status-pending">Inactive</span>'; ?></td>
                        <td><?php echo date('d-m-Y', strtotime($row['created_at'])); ?></td>
                        <td>
                            <a href="employee_access_edit.php?id=<?php echo (int)$row['id']; ?>" class="btn-action btn-edit">Edit</a>
                            <a href="employee_access_delete.php?id=<?php echo (int)$row['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Delete this access account?');">Delete</a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="6">No access accounts found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const pass = document.getElementById('password');
    const toggle = document.getElementById('show_password');
    if (pass && toggle) {
        toggle.addEventListener('change', function () {
            pass.type = this.checked ? 'text' : 'password';
        });
    }
});
</script>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>
