<?php
if (session_status() == PHP_SESSION_NONE) { session_start(); }
// Ensure DB connection is available for site messages
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';

// --- Dynamic Base URL Detection ---
$projectRoot = dirname(__DIR__); 
$docRoot = $_SERVER['DOCUMENT_ROOT']; 
$projectRoot = str_replace('\\', '/', $projectRoot);
$docRoot = str_replace('\\', '/', $docRoot);
$base_url = str_replace($docRoot, '', $projectRoot);
$base_url = rtrim($base_url, '/');

if (!isset($_SESSION['user_id'])) { header("Location: " . $base_url . "/login.php"); exit(); }
$username = htmlspecialchars($_SESSION['username']);
$raw_role = (string)$_SESSION['role'];
$role = htmlspecialchars($raw_role);
$current_page = basename($_SERVER['PHP_SELF']);
$home_link = ($role === 'manager') ? $base_url . '/manager/dashboard.php' : $base_url . '/index.php';
$user_initial = strtoupper(substr($username, 0, 1));
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
<body class="role-<?php echo $role; ?> app-layout">
    <?php
    // --- Display Active Site Messages & Popups ---
    if (isset($conn)) {
        // Safe query for popup support
        $has_pop = function_exists('schema_has_column') && schema_has_column($conn, 'site_messages', 'show_as_popup');

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
    <?php require_once __DIR__ . '/layout/topbar.php'; ?>

    <div class="app-shell">
    <?php require_once __DIR__ . '/layout/sidebar.php'; ?>
    <main class="app-main" id="app-main-content">