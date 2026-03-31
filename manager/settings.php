<?php
$page_title = "Manager Settings";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$defaults = [
    'default_date_range' => 'today',
    'show_pending_bills_card' => true,
    'show_revenue_chart' => true,
    'pending_bill_reminders' => true,
    'request_approval_alerts' => true,
    'require_password_for_approvals' => false,
    'session_timeout_minutes' => 60
];

$allowed_ranges = ['today', 'week', 'month', 'last_month'];
$feedback = '';

try {
    app_settings_ensure_schema($conn);
} catch (Exception $e) {
    $feedback = "<div class='error-banner'>Could not initialize settings storage: " . htmlspecialchars($e->getMessage()) . "</div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_manager_settings'])) {
    $default_date_range = in_array($_POST['default_date_range'] ?? '', $allowed_ranges, true) ? $_POST['default_date_range'] : $defaults['default_date_range'];
    $show_pending_bills_card = isset($_POST['show_pending_bills_card']) ? '1' : '0';
    $show_revenue_chart = isset($_POST['show_revenue_chart']) ? '1' : '0';
    $pending_bill_reminders = isset($_POST['pending_bill_reminders']) ? '1' : '0';
    $request_approval_alerts = isset($_POST['request_approval_alerts']) ? '1' : '0';
    $require_password_for_approvals = isset($_POST['require_password_for_approvals']) ? '1' : '0';
    $session_timeout_minutes = (int)($_POST['session_timeout_minutes'] ?? $defaults['session_timeout_minutes']);
    if ($session_timeout_minutes < 5) {
        $session_timeout_minutes = 5;
    }
    if ($session_timeout_minutes > 480) {
        $session_timeout_minutes = 480;
    }

    $user_id = (int)$_SESSION['user_id'];
    $ok = true;
    $conn->begin_transaction();

    $scope = 'manager_user';
    $ok = $ok && app_settings_set($conn, $scope, $user_id, 'default_date_range', $default_date_range, 'string', 'dashboard', $user_id);
    $ok = $ok && app_settings_set($conn, $scope, $user_id, 'show_pending_bills_card', $show_pending_bills_card, 'bool', 'dashboard', $user_id);
    $ok = $ok && app_settings_set($conn, $scope, $user_id, 'show_revenue_chart', $show_revenue_chart, 'bool', 'dashboard', $user_id);
    $ok = $ok && app_settings_set($conn, $scope, $user_id, 'pending_bill_reminders', $pending_bill_reminders, 'bool', 'notifications', $user_id);
    $ok = $ok && app_settings_set($conn, $scope, $user_id, 'request_approval_alerts', $request_approval_alerts, 'bool', 'notifications', $user_id);
    $ok = $ok && app_settings_set($conn, $scope, $user_id, 'require_password_for_approvals', $require_password_for_approvals, 'bool', 'security', $user_id);
    $ok = $ok && app_settings_set($conn, $scope, $user_id, 'session_timeout_minutes', (string)$session_timeout_minutes, 'int', 'security', $user_id);

    if ($ok) {
        $conn->commit();
        log_system_action($conn, 'MANAGER_SETTINGS_UPDATED', $user_id, 'Manager settings were updated via settings.php');
        $_SESSION['feedback'] = "<div class='success-banner'>Settings saved successfully.</div>";
    } else {
        $conn->rollback();
        $_SESSION['feedback'] = "<div class='error-banner'>Failed to save settings. Please try again.</div>";
    }

    header('Location: settings.php');
    exit();
}

if (isset($_SESSION['feedback'])) {
    $feedback = $_SESSION['feedback'];
    unset($_SESSION['feedback']);
}

$current_settings = app_settings_resolve($conn, $defaults, [
    ['scope' => 'global', 'scope_id' => 0],
    ['scope' => 'manager_role', 'scope_id' => 0],
    ['scope' => 'manager_user', 'scope_id' => (int)$_SESSION['user_id']]
]);
require_once '../includes/header.php';
?>

<div class="main-content page-container">
    <div class="dashboard-header">
        <div>
            <h1>Manager Settings</h1>
            <p>Configure dashboard behavior and manager-level preferences.</p>
        </div>
    </div>

    <?php if (!empty($feedback)): ?>
        <?php echo $feedback; ?>
    <?php endif; ?>

    <form method="POST" class="settings-form" novalidate>
        <input type="hidden" name="save_manager_settings" value="1">

        <div class="settings-grid">
            <section class="settings-card">
                <h3><i class="fas fa-chart-line" aria-hidden="true"></i> Dashboard Preferences</h3>
                <p>Control dashboard defaults and card/chart visibility.</p>

                <div class="settings-field">
                    <label for="default_date_range">Default Date Range</label>
                    <select id="default_date_range" name="default_date_range">
                        <option value="today" <?php echo ($current_settings['default_date_range'] === 'today') ? 'selected' : ''; ?>>Today</option>
                        <option value="week" <?php echo ($current_settings['default_date_range'] === 'week') ? 'selected' : ''; ?>>This Week</option>
                        <option value="month" <?php echo ($current_settings['default_date_range'] === 'month') ? 'selected' : ''; ?>>This Month</option>
                        <option value="last_month" <?php echo ($current_settings['default_date_range'] === 'last_month') ? 'selected' : ''; ?>>Last Month</option>
                    </select>
                </div>

                <label class="settings-toggle">
                    <input type="checkbox" name="show_pending_bills_card" <?php echo !empty($current_settings['show_pending_bills_card']) ? 'checked' : ''; ?>>
                    <span>Show Pending Bills KPI card on dashboard</span>
                </label>

                <label class="settings-toggle">
                    <input type="checkbox" name="show_revenue_chart" <?php echo !empty($current_settings['show_revenue_chart']) ? 'checked' : ''; ?>>
                    <span>Show Revenue by Payment Method chart</span>
                </label>
            </section>

            <section class="settings-card">
                <h3><i class="fas fa-bell" aria-hidden="true"></i> Notifications</h3>
                <p>Manage manager-level alerts for daily operations.</p>

                <label class="settings-toggle">
                    <input type="checkbox" name="pending_bill_reminders" <?php echo !empty($current_settings['pending_bill_reminders']) ? 'checked' : ''; ?>>
                    <span>Enable pending bill reminders</span>
                </label>

                <label class="settings-toggle">
                    <input type="checkbox" name="request_approval_alerts" <?php echo !empty($current_settings['request_approval_alerts']) ? 'checked' : ''; ?>>
                    <span>Enable request approval alerts</span>
                </label>
            </section>

            <section class="settings-card">
                <h3><i class="fas fa-user-shield" aria-hidden="true"></i> Security</h3>
                <p>Protect sensitive manager actions with extra checks.</p>

                <label class="settings-toggle">
                    <input type="checkbox" name="require_password_for_approvals" <?php echo !empty($current_settings['require_password_for_approvals']) ? 'checked' : ''; ?>>
                    <span>Require password confirmation for approval actions</span>
                </label>

                <div class="settings-field">
                    <label for="session_timeout_minutes">Session Timeout (minutes)</label>
                    <input type="number" id="session_timeout_minutes" name="session_timeout_minutes" min="5" max="480" value="<?php echo (int)$current_settings['session_timeout_minutes']; ?>">
                </div>
            </section>
        </div>

        <div class="settings-actions">
            <button type="submit" class="btn-submit">Save Settings</button>
        </div>
    </form>
</div>

<?php require_once '../includes/footer.php'; ?>
