<?php
$page_title = "Global Settings";
$required_role = "superadmin";
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
    'session_timeout_minutes' => 60,
    'notifications_default_recipient_type' => 'group_patients',
    'notifications_default_channel_email' => true,
    'notifications_default_channel_whatsapp' => true
];

$allowed_ranges = ['today', 'week', 'month', 'last_month'];
$allowed_recipient_types = ['group_patients', 'group_doctors', 'individual_doctor', 'individual_employee', 'custom'];
$feedback = '';

try {
    app_settings_ensure_schema($conn);
} catch (Exception $e) {
    $feedback = "<div class='error-banner'>Could not initialize global settings storage: " . htmlspecialchars($e->getMessage()) . "</div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_global_settings'])) {
    $default_date_range = in_array($_POST['default_date_range'] ?? '', $allowed_ranges, true)
        ? $_POST['default_date_range']
        : $defaults['default_date_range'];
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

    $default_recipient_type = in_array($_POST['notifications_default_recipient_type'] ?? '', $allowed_recipient_types, true)
        ? $_POST['notifications_default_recipient_type']
        : $defaults['notifications_default_recipient_type'];
    $default_channel_email = isset($_POST['notifications_default_channel_email']) ? '1' : '0';
    $default_channel_whatsapp = isset($_POST['notifications_default_channel_whatsapp']) ? '1' : '0';

    $scope = 'global';
    $scope_id = 0;
    $updated_by = (int)$_SESSION['user_id'];

    $conn->begin_transaction();
    $ok = true;
    $ok = $ok && app_settings_set($conn, $scope, $scope_id, 'default_date_range', $default_date_range, 'string', 'dashboard', $updated_by);
    $ok = $ok && app_settings_set($conn, $scope, $scope_id, 'show_pending_bills_card', $show_pending_bills_card, 'bool', 'dashboard', $updated_by);
    $ok = $ok && app_settings_set($conn, $scope, $scope_id, 'show_revenue_chart', $show_revenue_chart, 'bool', 'dashboard', $updated_by);
    $ok = $ok && app_settings_set($conn, $scope, $scope_id, 'pending_bill_reminders', $pending_bill_reminders, 'bool', 'notifications', $updated_by);
    $ok = $ok && app_settings_set($conn, $scope, $scope_id, 'request_approval_alerts', $request_approval_alerts, 'bool', 'notifications', $updated_by);
    $ok = $ok && app_settings_set($conn, $scope, $scope_id, 'require_password_for_approvals', $require_password_for_approvals, 'bool', 'security', $updated_by);
    $ok = $ok && app_settings_set($conn, $scope, $scope_id, 'session_timeout_minutes', (string)$session_timeout_minutes, 'int', 'security', $updated_by);
    $ok = $ok && app_settings_set($conn, $scope, $scope_id, 'notifications_default_recipient_type', $default_recipient_type, 'string', 'notifications', $updated_by);
    $ok = $ok && app_settings_set($conn, $scope, $scope_id, 'notifications_default_channel_email', $default_channel_email, 'bool', 'notifications', $updated_by);
    $ok = $ok && app_settings_set($conn, $scope, $scope_id, 'notifications_default_channel_whatsapp', $default_channel_whatsapp, 'bool', 'notifications', $updated_by);

    if ($ok) {
        $conn->commit();
        log_system_action($conn, 'GLOBAL_SETTINGS_UPDATED', $updated_by, 'Global settings updated in superadmin/global_settings.php');
        $_SESSION['feedback'] = "<div class='success-banner'>Global settings saved successfully.</div>";
    } else {
        $conn->rollback();
        $_SESSION['feedback'] = "<div class='error-banner'>Could not save global settings. Please try again.</div>";
    }

    header('Location: global_settings.php');
    exit();
}

if (isset($_SESSION['feedback'])) {
    $feedback = $_SESSION['feedback'];
    unset($_SESSION['feedback']);
}

$current = app_settings_get_many($conn, 'global', 0, $defaults);
require_once '../includes/header.php';
?>

