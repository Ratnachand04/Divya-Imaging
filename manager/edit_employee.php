<?php
$page_title = "Edit Employee";
// FIX: The required role must be set to "manager"
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$users_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'users', 'u') : '`users` u';

$feedback = '';
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$user_id) {
    header("Location: manage_employees.php");
    exit();
}

// Fetch current user data
$stmt_fetch_orig = $conn->prepare("SELECT u.username, u.role, u.is_active FROM {$users_source} WHERE u.id = ?");
$stmt_fetch_orig->bind_param("i", $user_id);
$stmt_fetch_orig->execute();
$original_user = $stmt_fetch_orig->get_result()->fetch_assoc();
$stmt_fetch_orig->close();

if (!$original_user) {
    die("User not found.");
}

// Security: Prevent manager from editing a superadmin or another manager
if ($original_user['role'] === 'superadmin' || ($original_user['role'] === 'manager' && $_SESSION['user_id'] != $user_id)) {
     die("Forbidden: You do not have permission to edit this user.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_user_id = (int)$_POST['user_id'];
    $username = trim($_POST['username']);
    $role = trim($_POST['role']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $new_password = trim($_POST['new_password']);

    // Prevent manager from deactivating their own account
    if ($posted_user_id === $_SESSION['user_id'] && !$is_active) {
        $feedback = "<div class='error-banner'>You cannot deactivate your own account.</div>";
    } else {
        // Update username, role, is_active
        $stmt_update = $conn->prepare("UPDATE users SET username = ?, role = ?, is_active = ? WHERE id = ?");
        $stmt_update->bind_param("ssii", $username, $role, $is_active, $posted_user_id);
        $stmt_update->execute();
        $stmt_update->close();

        // Update password if provided
        if (!empty($new_password)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt_pass = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt_pass->bind_param("si", $hashed_password, $posted_user_id);
            $stmt_pass->execute();
            $stmt_pass->close();
        }

        require_once '../includes/functions.php';
        $details = "Manager updated user '{$username}' (ID: {$posted_user_id}) with role '{$role}' and status " . ($is_active ? 'Active' : 'Inactive') . ".";
        log_system_action($conn, 'USER_UPDATED', $posted_user_id, $details);

        $feedback = "<div class='success-banner'>User updated successfully!</div>";
        // Refresh original user data to show new state
        $original_user = ['username' => $username, 'role' => $role, 'is_active' => $is_active];
    }
}

require_once '../includes/header.php';
?>
<div class="page-container">
    <div class="dashboard-header">
        <h1>Edit Employee: <?php echo htmlspecialchars($original_user['username']); ?></h1>
        <p>Update employee details or change password.</p>
    </div>
    
    <?php echo $feedback; ?>
    
    <div class="form-container" style="max-width: 600px;">
        <form action="edit_employee.php?id=<?php echo $user_id; ?>" method="POST">
            <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
            
            <h3 style="margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px;">User Details</h3>
            
            <div class="filter-group" style="margin-bottom: 15px;">
                <label for="username" style="display: block; margin-bottom: 5px; font-weight: 600;">Username</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($original_user['username']); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
            </div>
            
            <div class="filter-group" style="margin-bottom: 15px;">
                <label for="role" style="display: block; margin-bottom: 5px; font-weight: 600;">Role</label>
                <select id="role" name="role" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                    <option value="receptionist" <?php echo ($original_user['role'] == 'receptionist') ? 'selected' : ''; ?>>Receptionist</option>
                    <option value="manager" <?php echo ($original_user['role'] == 'manager') ? 'selected' : ''; ?>>Manager</option>
                    <option value="accountant" <?php echo ($original_user['role'] == 'accountant') ? 'selected' : ''; ?>>Accountant</option>
                    <option value="writer" <?php echo ($original_user['role'] == 'writer') ? 'selected' : ''; ?>>Writer</option>
                </select>
            </div>
            
            <div style="margin-bottom: 25px;">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" name="is_active" value="1" <?php echo $original_user['is_active'] ? 'checked' : ''; ?> style="width: 20px; height: 20px;"> 
                    <span style="font-weight: 600;">Active Account</span>
                </label>
            </div>
            
            <h3 style="margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 30px;">Change Password</h3>
            
            <div class="filter-group" style="margin-bottom: 25px;">
                <label for="new_password" style="display: block; margin-bottom: 5px; font-weight: 600;">New Password</label>
                <input type="password" id="new_password" name="new_password" placeholder="Leave blank to keep current password" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn-submit">Update User</button>
                <a href="manage_employees.php" class="btn-cancel">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>