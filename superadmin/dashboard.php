<?php
$page_title = "Superadmin Dashboard";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

if (!function_exists('summarize_change_label')) {
    function summarize_change_label($action_type, $details) {
        $text = strtolower(trim(($action_type ?? '') . ' ' . ($details ?? '')));
        $map = [
            'password' => 'Password Change',
            'new user' => 'New User Created',
            'create user' => 'User Created',
            'role' => 'Role Updated',
            'bills pending' => 'Bills Pending',
            'payment due' => 'Payment Due',
            'payment reminder' => 'Payment Reminder'
        ];

        foreach ($map as $needle => $label) {
            if (strpos($text, $needle) !== false) {
                return $label;
            }
        }

        if (strpos($text, 'user') !== false && strpos($text, 'create') !== false) {
            return 'User Created';
        }

        if (strpos($text, 'role') !== false && (strpos($text, 'create') !== false || strpos($text, 'assign') !== false || strpos($text, 'update') !== false)) {
            return 'Role Updated';
        }

        if (strpos($text, 'bill') !== false && strpos($text, 'pending') !== false) {
            return 'Bills Pending';
        }

        if (strpos($text, 'payment') !== false && strpos($text, 'due') !== false) {
            return 'Payment Due';
        }

        $fallback = ucwords(strtolower(str_replace('_', ' ', $action_type ?? 'No Changes')));
        return $fallback ?: 'No Changes';
    }
}

// --- Data Fetching for Tiles ---

// 1. Test Count
// Total Tests Performed (All time)
$sql_total_tests = "SELECT COUNT(*) as count FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE bi.item_status = 0 AND b.bill_status != 'Void'";
$total_tests = 0;
if ($res = $conn->query($sql_total_tests)) {
    $total_tests = $res->fetch_assoc()['count'];
}

// Today's Tests & Revenue
$sql_today_stats = "SELECT COUNT(bi.id) as test_count, SUM(b.net_amount) as revenue FROM bills b LEFT JOIN bill_items bi ON b.id = bi.bill_id AND bi.item_status = 0 WHERE b.bill_status != 'Void' AND DATE(b.created_at) = CURDATE()";
$today_tests = 0;
$today_revenue = 0;
if ($res = $conn->query($sql_today_stats)) {
    $row = $res->fetch_assoc();
    $today_tests = $row['test_count'];
    $today_revenue = $row['revenue'] ?? 0;
}

// Monthly Tests
$sql_month_tests = "SELECT COUNT(*) as count FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE bi.item_status = 0 AND b.bill_status != 'Void' AND MONTH(b.created_at) = MONTH(CURDATE()) AND YEAR(b.created_at) = YEAR(CURDATE())";
$month_tests = 0;
if ($res = $conn->query($sql_month_tests)) {
    $month_tests = $res->fetch_assoc()['count'];
}

// 2. Doctor-Wise Count
// Total Doctors
$sql_doctors = "SELECT COUNT(*) as count FROM referral_doctors";
$total_doctors = 0;
if ($res = $conn->query($sql_doctors)) {
    $total_doctors = $res->fetch_assoc()['count'];
}

// Active Doctors (Master List)
$sql_active_master = "SELECT COUNT(*) as count FROM referral_doctors WHERE is_active = 1";
$active_doctors_master = 0;
if ($res = $conn->query($sql_active_master)) {
    $active_doctors_master = $res->fetch_assoc()['count'];
}

// Active Doctors (This Month)
$sql_active_docs = "SELECT COUNT(DISTINCT referral_doctor_id) as count FROM bills WHERE referral_type='Doctor' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
$active_doctors_month = 0;
if ($res = $conn->query($sql_active_docs)) {
    $active_doctors_month = $res->fetch_assoc()['count'];
}

// Total Tests Referred (by doctors)
$sql_doc_tests = "SELECT COUNT(*) as count FROM bills WHERE referral_type = 'Doctor' AND bill_status != 'Void'";
$total_doc_tests = 0;
if ($res = $conn->query($sql_doc_tests)) {
    $total_doc_tests = $res->fetch_assoc()['count'];
}

// Active Doctors Today
$sql_active_docs_today = "SELECT COUNT(DISTINCT referral_doctor_id) as count FROM bills WHERE referral_type='Doctor' AND bill_status != 'Void' AND DATE(created_at) = CURDATE()";
$active_doctors_today = 0;
if ($res = $conn->query($sql_active_docs_today)) {
    $active_doctors_today = $res->fetch_assoc()['count'];
}

// Tests linked to doctors today
$sql_tests_today_doctors = "SELECT COUNT(bi.id) as count FROM bills b JOIN bill_items bi ON b.id = bi.bill_id AND bi.item_status = 0 WHERE b.bill_status != 'Void' AND b.referral_type = 'Doctor' AND DATE(b.created_at) = CURDATE()";
$tests_today_doctors = 0;
if ($res = $conn->query($sql_tests_today_doctors)) {
    $tests_today_doctors = $res->fetch_assoc()['count'];
}