<div class="main-content page-container">
    <div class="dashboard-header">
        <div>
            <h1>Global Settings</h1>
            <p>Set centralized defaults (scope global, scope_id 0). Role-specific and user-specific settings can override these values.</p>
        </div>
        <a href="dashboard.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <?php if (!empty($feedback)): ?>
        <?php echo $feedback; ?>
    <?php endif; ?>

    <form method="POST" class="settings-form" novalidate>
        <input type="hidden" name="save_global_settings" value="1">

        <div class="settings-grid">
            <section class="settings-card">
                <h3><i class="fas fa-chart-line" aria-hidden="true"></i> Dashboard Defaults</h3>

                <div class="settings-field">
                    <label for="default_date_range">Default Date Range</label>
                    <select id="default_date_range" name="default_date_range">
                        <option value="today" <?php echo ($current['default_date_range'] === 'today') ? 'selected' : ''; ?>>Today</option>
                        <option value="week" <?php echo ($current['default_date_range'] === 'week') ? 'selected' : ''; ?>>This Week</option>
                        <option value="month" <?php echo ($current['default_date_range'] === 'month') ? 'selected' : ''; ?>>This Month</option>
                        <option value="last_month" <?php echo ($current['default_date_range'] === 'last_month') ? 'selected' : ''; ?>>Last Month</option>
                    </select>
                </div>

                <label class="settings-toggle">
                    <input type="checkbox" name="show_pending_bills_card" <?php echo !empty($current['show_pending_bills_card']) ? 'checked' : ''; ?>>
                    <span>Show Pending Bills KPI by default</span>
                </label>

                <label class="settings-toggle">
                    <input type="checkbox" name="show_revenue_chart" <?php echo !empty($current['show_revenue_chart']) ? 'checked' : ''; ?>>
                    <span>Show Revenue chart by default</span>
                </label>

                <label class="settings-toggle">
                    <input type="checkbox" name="pending_bill_reminders" <?php echo !empty($current['pending_bill_reminders']) ? 'checked' : ''; ?>>
                    <span>Enable Pending Bill reminders by default</span>
                </label>

                <label class="settings-toggle">
                    <input type="checkbox" name="request_approval_alerts" <?php echo !empty($current['request_approval_alerts']) ? 'checked' : ''; ?>>
                    <span>Enable Request Approval alerts by default</span>
                </label>

                <label class="settings-toggle">
                    <input type="checkbox" name="require_password_for_approvals" <?php echo !empty($current['require_password_for_approvals']) ? 'checked' : ''; ?>>
                    <span>Require password for approvals by default</span>
                </label>

                <div class="settings-field">
                    <label for="session_timeout_minutes">Default Session Timeout (minutes)</label>
                    <input type="number" id="session_timeout_minutes" name="session_timeout_minutes" min="5" max="480" value="<?php echo (int)$current['session_timeout_minutes']; ?>">
                </div>
            </section>

            <section class="settings-card">
                <h3><i class="fas fa-bell" aria-hidden="true"></i> Notification Defaults</h3>

                <div class="settings-field">
                    <label for="notifications_default_recipient_type">Default Recipient Type</label>
                    <select id="notifications_default_recipient_type" name="notifications_default_recipient_type">
                        <option value="group_patients" <?php echo ($current['notifications_default_recipient_type'] === 'group_patients') ? 'selected' : ''; ?>>All Patients (Bulk)</option>
                        <option value="group_doctors" <?php echo ($current['notifications_default_recipient_type'] === 'group_doctors') ? 'selected' : ''; ?>>All Doctors (Bulk)</option>
                        <option value="individual_doctor" <?php echo ($current['notifications_default_recipient_type'] === 'individual_doctor') ? 'selected' : ''; ?>>Specific Doctor</option>
                        <option value="individual_employee" <?php echo ($current['notifications_default_recipient_type'] === 'individual_employee') ? 'selected' : ''; ?>>Specific Employee</option>
                        <option value="custom" <?php echo ($current['notifications_default_recipient_type'] === 'custom') ? 'selected' : ''; ?>>Custom Email / Phone</option>
                    </select>
                </div>

                <label class="settings-toggle">
                    <input type="checkbox" name="notifications_default_channel_email" <?php echo !empty($current['notifications_default_channel_email']) ? 'checked' : ''; ?>>
                    <span>Enable Email channel by default</span>
                </label>

                <label class="settings-toggle">
                    <input type="checkbox" name="notifications_default_channel_whatsapp" <?php echo !empty($current['notifications_default_channel_whatsapp']) ? 'checked' : ''; ?>>
                    <span>Enable WhatsApp channel by default</span>
                </label>
            </section>
        </div>

        <div class="settings-actions">
            <button type="submit" class="btn-submit">Save Global Defaults</button>
        </div>
    </form>
</div>

<?php require_once '../includes/footer.php'; ?>
