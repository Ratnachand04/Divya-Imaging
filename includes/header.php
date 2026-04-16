<?php
if (session_status() == PHP_SESSION_NONE) { session_start(); }
// Ensure DB connection is available for site messages
require_once __DIR__ . '/db_connect.php';

// --- Dynamic Base URL Detection ---
$projectRoot = dirname(__DIR__); 
$docRoot = $_SERVER['DOCUMENT_ROOT']; 
$projectRoot = str_replace('\\', '/', $projectRoot);
$docRoot = str_replace('\\', '/', $docRoot);
$base_url = str_replace($docRoot, '', $projectRoot);
$base_url = rtrim($base_url, '/');

if (!isset($_SESSION['user_id'])) { header("Location: " . $base_url . "/login.php"); exit(); }
$username = htmlspecialchars($_SESSION['username']);
$role = htmlspecialchars($_SESSION['role']);
$current_page = basename($_SERVER['PHP_SELF']);
$home_link = ($role === 'manager') ? $base_url . '/manager/dashboard.php' : $base_url . '/index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpeg" href="<?php echo $base_url; ?>/assets/images/logo.jpg">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : "Diagnostic Center"; ?></title>
    
    <link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/style.css?v=<?php echo time(); ?>">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <?php if ($role === 'writer'): ?>
    <link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/writer.css?v=<?php echo time(); ?>">
    <?php endif; ?>
    <?php if ($role === 'superadmin'): ?>
    <link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin.css?v=<?php echo time(); ?>">
    <?php endif; ?>
    <?php if ($role === 'manager'): ?>
    <link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/manager.css?v=<?php echo time(); ?>">
    <?php endif; ?>
    <?php if ($role === 'accountant'): ?>
    <link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/accountant.css?v=<?php echo time(); ?>">
    <?php endif; ?>
    <?php if ($role === 'receptionist'): ?>
    <link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/receptionist.css?v=<?php echo time(); ?>">
    <?php endif; ?>
    
    <style>
        /* Shared Styles for Messages/Popups */
        .site-message {
            width: 100%;
            padding: 10px 20px;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center; /* Centered text */
            animation: slideDown 0.5s ease;
            font-family: 'Segoe UI', sans-serif;
            font-weight: 500;
            text-align: center;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        .site-message > div {
             display: flex; align-items: center; gap: 15px;
        }
        
        /* Message Types - Line Style */
        .site-message.info { background: #e0f2fe; color: #0369a1; border-top: 3px solid #0284c7; }
        .site-message.warning { background: #fffbeb; color: #92400e; border-top: 3px solid #f59e0b; }
        .site-message.maintenance { background: #fef2f2; color: #991b1b; border-top: 3px solid #ef4444; }
        .site-message.success { background: #f0fdf4; color: #166534; border-top: 3px solid #22c55e; }

        .site-message h4 { margin: 0; font-size: 1em; font-weight: 700; display: inline-block; margin-right: 10px; }
        .site-message p { margin: 0; font-size: 0.95em; display: inline-block; }

        /* Popup Styles */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6); z-index: 9999;
            display: flex; justify-content: center; align-items: center;
            opacity: 0; animation: fadeIn 0.3s forwards;
        }
        .modal-popup {
            background: white; padding: 30px; border-radius: 12px;
            max-width: 500px; width: 90%; text-align: center;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
            transform: scale(0.8); animation: popIn 0.3s forwards;
            position: relative;
        }
        .modal-popup h3 { margin-top: 0; color: #333; font-size: 1.5rem; }
        .modal-popup p { font-size: 1.1rem; color: #555; line-height: 1.6; }
        .modal-popup .btn-close-popup {
            background: #333; color: white; border: none; padding: 10px 25px;
            border-radius: 5px; cursor: pointer; margin-top: 20px; font-size: 1rem;
        }
        .modal-popup .popup-icon { font-size: 3rem; margin-bottom: 15px; }

        @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; }}
        @keyframes fadeIn { to { opacity: 1; } }
        @keyframes popIn { to { transform: scale(1); } }
    </style>
</head>
<body class="role-<?php echo $role; ?>">
    <?php
    // --- Display Active Site Messages & Popups ---
    if (isset($conn)) {
        // Safe query for popup support
        $has_pop = false;
        $cols = @$conn->query("SHOW COLUMNS FROM site_messages LIKE 'show_as_popup'");
        if($cols && $cols->num_rows > 0) $has_pop = true;

        $msg_sql = "SELECT * FROM site_messages WHERE is_active = 1 ORDER BY created_at DESC";
        $msg_result = $conn->query($msg_sql);
        
        $popups = [];
        $banners = [];

        if ($msg_result && $msg_result->num_rows > 0) {
            while ($msg = $msg_result->fetch_assoc()) {
                if ($has_pop && isset($msg['show_as_popup']) && $msg['show_as_popup'] == 1) {
                    $popups[] = $msg;
                } else {
                    $banners[] = $msg;
                }
            }

            // Render Banners
            foreach($banners as $msg) {
                echo "<div class='site-message ".htmlspecialchars($msg['type'])."'>";
                echo "<div><h4><i class='fas fa-bullhorn'></i> ".htmlspecialchars($msg['title'])."</h4>";
                echo "<p>".nl2br(htmlspecialchars($msg['message']))."</p></div>";
                echo "</div>";
            }

            // Render Single Popup (Priority: Maintenance > Warning > Info)
            if (!empty($popups)) {
                 $p = $popups[0]; // Gets the most recent one
                 $icon = 'fa-info-circle'; $color = '#0284c7';
                 if ($p['type'] == 'warning') { $icon = 'fa-exclamation-triangle'; $color='#ca8a04'; }
                 if ($p['type'] == 'maintenance') { $icon = 'fa-tools'; $color='#dc2626'; }
                 if ($p['type'] == 'success') { $icon = 'fa-check-circle'; $color='#16a34a'; }
    
                 $popup_id = 'seen_popup_' . md5($p['id'] . $p['title']);
                 if (!isset($_SESSION[$popup_id])) {
                    $_SESSION[$popup_id] = true; 
                    echo "
                    <div class='modal-overlay' id='sitePopup'>
                        <div class='modal-popup'>
                            <div class='popup-icon' style='color:$color'><i class='fas $icon'></i></div>
                            <h3>" . htmlspecialchars($p['title']) . "</h3>
                            <p>" . nl2br(htmlspecialchars($p['message'])) . "</p>
                            <button class='btn-close-popup' onclick=\"document.getElementById('sitePopup').style.display='none'\">Understood</button>
                        </div>
                    </div>";
                 }
            }
        }
    }
?>
    <header class="main-header">
       <div class="header-container">
            <div class="logo-area">
                <button id="mobile-menu-toggle" aria-label="Toggle navigation">
                    <i class="fas fa-bars"></i>
                </button>
                <a href="<?php echo htmlspecialchars($home_link); ?>"><img src="<?php echo $base_url; ?>/assets/images/logo.jpg" alt="Divya Imaging Center Logo"><span>Divya Imaging Center</span></a>
            </div>
            <div class="user-info-area">
                <?php if ($role === 'superadmin'): ?>
                    <div class="sa-global-header-actions" aria-label="Superadmin quick actions">
                        <div class="sa-global-header-icons">
                        <a href="manage_calendar.php" title="Calendar"><i class="far fa-calendar-alt"></i></a>
                        <a href="notifications.php" title="Notifications"><i class="far fa-bell"></i></a>
                        <a href="global_settings.php" title="Settings"><i class="fas fa-cog"></i></a>
                        </div>
                        <a href="<?php echo $base_url; ?>/logout.php" class="sa-global-logout-btn">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                <?php else: ?>
                    <span>Welcome, <?php echo $username; ?> (<?php echo ucfirst($role); ?>)</span>
                    <a href="<?php echo $base_url; ?>/logout.php" class="btn-logout">Logout</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <nav class="main-navbar" id="main-nav">
        <ul>
            <?php if ($role === 'superadmin'): ?>
                <li><a href="dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
                <li><a href="scans.php" class="<?php echo ($current_page == 'scans.php') ? 'active' : ''; ?>"><i class="fas fa-x-ray"></i><span>Scans</span></a></li>
                <li><a href="view_doctors.php" class="<?php echo in_array($current_page, ['view_doctors.php', 'view_doctor_details.php']) ? 'active' : ''; ?>"><i class="fas fa-user-md"></i><span>Doctors</span></a></li>
                <li><a href="test_count.php" class="<?php echo in_array($current_page, ['test_count.php', 'radiology_details.php']) ? 'active' : ''; ?>"><i class="fas fa-radiation"></i><span>Radiology</span></a></li>
                <li><a href="expenditure.php" class="<?php echo ($current_page == 'expenditure.php') ? 'active' : ''; ?>"><i class="fas fa-wallet"></i><span>Financials</span></a></li>
                <li><a href="patients.php" class="<?php echo in_array($current_page, ['patients.php', 'patient_details.php']) ? 'active' : ''; ?>"><i class="fas fa-procedures"></i><span>Patients</span></a></li>
                <li><a href="employees.php" class="<?php echo in_array($current_page, ['employees.php', 'edit_employee.php', 'delete_employee.php']) ? 'active' : ''; ?>"><i class="fas fa-users"></i><span>Employee</span></a></li>
                <li class="sa-settings-link"><a href="global_settings.php" class="<?php echo ($current_page == 'global_settings.php') ? 'active' : ''; ?>"><i class="fas fa-cogs"></i><span>Settings</span></a></li>
            <?php elseif ($role === 'manager'): ?>
                <li><a href="dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
                <li><a href="analytics.php" class="<?php echo ($current_page == 'analytics.php') ? 'active' : ''; ?>"><i class="fas fa-chart-pie"></i><span>Analytics</span></a></li>
                <li><a href="manage_tests.php" class="<?php echo ($current_page == 'manage_tests.php') ? 'active' : ''; ?>"><i class="fas fa-vials"></i><span>Tests</span></a></li>
                <li><a href="manage_doctors.php" class="<?php echo in_array($current_page, ['manage_doctors.php', 'manage_doctor_commissions.php']) ? 'active' : ''; ?>"><i class="fas fa-user-md"></i><span>Doctors</span></a></li>
                <li><a href="expenses.php" class="<?php echo ($current_page == 'expenses.php') ? 'active' : ''; ?>"><i class="fas fa-wallet"></i><span>Expenses</span></a></li>
                <li><a href="requests.php" class="<?php echo ($current_page == 'requests.php') ? 'active' : ''; ?>"><i class="fas fa-inbox"></i><span>Requests</span><span class="nav-badge is-hidden" data-nav-count="requests">0</span></a></li>
                <li><a href="manage_employees.php" class="<?php echo ($current_page == 'manage_employees.php') ? 'active' : ''; ?>"><i class="fas fa-users"></i><span>Employees</span></a></li>
                <li><a href="<?php echo $base_url; ?>/manager/view_due_bills.php" class="<?php echo ($current_page == 'view_due_bills.php') ? 'active' : ''; ?>"><i class="fas fa-file-invoice-dollar"></i><span>Pending Bills</span><span class="nav-badge is-hidden" data-nav-count="pending-bills">0</span></a></li>
                <li><a href="print_reports.php" class="<?php echo ($current_page == 'print_reports.php') ? 'active' : ''; ?>"><i class="fas fa-print"></i><span>Print Reports</span><span class="nav-badge is-hidden" data-nav-count="pending-reports">0</span></a></li>
                <li><a href="reporting_doctors.php" class="<?php echo ($current_page == 'reporting_doctors.php') ? 'active' : ''; ?>"><i class="fas fa-user-md"></i><span>Reporting Doctors</span></a></li>

            <?php elseif ($role === 'accountant'): ?>
                <li><a href="dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
                <li><a href="manage_payments.php" class="<?php echo ($current_page == 'manage_payments.php') ? 'active' : ''; ?>"><i class="fas fa-credit-card"></i><span>Payments</span></a></li>
                <li><a href="doctor_payouts.php" class="<?php echo ($current_page == 'doctor_payouts.php') ? 'active' : ''; ?>"><i class="fas fa-hand-holding-usd"></i><span>Professional Charges</span></a></li>
                <li><a href="view_expenses.php" class="<?php echo in_array($current_page, ['view_expenses.php', 'log_expense.php']) ? 'active' : ''; ?>"><i class="fas fa-receipt"></i><span>Expenses</span></a></li>
                <li><a href="discount_report.php" class="<?php echo ($current_page == 'discount_report.php') ? 'active' : ''; ?>"><i class="fas fa-tag"></i><span>Discounts</span></a></li>
            <?php elseif ($role === 'writer'): ?>
                <li><a href="dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
                <li><a href="write_reports.php" class="<?php echo ($current_page == 'write_reports.php') ? 'active' : ''; ?>"><i class="fas fa-pen-nib"></i><span>Write Report</span></a></li>
                <li><a href="view_reports.php" class="<?php echo ($current_page == 'view_reports.php') ? 'active' : ''; ?>"><i class="fas fa-file-medical-alt"></i><span>Uploaded</span></a></li>
                <li><a href="templates.php" class="<?php echo ($current_page == 'templates.php') ? 'active' : ''; ?>"><i class="fas fa-layer-group"></i><span>Templates</span></a></li>
            <?php elseif ($role === 'receptionist'): ?>
                <li><a href="dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
                <li><a href="generate_bill.php" class="<?php echo in_array($current_page, ['generate_bill.php', 'edit_bill.php']) ? 'active' : ''; ?>"><i class="fas fa-file-medical"></i><span>Generate Bill</span></a></li>
                <li><a href="existing_patients.php" class="<?php echo in_array($current_page, ['existing_patients.php', 'edit_patient.php']) ? 'active' : ''; ?>"><i class="fas fa-users"></i><span>Patients</span></a></li>
                <li><a href="bill_history.php" class="<?php echo ($current_page == 'bill_history.php') ? 'active' : ''; ?>"><i class="fas fa-history"></i><span>Bill History</span></a></li>
            <?php endif; ?>
        </ul>
    </nav>