// Doctor revenue today
$sql_today_doctor_revenue = "SELECT SUM(net_amount) as total FROM bills WHERE referral_type = 'Doctor' AND bill_status != 'Void' AND DATE(created_at) = CURDATE()";
$today_doctor_revenue = 0;
if ($res = $conn->query($sql_today_doctor_revenue)) {
    $today_doctor_revenue = $res->fetch_assoc()['total'] ?? 0;
}

$tests_doctor_ratio = $active_doctors_today > 0 ? ($tests_today_doctors / $active_doctors_today) : 0;
$avg_revenue_per_doctor = $active_doctors_today > 0 ? ($today_doctor_revenue / $active_doctors_today) : 0;

// 3. Audit Log
// Active Users (Total users)
$sql_users = "SELECT COUNT(*) as count FROM users";
$total_users = 0;
if ($res = $conn->query($sql_users)) {
    $total_users = $res->fetch_assoc()['count'];
}

// Actions Today
$sql_actions_today = "SELECT COUNT(*) as count FROM system_audit_log WHERE DATE(logged_at) = CURDATE()";
$actions_today = 0;
if ($res = $conn->query($sql_actions_today)) {
    $actions_today = $res->fetch_assoc()['count'];
}

// Bill edits logged today by receptionists
$sql_bill_edits_today = "SELECT COUNT(*) as count FROM bill_edit_log l JOIN users u ON l.editor_id = u.id WHERE u.role = 'receptionist' AND DATE(l.changed_at) = CURDATE()";
$bill_edits_today = 0;
if ($res = $conn->query($sql_bill_edits_today)) {
    $bill_edits_today = $res->fetch_assoc()['count'];
}

// Latest notable change today
$latest_change_summary = 'No changes today';
$sql_latest_change = "SELECT action_type, details FROM system_audit_log WHERE DATE(logged_at) = CURDATE() ORDER BY logged_at DESC LIMIT 1";
if ($res = $conn->query($sql_latest_change)) {
    if ($row = $res->fetch_assoc()) {
        $latest_change_summary = summarize_change_label($row['action_type'], $row['details']);
    }
}

$top_doc_period_label = date('F Y');
$sql_top_doc = "
    SELECT
        rd.id as doc_id,
        rd.doctor_name,
        SUM(b.net_amount) AS total_revenue,
        COUNT(b.id) AS total_bills,
        COUNT(DISTINCT b.patient_id) AS total_patients,
        COUNT(bi.id) AS total_tests
    FROM bills b
    JOIN referral_doctors rd ON b.referral_doctor_id = rd.id
    LEFT JOIN bill_items bi ON bi.bill_id = b.id AND bi.item_status = 0
    WHERE
        b.referral_type = 'Doctor'
        AND b.bill_status != 'Void'
        AND MONTH(b.created_at) = MONTH(CURDATE())
        AND YEAR(b.created_at) = YEAR(CURDATE())
    GROUP BY rd.id
    ORDER BY total_revenue DESC, total_bills DESC
    LIMIT 1";
$top_doc_id = "---";
$top_doc_name = "No Doctor";
$top_doc_revenue = 0;
$top_doc_tests_ratio = 0;
if ($res = $conn->query($sql_top_doc)) {
    if ($row = $res->fetch_assoc()) {
        $top_doc_id = $row['doc_id'] ?? '---';
        $top_doc_name = $row['doctor_name'];
        $top_doc_revenue = $row['total_revenue'] ?? 0;
        $patient_count = (int)($row['total_patients'] ?? 0);
        $tests_count = (int)($row['total_tests'] ?? 0);
        $top_doc_tests_ratio = $patient_count > 0 ? ($tests_count / $patient_count) : 0;
    }
}

// 6. Employees Breakdown
$sql_roles = "SELECT role, COUNT(*) as count FROM users WHERE is_active = 1 GROUP BY role";
$roles_count = ['receptionist' => 0, 'accountant' => 0, 'manager' => 0, 'superadmin' => 0, 'writer' => 0];
if ($res = $conn->query($sql_roles)) {
    while($row = $res->fetch_assoc()) {
        $roleKey = strtolower($row['role']);
        if (!array_key_exists($roleKey, $roles_count)) {
            $roles_count[$roleKey] = 0;
        }
        $roles_count[$roleKey] = (int)$row['count'];
    }
}
$total_employees = array_sum($roles_count);
$role_labels = [
    'receptionist' => 'Receptionists',
    'accountant' => 'Accountants',
    'manager' => 'Managers',
    'superadmin' => 'Superadmins',
    'writer' => 'Writers'
];

// 7. Notifications (Broadcasts)
$queued_broadcasts = 0;
$total_broadcasts = 0;
$check_table = $conn->query("SHOW TABLES LIKE 'notification_queue'");
if ($check_table && $check_table->num_rows > 0) {
    // Queued
    if ($res = $conn->query("SELECT COUNT(*) as count FROM notification_queue WHERE status = 'Queued'")) {
        $queued_broadcasts = $res->fetch_assoc()['count'];
    }
    // Total
    if ($res = $conn->query("SELECT COUNT(*) as count FROM notification_queue")) {
        $total_broadcasts = $res->fetch_assoc()['count'];
    }
}

