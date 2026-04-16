<?php
$page_title = "Edit Employee Access";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/header.php';

$sa_active_page = 'global_settings.php';
$feedback = '';

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($userId <= 0) {
    header('Location: employee_access.php');
    exit;
}

$stmt = $conn->prepare("SELECT id, username, role, is_active FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header('Location: employee_access.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $newPassword = trim($_POST['new_password'] ?? '');

    $allowedRoles = ['receptionist', 'accountant', 'writer', 'manager'];

    if ($username === '' || !in_array($role, $allowedRoles, true)) {
        $feedback = "<div class='error-banner'>Username and valid role are required.</div>";
    } else {
        $stmtUpdate = $conn->prepare("UPDATE users SET username = ?, role = ?, is_active = ? WHERE id = ?");
        $stmtUpdate->bind_param('ssii', $username, $role, $isActive, $userId);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        if ($newPassword !== '') {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmtPass = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmtPass->bind_param('si', $hash, $userId);
            $stmtPass->execute();
            $stmtPass->close();
        }

        log_system_action($conn, 'USER_UPDATED', $userId, "Superadmin updated access account '{$username}'.");
        $feedback = "<div class='success-banner'>Access account updated.</div>";
        $user['username'] = $username;
        $user['role'] = $role;
        $user['is_active'] = $isActive;
    }
}
?>

<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-edit-access { display: grid; gap: 1rem; }
.sa-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 0.8rem;
}
.sa-form-grid .wide { grid-column: 1 / -1; }
.sa-form-grid label { display: block; margin-bottom: 0.3rem; font-weight: 700; color: #475569; }
.sa-form-grid input,
.sa-form-grid select {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 0.55rem 0.65rem;
}
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<section class="sa-edit-access">
    <article class="sa-card">
        <h1 style="margin:0; color:#1e3a8a; font-size:1.35rem;">Edit Employee Access</h1>
        <p style="margin:0.2rem 0 0; color:#64748b;">Update login account details.</p>
    </article>

    <?php if ($feedback) echo $feedback; ?>

    <article class="sa-card">
        <form method="POST" class="sa-form-grid" autocomplete="off">
            <div>
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
            </div>
            <div>
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="receptionist" <?php echo $user['role'] === 'receptionist' ? 'selected' : ''; ?>>Receptionist</option>
                    <option value="accountant" <?php echo $user['role'] === 'accountant' ? 'selected' : ''; ?>>Accountant</option>
                    <option value="writer" <?php echo $user['role'] === 'writer' ? 'selected' : ''; ?>>Writer</option>
                    <option value="manager" <?php echo $user['role'] === 'manager' ? 'selected' : ''; ?>>Manager</option>
                </select>
            </div>
            <div>
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" placeholder="Leave blank to keep current password">
            </div>
            <div>
                <label style="display:flex; align-items:center; gap:0.5rem; margin-top:1.85rem;">
                    <input type="checkbox" name="is_active" value="1" <?php echo (int)$user['is_active'] ? 'checked' : ''; ?>> Active
                </label>
            </div>
            <div class="wide" style="display:flex; gap:0.55rem;">
                <button type="submit" class="btn-submit">Update Access</button>
                <a href="employee_access.php" class="btn-cancel">Cancel</a>
            </div>
        </form>
    </article>
</section>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>
