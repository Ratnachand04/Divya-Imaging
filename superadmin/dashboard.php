<?php
$page_title = "Superadmin Dashboard";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/header.php';

ensure_package_management_schema($conn);

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
$sql_total_tests = "SELECT COUNT(*) as count FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE bi.item_status = 0 AND COALESCE(bi.item_type, 'test') <> 'package' AND b.bill_status != 'Void'";
$total_tests = 0;
if ($res = $conn->query($sql_total_tests)) {
    $total_tests = $res->fetch_assoc()['count'];
}

// Today's Tests & Revenue
$sql_today_stats = "SELECT COUNT(bi.id) as test_count, SUM(b.net_amount) as revenue FROM bills b LEFT JOIN bill_items bi ON b.id = bi.bill_id AND bi.item_status = 0 AND COALESCE(bi.item_type, 'test') <> 'package' WHERE b.bill_status != 'Void' AND DATE(b.created_at) = CURDATE()";
$today_tests = 0;
$today_revenue = 0;
if ($res = $conn->query($sql_today_stats)) {
    $row = $res->fetch_assoc();
    $today_tests = $row['test_count'];
    $today_revenue = $row['revenue'] ?? 0;
}

// Monthly Tests
$sql_month_tests = "SELECT COUNT(*) as count FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE bi.item_status = 0 AND COALESCE(bi.item_type, 'test') <> 'package' AND b.bill_status != 'Void' AND MONTH(b.created_at) = MONTH(CURDATE()) AND YEAR(b.created_at) = YEAR(CURDATE())";
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
$sql_tests_today_doctors = "SELECT COUNT(bi.id) as count FROM bills b JOIN bill_items bi ON b.id = bi.bill_id AND bi.item_status = 0 AND COALESCE(bi.item_type, 'test') <> 'package' WHERE b.bill_status != 'Void' AND b.referral_type = 'Doctor' AND DATE(b.created_at) = CURDATE()";
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
    LEFT JOIN bill_items bi ON bi.bill_id = b.id AND bi.item_status = 0 AND COALESCE(bi.item_type, 'test') <> 'package'
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
$sql_last_month_tests = "SELECT COUNT(*) as count FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE bi.item_status = 0 AND COALESCE(bi.item_type, 'test') <> 'package' AND b.bill_status != 'Void' AND MONTH(b.created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(b.created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH)";
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

<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-dashboard-page {
    --sa-ink: #0f172a;
    --sa-muted: #64748b;
    --sa-border: #e2e8f0;
    --sa-surface: #ffffff;
    --sa-glow-1: #0ea5e9;
    --sa-glow-2: #1d4ed8;
    --sa-glow-3: #10b981;
    --sa-glow-4: #f59e0b;
    font-family: 'Sora', sans-serif;
    display: grid;
    gap: 1rem;
}

.sa-dash-hero {
    border: 1px solid var(--sa-border);
    border-radius: 18px;
    padding: 1rem;
    background:
        radial-gradient(circle at 85% 10%, rgba(14, 165, 233, 0.16), transparent 45%),
        radial-gradient(circle at 15% 90%, rgba(29, 78, 216, 0.14), transparent 40%),
        var(--sa-surface);
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.8rem;
}

.sa-dash-hero h1 {
    margin: 0;
    font-family: 'Space Grotesk', sans-serif;
    color: #0b2a64;
    letter-spacing: -0.02em;
    font-size: 1.65rem;
}

.sa-dash-hero p {
    margin: 0.3rem 0 0;
    color: var(--sa-muted);
    font-size: 0.9rem;
}

.sa-dash-timebox {
    text-align: right;
    color: #0b2a64;
    font-weight: 700;
    font-size: 0.85rem;
}

.sa-dash-timebox small {
    display: block;
    color: var(--sa-muted);
    margin-top: 0.1rem;
    font-weight: 600;
}

.sa-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.8rem;
}

.sa-kpi-card {
    border: 1px solid var(--sa-border);
    border-radius: 16px;
    background: var(--sa-surface);
    padding: 0.85rem;
    box-shadow: 0 10px 22px rgba(15, 23, 42, 0.05);
    text-decoration: none;
    color: inherit;
    transition: transform .2s ease, box-shadow .2s ease;
}

.sa-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 16px 30px rgba(15, 23, 42, 0.1);
}

.sa-kpi-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.sa-kpi-label {
    color: var(--sa-muted);
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
}

.sa-kpi-value {
    margin-top: 0.3rem;
    font-size: 1.9rem;
    font-family: 'Space Grotesk', sans-serif;
    line-height: 1.1;
    color: var(--sa-ink);
}

.sa-kpi-sub {
    margin-top: 0.4rem;
    color: #334155;
    font-size: 0.8rem;
    display: flex;
    justify-content: space-between;
}

.sa-kpi-icon {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 0.95rem;
}