// 8. Expenditure
// Total Expenditure
$sql_expenses = "SELECT SUM(amount) as total FROM expenses";
$total_expenditure = 0;
if ($res = $conn->query($sql_expenses)) {
    $total_expenditure = $res->fetch_assoc()['total'] ?? 0;
}
// Month Expenditure (till date)
$sql_month_exp = "SELECT SUM(amount) as total FROM expenses WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
$month_expenditure = 0;
if ($res = $conn->query($sql_month_exp)) {
    $month_expenditure = $res->fetch_assoc()['total'] ?? 0;
}
// Today Expenditure
$sql_today_exp = "SELECT SUM(amount) as total FROM expenses WHERE DATE(created_at) = CURDATE()";
$today_expenditure = 0;
if ($res = $conn->query($sql_today_exp)) {
    $today_expenditure = $res->fetch_assoc()['total'] ?? 0;
}
// Most spent category for current month
$sql_most_spent = "SELECT expense_type, SUM(amount) as total FROM expenses WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) GROUP BY expense_type ORDER BY total DESC LIMIT 1";
$most_spent_category = 'N/A';
$most_spent_amount = 0;
if ($res = $conn->query($sql_most_spent)) {
    if ($row = $res->fetch_assoc()) {
        $most_spent_category = $row['expense_type'] ?: 'N/A';
        $most_spent_amount = $row['total'] ?? 0;
    }
}

// 9. Monthly Analysis
// Total Revenue (All time)
$sql_total_revenue = "SELECT SUM(net_amount) as total FROM bills WHERE bill_status != 'Void'";
$total_revenue_all = 0;
if ($res = $conn->query($sql_total_revenue)) {
    $total_revenue_all = $res->fetch_assoc()['total'] ?? 0;
}

// Current Month Revenue
$sql_month_revenue = "SELECT SUM(net_amount) as total FROM bills WHERE bill_status != 'Void' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
$month_revenue = 0;
if ($res = $conn->query($sql_month_revenue)) {
    $month_revenue = $res->fetch_assoc()['total'] ?? 0;
}

// Last Month Revenue for Growth
$sql_last_month = "SELECT SUM(net_amount) as total FROM bills WHERE bill_status != 'Void' AND MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH)";
$last_month_rev = 0;
if ($res = $conn->query($sql_last_month)) {
    $last_month_rev = $res->fetch_assoc()['total'] ?? 0;
}
$sql_last_month_tests = "SELECT COUNT(*) as count FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE bi.item_status = 0 AND b.bill_status != 'Void' AND MONTH(b.created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(b.created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH)";
$last_month_tests = 0;
if ($res = $conn->query($sql_last_month_tests)) {
    $last_month_tests = $res->fetch_assoc()['count'];
}
$revenue_growth_amount = $month_revenue - $last_month_rev;

// 5. Deep Analysis (Revenue vs Tests)
$month_avg_rev_per_test = $month_tests > 0 ? ($month_revenue / $month_tests) : 0;
$last_month_avg_rev_per_test = $last_month_tests > 0 ? ($last_month_rev / $last_month_tests) : 0;

// Monthly discount breakdown (Doctor vs Center)
$sql_month_discount_breakdown = "SELECT 
    SUM(CASE WHEN discount_by = 'Doctor' THEN COALESCE(discount, 0) ELSE 0 END) AS doctor_discount,
    SUM(CASE WHEN discount_by != 'Doctor' THEN COALESCE(discount, 0) ELSE 0 END) AS center_discount
FROM bills
WHERE bill_status != 'Void'
  AND MONTH(created_at) = MONTH(CURDATE())
  AND YEAR(created_at) = YEAR(CURDATE())";
$month_discount_doctor = 0;
$month_discount_center = 0;
if ($res = $conn->query($sql_month_discount_breakdown)) {
    if ($row = $res->fetch_assoc()) {
        $month_discount_doctor = $row['doctor_discount'] ?? 0;
        $month_discount_center = $row['center_discount'] ?? 0;
    }
}
$total_month_discounts = ($month_discount_doctor + $month_discount_center);

$revenue_trend_class = $revenue_growth_amount >= 0 ? 'revenue-positive' : 'revenue-negative';
$revenue_class_attr = 'class="revenue-figure ' . $revenue_trend_class . '"';
$revenue_growth_display = ($revenue_growth_amount >= 0 ? '+' : '-') . '₹' . number_format(abs($revenue_growth_amount), 2);

?>

<!-- Fonts & Icons -->
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<!-- Tailwind CDN -->
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script>
tailwind.config = {
    darkMode: "class",
    theme: {
        extend: {
            colors: {
                "primary": "#e91e63",
                "on-primary": "#ffffff",
                "secondary": "#5c47e5",
                "tertiary": "#10b981",
                "surface-container-lowest": "#ffffff",
                "surface-bright": "#fdf4f7",
                "background": "#fdf4f7",
                "outline": "#70787d",
                "surface-container": "#fce4ec",
                "on-surface": "#191c1e",
                "on-surface-variant": "#40484c"
            },
            fontFamily: {
                "headline": ["Manrope"],
                "body": ["Inter"],
                "label": ["Inter"]
            },
            borderRadius: {"DEFAULT": "0.125rem", "lg": "0.25rem", "xl": "0.5rem", "full": "0.75rem"},
        },
    },
};
</script>
<!-- Dashboard CSS -->
<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_dashboard.css?v=<?php echo time(); ?>">

<?php
// --- Helper: format large INR amounts ---
if (!function_exists('format_inr_short')) {
    function format_inr_short($amount) {
        $amt = (float)$amount;
        if ($amt >= 100000) return '₹' . number_format($amt / 100000, 1) . 'L';
        if ($amt >= 1000) return '₹' . number_format($amt / 1000, 0) . 'k';
        return '₹' . number_format($amt);
    }
}

$days_in_month_so_far = max(1, (int)date('j'));
$month_daily_avg = $month_revenue / $days_in_month_so_far;
$last_month_days = max(1, (int)date('t', strtotime('last month')));
$last_month_daily_avg = $last_month_rev / $last_month_days;

// Growth badge
$growth_badge_label = 'STABLE';
if ($revenue_growth_amount > 0) $growth_badge_label = 'UP';
if ($revenue_growth_amount < 0) $growth_badge_label = 'DOWN';

// Short role labels
$role_short_labels = [
    'receptionist' => 'Receptionists',
    'accountant'   => 'Accountants',
    'manager'      => 'Managers',
    'superadmin'   => 'Superadmins',
    'writer'       => 'Writers'
];
?>

<div class="bg-surface-bright font-body text-on-surface min-h-screen relative w-full overflow-x-hidden pt-16 md:pl-64">

<!-- Top Navigation Shell -->
<header class="bg-white/90 backdrop-blur-xl flex justify-between items-center px-4 md:px-8 h-16 w-full fixed top-0 left-0 z-50 border-b border-primary/10">
    <div class="flex items-center gap-8">
        <span class="text-2xl font-black tracking-tighter text-primary">Divya Imaging</span>
        <nav class="hidden md:flex items-center gap-6 font-['Manrope'] font-bold text-sm tracking-tight">
            <a class="text-primary border-b-2 border-primary pb-1" href="dashboard.php">Analytics</a>
            <a class="text-slate-500 font-medium hover:text-primary transition-colors" href="test_count.php">Diagnostics</a>
            <a class="text-slate-500 font-medium hover:text-primary transition-colors" href="lists.php">Patients</a>
            <a class="text-slate-500 font-medium hover:text-primary transition-colors" href="expenditure.php">Inventory</a>
        </nav>
    </div>
    <div class="flex items-center gap-2 md:gap-4">
        <a href="notifications.php" class="p-2 text-slate-500 hover:bg-primary/10 rounded-full transition-all flex">
            <span class="material-symbols-outlined">notifications</span>
        </a>
        <a href="employees.php" class="p-2 text-slate-500 hover:bg-primary/10 rounded-full transition-all hidden sm:flex">
            <span class="material-symbols-outlined">settings</span>
        </a>
        <div class="flex items-center gap-2 md:gap-3 ml-1 md:ml-2 pl-2 md:pl-4 border-l border-slate-200">
            <div class="text-right hidden sm:block">
                <p class="text-xs font-bold text-primary"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Superadmin'); ?></p>
                <p class="text-[10px] text-slate-400 uppercase tracking-widest">Master Control</p>
            </div>
            <img alt="User profile avatar" class="w-8 h-8 md:w-10 md:h-10 rounded-full object-cover ring-2 ring-primary/20" src="<?php echo $base_url; ?>/assets/images/logo.jpg"/>
            <a href="<?php echo $base_url; ?>/logout.php" class="ml-1 md:ml-2 px-2 py-1 md:px-3 bg-primary text-white text-[10px] font-bold uppercase rounded-md hover:bg-pink-600 transition-colors">Logout</a>
        </div>
    </div>
</header>