.sa-grad-1 { background: linear-gradient(135deg, var(--sa-glow-1), #0369a1); }
.sa-grad-2 { background: linear-gradient(135deg, var(--sa-glow-2), #312e81); }
.sa-grad-3 { background: linear-gradient(135deg, var(--sa-glow-3), #047857); }
.sa-grad-4 { background: linear-gradient(135deg, var(--sa-glow-4), #d97706); }

.sa-row-two {
    display: grid;
    grid-template-columns: 1.2fr 1fr;
    gap: 0.8rem;
}

.sa-panel {
    border: 1px solid var(--sa-border);
    border-radius: 16px;
    padding: 1rem;
    background: var(--sa-surface);
    box-shadow: 0 10px 22px rgba(15, 23, 42, 0.05);
}

.sa-panel-title {
    margin: 0;
    color: #0b2a64;
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1.08rem;
}

.sa-topdoc {
    text-decoration: none;
    color: inherit;
    display: block;
}

.sa-topdoc-meta {
    margin-top: 0.75rem;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.6rem;
}

.sa-chip {
    background: #f8fafc;
    border: 1px solid var(--sa-border);
    border-radius: 12px;
    padding: 0.55rem 0.6rem;
}

.sa-chip span {
    display: block;
    font-size: 0.72rem;
    color: var(--sa-muted);
    text-transform: uppercase;
    font-weight: 700;
}

.sa-chip strong {
    display: block;
    margin-top: 0.2rem;
    font-family: 'Space Grotesk', sans-serif;
    color: #0f172a;
    font-size: 1rem;
}

.sa-fin-bars {
    margin-top: 0.9rem;
    display: grid;
    gap: 0.55rem;
}

.sa-fin-bar {
    border-radius: 12px;
    border: 1px solid var(--sa-border);
    background: #f8fafc;
    padding: 0.55rem 0.65rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.84rem;
}

.sa-row-three {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.8rem;
}

.sa-slim-list {
    margin-top: 0.6rem;
    display: grid;
    gap: 0.35rem;
}

.sa-employee-actions {
    margin-top: 0.7rem;
    display: flex;
    gap: 0.45rem;
}

.sa-employee-btn {
    border: 1px solid #bfdbfe;
    background: #eff6ff;
    color: #1e3a8a;
    text-decoration: none;
    border-radius: 999px;
    padding: 0.35rem 0.75rem;
    font-size: 0.78rem;
    font-weight: 700;
}

.sa-employee-btn:hover {
    background: #dbeafe;
}

.sa-slim-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px dashed #dbe1ea;
    padding-bottom: 0.35rem;
    color: #334155;
    font-size: 0.82rem;
}

.sa-notify-cta {
    text-decoration: none;
    color: #fff;
    border-radius: 16px;
    padding: 1rem;
    background: linear-gradient(120deg, #1e3a8a 0%, #3b82f6 45%, #0ea5e9 100%);
    box-shadow: 0 16px 30px rgba(29, 78, 216, 0.3);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.8rem;
}

.sa-notify-cta h3 {
    margin: 0;
    font-size: 1.2rem;
    font-family: 'Space Grotesk', sans-serif;
}

.sa-notify-cta p {
    margin: 0.2rem 0 0;
    opacity: .88;
    font-size: .86rem;
}

.sa-notify-counts {
    display: flex;
    gap: 1rem;
    align-items: center;
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1.35rem;
}

.sa-pill {
    border: 1px solid rgba(255,255,255,0.35);
    background: rgba(255,255,255,0.12);
    border-radius: 999px;
    padding: 0.2rem 0.7rem;
    font-size: .76rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    font-weight: 700;
}

@media (max-width: 1200px) {
    .sa-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .sa-row-two { grid-template-columns: 1fr; }
    .sa-row-three { grid-template-columns: 1fr; }
}

@media (max-width: 700px) {
    .sa-kpi-grid { grid-template-columns: 1fr; }
    .sa-dash-hero { flex-direction: column; }
    .sa-dash-timebox { text-align: left; }
    .sa-notify-cta { flex-direction: column; align-items: flex-start; }
}
</style>

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

<div class="page-container font-body text-on-surface">

    <!-- Header Metrics -->
    <div class="sa-dashboard-header flex justify-between items-end mb-8">
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

        <article class="sa-panel" style="text-decoration:none;color:inherit;">
            <h2 class="sa-panel-title">Employee Strength</h2>
            <div style="margin:.4rem 0 .2rem;color:#64748b;font-size:.86rem;"><?php echo number_format($total_employees); ?> active professionals</div>
            <div class="sa-slim-list">
                <?php foreach ($roles_count as $roleKey => $count): ?>
                    <div class="sa-slim-item">
                        <span><?php echo htmlspecialchars($role_short_labels[$roleKey] ?? ucwords($roleKey)); ?></span>
                        <strong><?php echo (int)$count; ?></strong>
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
            <div class="sa-employee-actions">
                <a class="sa-employee-btn" href="employees.php">Manage</a>
                <a class="sa-employee-btn" href="analysis.php">Analysis</a>
            </div>
        </article>
    </section>

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

    </div> <!-- End of bento-grid -->
</div> <!-- End of page-container -->

<!-- Dashboard JS -->
<script src="<?php echo $base_url; ?>/assets/js/superadmin_dashboard.js?v=<?php echo time(); ?>"></script>

<?php require_once '../includes/footer.php'; ?>