<!-- Sidebar Shell -->
<aside class="h-screen w-64 fixed left-0 top-0 flex flex-col bg-white border-r border-slate-100 shadow-xl z-40 hidden md:flex pt-16">
    <div class="p-8 pb-4 border-b border-slate-100">
        <h2 class="font-['Manrope'] font-extrabold text-primary text-xl">Divya Imaging</h2>
        <p class="text-xs font-semibold text-slate-400">Clinical Precision</p>
    </div>
    <nav class="flex flex-col py-6 gap-2 flex-grow font-['Inter'] text-sm font-semibold overflow-y-auto">
        <a class="bg-primary text-white rounded-r-full mr-4 px-6 py-3 flex items-center gap-3" href="dashboard.php">
            <span class="material-symbols-outlined">dashboard</span> Dashboard
        </a>
        <a class="text-slate-600 mx-4 px-6 py-3 flex items-center gap-3 hover:bg-primary/5 hover:text-primary rounded-full transition-all" href="test_count.php">
            <span class="material-symbols-outlined">biotech</span> MRI scans
        </a>
        <a class="text-slate-600 mx-4 px-6 py-3 flex items-center gap-3 hover:bg-primary/5 hover:text-primary rounded-full transition-all" href="lists.php">
            <span class="material-symbols-outlined">query_stats</span> CT Pathology
        </a>
        <a class="text-slate-600 mx-4 px-6 py-3 flex items-center gap-3 hover:bg-primary/5 hover:text-primary rounded-full transition-all" href="view_doctors.php">
            <span class="material-symbols-outlined">settings_accessibility</span> Radiology
        </a>
        <a class="text-slate-600 mx-4 px-6 py-3 flex items-center gap-3 hover:bg-primary/5 hover:text-primary rounded-full transition-all" href="detailed_report.php">
            <span class="material-symbols-outlined">description</span> Lab Reports
        </a>
        <a class="text-slate-600 mx-4 px-6 py-3 flex items-center gap-3 hover:bg-primary/5 hover:text-primary rounded-full transition-all" href="expenditure.php">
            <span class="material-symbols-outlined">payments</span> Financials
        </a>
    </nav>
    <div class="p-4 border-t border-slate-100 font-['Inter'] text-sm font-semibold">
        <a class="text-slate-600 mx-4 px-6 py-3 flex items-center gap-3 hover:bg-primary/5 hover:text-primary rounded-full transition-all" href="manage_calendar.php">
            <span class="material-symbols-outlined">cloud_done</span> System Status
        </a>
        <a class="text-slate-600 mx-4 px-6 py-3 flex items-center gap-3 hover:bg-primary/5 hover:text-primary rounded-full transition-all" href="notifications.php">
            <span class="material-symbols-outlined">help_outline</span> Help
        </a>
    </div>
</aside>

<!-- Main Content -->
<main class="ml-0 md:ml-64 p-4 md:p-8 min-h-screen pt-20">
    <!-- Header Metrics -->
    <div class="flex justify-between items-end mb-8">
        <div>
            <h1 class="font-headline text-2xl md:text-3xl font-extrabold text-slate-800 mb-1">Clinic Performance</h1>
            <p class="font-body text-xs md:text-sm text-slate-500 font-medium">Real-time clinical and operational telemetry</p>
        </div>
        <div class="text-right">
            <p class="font-headline text-sm md:text-lg font-bold text-primary uppercase tracking-tighter" id="sa-dash-date"></p>
            <p class="font-body text-[10px] md:text-xs font-semibold text-slate-400" id="sa-dash-time"></p>
        </div>
    </div>

    <!-- Bento Grid Layout -->
    <div class="bento-grid">
        <!-- 1. Test Count Card -->
        <a href="test_count.php" class="col-span-12 md:col-span-4 rounded-xl p-6 bg-gradient-to-br from-[#00bcd4] to-[#0097a7] text-white shadow-lg overflow-hidden group block">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <p class="font-label text-[10px] uppercase font-bold tracking-widest opacity-80">Test Volume</p>
                    <h3 class="font-headline text-5xl font-black"><?php echo number_format($today_tests); ?></h3>
                </div>
                <span class="material-symbols-outlined text-4xl opacity-30">biotech</span>
            </div>
            <div class="flex flex-col gap-1">
                <p class="text-sm font-bold">Tests Today</p>
                <div class="flex justify-between items-end mt-4">
                    <p class="text-[10px] opacity-70 font-bold uppercase">This Month: <?php echo number_format($month_tests); ?></p>
                    <p class="text-lg font-black">₹<?php echo number_format($today_revenue); ?> <span class="text-[10px] font-normal opacity-70">Today</span></p>
                </div>
            </div>
        </a>

        <!-- 2. Doctors Card -->
        <a href="doctor_wise_count.php" class="col-span-12 md:col-span-4 rounded-xl p-6 bg-white shadow-sm border border-slate-100 flex flex-col justify-between group block hover:border-primary/30 transition-colors">
            <div class="flex justify-between">
                <div>
                    <p class="text-[10px] uppercase font-bold text-slate-400 tracking-widest mb-1">Clinical Staff</p>
                    <h3 class="font-headline text-4xl font-black text-slate-800 group-hover:text-primary transition-colors"><?php echo number_format($active_doctors_today); ?></h3>
                    <p class="text-xs font-medium text-slate-500 mt-1">Active Doctors Today</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-primary/10 flex items-center justify-center group-hover:bg-primary transition-colors">
                    <span class="material-symbols-outlined text-primary group-hover:text-white transition-colors">medical_services</span>
                </div>
            </div>
            <div class="mt-6 grid grid-cols-2 gap-4">
                <div class="p-3 bg-slate-50 rounded-lg group-hover:bg-primary/5 transition-colors">
                    <p class="text-[9px] uppercase text-slate-400 font-bold">Tests / Doc</p>
                    <p class="text-lg font-black text-slate-700"><?php echo number_format($tests_doctor_ratio, 1); ?></p>
                </div>
                <div class="p-3 bg-slate-50 rounded-lg group-hover:bg-primary/5 transition-colors">
                    <p class="text-[9px] uppercase text-slate-400 font-bold">Avg Rev</p>
                    <p class="text-lg font-black text-slate-700">₹<?php echo number_format($avg_revenue_per_doctor); ?></p>
                </div>
            </div>
        </a>

        <!-- 3. Audit Log Card -->
        <a href="audit_log.php" class="col-span-12 md:col-span-4 rounded-xl p-6 bg-[#2c3e50] text-white shadow-lg flex flex-col justify-between block hover:scale-[1.02] transition-transform">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-[10px] uppercase font-bold opacity-60 tracking-widest">Audit Log</p>
                    <h3 class="text-4xl font-black"><?php echo number_format($bill_edits_today); ?></h3>
                    <p class="text-xs font-medium opacity-80">Bill Edits Today</p>
                </div>
                <span class="material-symbols-outlined text-3xl opacity-40">history</span>
            </div>
            <div class="mt-6 p-3 bg-white/5 rounded-lg border border-white/10 italic text-[11px] opacity-70">
                "<?php echo htmlspecialchars($latest_change_summary); ?>"
            </div>
        </a>

        <!-- 4. Top Doctor -->
        <a href="compare.php" class="col-span-12 md:col-span-5 rounded-xl p-8 bg-secondary text-white shadow-2xl relative overflow-hidden group block">
            <div class="flex justify-between items-center mb-10 relative z-10">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest opacity-70">Elite Performance</p>
                    <h2 class="font-headline text-2xl font-black">Top Physician <span class="text-primary"><?php echo date('F Y'); ?></span></h2>
                </div>
                <span class="material-symbols-outlined text-white/40 text-4xl">workspace_premium</span>
            </div>
            <div class="flex items-center gap-8 relative z-10">
                <div class="w-20 h-20 md:w-24 md:h-24 rounded-2xl bg-white/10 flex items-center justify-center border-2 border-dashed border-white/20 shrink-0">
                    <span class="material-symbols-outlined text-5xl opacity-40">person</span>
                </div>
                <div class="flex-grow">
                    <div class="flex flex-col xl:flex-row justify-between xl:items-start gap-1">
                        <h3 class="text-xl md:text-2xl font-black truncate max-w-[200px]" title="<?php echo htmlspecialchars($top_doc_name); ?>"><?php echo htmlspecialchars($top_doc_name); ?></h3>
                        <span class="text-[10px] bg-white/10 px-2 py-0.5 rounded font-bold self-start mt-1 xl:mt-0 whitespace-nowrap">ID: <?php echo htmlspecialchars($top_doc_id); ?></span>
                    </div>
                    <div class="grid grid-cols-2 gap-4 mt-6 pt-6 border-t border-white/10">
                        <div>
                            <p class="text-[9px] font-bold opacity-60 uppercase">Revenue</p>
                            <p class="text-lg md:text-xl font-bold">₹<?php echo number_format($top_doc_revenue); ?></p>
                        </div>
                        <div>
                            <p class="text-[9px] font-bold opacity-60 uppercase">Tests / Pat</p>
                            <p class="text-lg md:text-xl font-bold"><?php echo number_format($top_doc_tests_ratio, 2); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <span class="material-symbols-outlined absolute -bottom-8 -right-8 text-9xl opacity-10 pointer-events-none group-hover:scale-110 transition-transform duration-700">scale</span>
        </a>

        <!-- 5. Deep Analysis -->
        <a href="deep_analysis.php" class="col-span-12 md:col-span-7 rounded-xl p-8 bg-[#95a5a6] text-white shadow-lg flex flex-col justify-between relative overflow-hidden group block">
            <div class="flex justify-between items-start mb-12 relative z-10">
                <div>
                    <h2 class="font-headline text-2xl font-black">Deep Financial Analysis</h2>
                    <p class="text-sm font-medium opacity-80">Comparative performance vs. previous cycle</p>
                </div>
                <span class="material-symbols-outlined text-white/30 text-4xl">query_stats</span>
            </div>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 relative z-10">
                <div class="p-4 bg-black/5 rounded-lg border-l-4 border-white">
                    <p class="text-[9px] uppercase font-bold opacity-70">Revenue (Month)</p>
                    <p class="text-2xl font-black leading-tight"><?php echo format_inr_short($month_revenue); ?></p>
                </div>
                <div class="p-4 bg-black/5 rounded-lg border-l-4 border-white">
                    <p class="text-[9px] uppercase font-bold opacity-70">Daily Avg</p>
                    <p class="text-2xl font-black leading-tight"><?php echo format_inr_short($month_daily_avg); ?></p>
                </div>
                <div class="p-4 bg-black/5 rounded-lg border-l-4 border-white/30">
                    <p class="text-[9px] uppercase font-bold opacity-50">Last Month</p>
                    <p class="text-2xl font-black opacity-70 leading-tight"><?php echo format_inr_short($last_month_rev); ?></p>
                </div>
                <div class="p-4 bg-black/5 rounded-lg border-l-4 border-white/30">
                    <p class="text-[9px] uppercase font-bold opacity-50">Last Avg</p>
                    <p class="text-2xl font-black opacity-70 leading-tight"><?php echo format_inr_short($last_month_daily_avg); ?></p>
                </div>
            </div>
            <div class="absolute -bottom-10 -right-10 w-40 h-40 bg-white/5 rounded-full blur-2xl pointer-events-none"></div>
        </a>

        <!-- 6. Expenditure -->
        <a href="expenditure.php" class="col-span-12 md:col-span-4 rounded-xl p-6 bg-gradient-to-br from-[#0091ea] to-[#00b0ff] text-white shadow-xl flex flex-col justify-between relative overflow-hidden block">
            <div class="relative z-10">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="font-headline text-lg font-black uppercase tracking-tight">Expenditure</h3>
                    <span class="material-symbols-outlined opacity-40">account_balance_wallet</span>
                </div>
                <p class="text-[10px] font-bold opacity-70 uppercase tracking-widest mb-1">Total Expenditure</p>
                <h3 class="text-5xl font-black mb-8">₹<?php echo number_format($month_expenditure); ?></h3>
            </div>
            <div class="space-y-3 relative z-10">
                <div class="flex justify-between text-xs py-2 border-b border-white/20">
                    <span class="opacity-70">Today</span>
                    <span class="font-bold">₹<?php echo number_format($today_expenditure); ?></span>
                </div>
                <div class="flex justify-between text-xs py-2 border-b border-white/20">
                    <span class="opacity-70">Most Spent</span>
                    <span class="font-bold">₹<?php echo number_format($most_spent_amount); ?></span>
                </div>
                <div class="flex justify-between text-xs py-2">
                    <span class="opacity-70">Most Spent On</span>
                    <span class="font-bold truncate max-w-[120px] text-right" title="<?php echo htmlspecialchars($most_spent_category); ?>"><?php echo htmlspecialchars($most_spent_category); ?></span>
                </div>
            </div>
            <span class="material-symbols-outlined absolute -bottom-4 -right-4 text-8xl opacity-10 pointer-events-none">payments</span>
        </a>

        <!-- 7. Monthly Analysis -->
        <a href="monthly_analysis.php" class="col-span-12 md:col-span-4 rounded-xl p-6 bg-[#2c3e50] text-white shadow-xl flex flex-col justify-between block hover:scale-[1.02] transition-transform">
            <div class="flex justify-between items-center mb-6">
                <h3 class="font-headline text-lg font-black tracking-tight">Monthly Analysis</h3>
                <span class="material-symbols-outlined opacity-40">analytics</span>
            </div>
            <div class="space-y-6">
                <div>
                    <p class="text-[10px] font-bold opacity-60 uppercase mb-1">Revenue Growth</p>
                    <div class="flex items-center gap-2">
                        <span class="text-3xl font-black"><?php echo $revenue_growth_display; ?></span>
                        <div class="px-2 py-0.5 bg-primary/20 text-primary rounded text-[9px] font-bold"><?php echo $growth_badge_label; ?></div>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-3">
                    <div class="flex justify-between items-center p-3 bg-white/5 rounded border border-white/10">
                        <span class="text-[11px] opacity-60">Revenue of the Month</span>
                        <span class="font-bold">₹<?php echo number_format($month_revenue); ?></span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-white/5 rounded border border-white/10">
                        <span class="text-[11px] opacity-60">Total Discounts</span>
                        <span class="font-bold">₹<?php echo number_format($total_month_discounts); ?></span>
                    </div>
                </div>
            </div>
        </a>

        <!-- 8. Employees -->
        <a href="employees.php" class="col-span-12 md:col-span-4 rounded-xl p-6 bg-tertiary text-white shadow-xl relative overflow-hidden flex flex-col justify-between block">
            <div class="relative z-10">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h3 class="font-headline text-xl font-black">Employees</h3>
                        <p class="text-xs font-bold opacity-80 mt-1"><?php echo $total_employees; ?> Professionals Total</p>
                    </div>
                    <span class="material-symbols-outlined text-4xl opacity-40">groups</span>
                </div>
                <div class="space-y-1 mt-4">
                    <?php 
                    $role_count_array = array_values($roles_count);
                    $role_keys = array_keys($roles_count);
                    for($i=0; $i<count($role_keys); $i++) {
                        $role_key = $role_keys[$i];
                        $count = $role_count_array[$i];
                        $label = $role_short_labels[$role_key] ?? ucwords($role_key);
                        $is_last = ($i === count($role_keys) - 1);
                    ?>
                        <div class="flex justify-between text-xs py-1.5 opacity-80 <?php echo !$is_last ? 'border-b border-white/10' : ''; ?>">
                            <span><?php echo htmlspecialchars($label); ?></span>
                            <span class="font-black <?php echo $count > 0 ? 'text-white' : 'text-[#a7f3d0]'; ?>"><?php echo $count; ?></span>
                        </div>
                    <?php } ?>
                </div>
            </div>
            <span class="material-symbols-outlined absolute -bottom-6 -right-6 text-9xl opacity-10 pointer-events-none">diversity_3</span>
        </a>

        <!-- 9. Notifications / Broadcasts -->
        <a href="notifications.php" class="col-span-12 rounded-xl p-8 bg-secondary text-white shadow-2xl relative overflow-hidden block group">
            <div class="flex flex-col md:flex-row justify-between items-center gap-8 relative z-10">
                <div class="flex items-center gap-6">
                    <div class="w-16 h-16 bg-white/10 rounded-full flex items-center justify-center shrink-0 group-hover:bg-white/20 transition-colors">
                        <span class="material-symbols-outlined text-4xl">campaign</span>
                    </div>
                    <div>
                        <h2 class="font-headline text-2xl font-black">Notifications</h2>
                        <p class="text-sm font-medium opacity-70">Automated Patient & Provider Broadcasts</p>
                    </div>
                </div>
                <div class="flex gap-10 md:gap-16 items-center">
                    <div class="text-center">
                        <p class="text-[10px] font-black uppercase tracking-widest opacity-60 mb-1">Broadcasts</p>
                        <p class="text-4xl md:text-5xl font-black"><?php echo number_format($total_broadcasts); ?></p>
                    </div>
                    <div class="text-center">
                        <p class="text-[10px] font-black uppercase tracking-widest opacity-60 mb-1">Queued</p>
                        <p class="text-4xl md:text-5xl font-black text-primary"><?php echo str_pad($queued_broadcasts, 2, '0', STR_PAD_LEFT); ?></p>
                    </div>
                    <div class="hidden sm:block">
                        <button class="px-6 md:px-8 py-3 bg-primary text-white font-bold rounded-xl text-xs uppercase tracking-widest hover:scale-105 transition-transform shadow-lg shadow-primary/20 pointer-events-none">
                            Manage Comms
                        </button>
                    </div>
                </div>
            </div>
            <div class="absolute top-0 right-0 w-96 h-96 bg-white/5 rounded-full -translate-y-1/2 translate-x-1/2 blur-3xl pointer-events-none"></div>
            <span class="material-symbols-outlined absolute -bottom-10 -left-10 text-[200px] opacity-5 pointer-events-none">notifications</span>
        </a>

    </div>
</main>

<!-- Footer Shell -->
<footer class="w-full py-6 px-4 md:px-8 border-t border-slate-200 bg-white flex flex-col md:flex-row justify-between items-center ml-0 md:ml-64 gap-4 pb-20 md:pb-6">
    <p class="font-['Inter'] text-[10px] uppercase tracking-widest text-slate-400 font-bold text-center md:text-left">
        © <?php echo date('Y'); ?> Divya Imaging Center • System Status: <span class="text-tertiary">All Systems Operational</span>
    </p>
    <div class="flex flex-wrap justify-center gap-4 md:gap-8 font-['Inter'] text-[10px] uppercase tracking-widest font-black text-slate-400">
        <a class="hover:text-primary transition-colors" href="#">Privacy Policy</a>
        <a class="hover:text-primary transition-colors" href="#">Terms of Service</a>
        <a class="hover:text-primary transition-colors" href="#">Security Audit</a>
    </div>
</footer>

<!-- Mobile Navigation Shell -->
<nav class="md:hidden fixed bottom-0 left-0 w-full bg-white/95 backdrop-blur-xl h-16 border-t border-slate-200 flex justify-around items-center z-50 px-4">
    <a class="flex flex-col items-center text-primary" href="dashboard.php">
        <span class="material-symbols-outlined">dashboard</span>
        <span class="text-[10px] font-bold uppercase mt-1">Home</span>
    </a>
    <a class="flex flex-col items-center text-slate-400 hover:text-primary transition-colors" href="deep_analysis.php">
        <span class="material-symbols-outlined">analytics</span>
        <span class="text-[10px] font-bold uppercase mt-1">Stats</span>
    </a>
    <a class="flex flex-col items-center text-slate-400 hover:text-primary transition-colors" href="test_count.php">
        <span class="material-symbols-outlined">medical_information</span>
        <span class="text-[10px] font-bold uppercase mt-1">Tests</span>
    </a>
    <a class="flex flex-col items-center text-slate-400 hover:text-primary transition-colors" href="employees.php">
        <span class="material-symbols-outlined">person</span>
        <span class="text-[10px] font-bold uppercase mt-1">Admin</span>
    </a>
</nav>

</div> <!-- End of bg-surface-bright wrapper -->

<!-- Dashboard JS -->
<script src="<?php echo $base_url; ?>/assets/js/superadmin_dashboard.js?v=<?php echo time(); ?>"></script>

<!-- Close the standard body and html tags from header.php if necessary -->
<?php require_once '../includes/footer.php'; ?>
