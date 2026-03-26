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
$top_doc_name = "No Doctor";
$top_doc_revenue = 0;
$top_doc_tests_ratio = 0;
if ($res = $conn->query($sql_top_doc)) {
    if ($row = $res->fetch_assoc()) {
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

<style>
    .revenue-figure { font-weight: 600; }
    .revenue-figure.revenue-positive { color: #0f9d58; }
    .revenue-figure.revenue-negative { color: #d93025; }
</style>

<div class="main-content page-container">
    <div class="dashboard-header">
        <h1>SuperAdmin Dashboard</h1>
        <p>Overview of system performance and activities.</p>
    </div>

    <div class="dashboard-grid">
        <!-- 1. Test Count -->
        <a href="test_count.php" class="tile tile-1">
            <span class="tile-date" id="testTileDate"></span>
            <div class="tile-content">
                <h3>Test Count</h3>
                <div class="stat-row">
                    <span>This Month:</span> <strong><?php echo number_format($month_tests); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Today:</span> <strong><?php echo number_format($today_tests); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Rev Today:</span> <strong <?php echo $revenue_class_attr; ?>>₹<?php echo number_format($today_revenue); ?></strong>
                </div>
            </div>
            <i class="fas fa-vials"></i>
        </a>

        <!-- 2. Doctors Count -->
        <a href="doctor_wise_count.php" class="tile tile-2">
            <div class="tile-content">
                <h3>Doctors</h3>
                <div class="stat-row">
                    <span>Active Today:</span> <strong><?php echo number_format($active_doctors_today); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Tests / Doctor:</span> <strong><?php echo number_format($tests_doctor_ratio, 1); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Avg Rev Today:</span> <strong <?php echo $revenue_class_attr; ?>>₹<?php echo number_format($avg_revenue_per_doctor); ?></strong>
                </div>
            </div>
            <i class="fas fa-user-md"></i>
        </a>

        <!-- 3. Audit Log -->
        <a href="audit_log.php" class="tile tile-3">
            <div class="tile-content">
                <h3>Audit Log</h3>
                <div class="stat-row">
                    <span>Bill Edits:</span> <strong><?php echo number_format($bill_edits_today); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Changes:</span> <strong><?php echo htmlspecialchars($latest_change_summary); ?></strong>
                </div>
            </div>
            <i class="fas fa-history"></i>
        </a>

        <!-- 4. Compare Doctors -->
            <a href="compare.php" class="tile tile-4">
                <div class="tile-content">
                    <h3>Top Doctor (<?php echo htmlspecialchars($top_doc_period_label); ?>)</h3>
                    <div class="stat-row">
                        <span>Doctor:</span>
                        <strong class="top-doc-name"><?php echo htmlspecialchars($top_doc_name); ?></strong>
                    </div>
                    <div class="stat-row">
                        <span>Revenue:</span>
                        <strong <?php echo $revenue_class_attr; ?>>₹<?php echo number_format($top_doc_revenue); ?></strong>
                    </div>
                    <div class="stat-row">
                        <span>Tests / Patient:</span>
                        <strong><?php echo number_format($top_doc_tests_ratio, 2); ?></strong>
                    </div>
                </div>
                <i class="fas fa-balance-scale"></i>
            </a>

        <!-- 5. Deep Analysis -->
        <a href="deep_analysis.php" class="tile tile-5">
            <div class="tile-content">
                <h3>Deep Analysis</h3>
                <div class="stat-row">
                    <span>Revenue (This Month):</span> <strong <?php echo $revenue_class_attr; ?>>₹<?php echo number_format($month_revenue); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Daily Avg (This Month):</span> <strong <?php echo $revenue_class_attr; ?>>₹<?php echo number_format($month_avg_rev_per_test, 2); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Last Month Revenue:</span> <strong <?php echo $revenue_class_attr; ?>>₹<?php echo number_format($last_month_rev); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Last Month Daily Avg:</span> <strong <?php echo $revenue_class_attr; ?>>₹<?php echo number_format($last_month_avg_rev_per_test, 2); ?></strong>
                </div>
            </div>
            <i class="fas fa-chart-line"></i>
        </a>

        <!-- 6. Employees -->
        <a href="employees.php" class="tile tile-6">
            <div class="tile-content">
                <h3>Employees</h3>
                <div class="stat-row">
                    <span>Total Employees:</span> <strong><?php echo number_format($total_employees); ?></strong>
                </div>
                <ul class="role-count-list" style="list-style:none; padding:0; margin:8px 0 0; font-size:0.9rem;">
                    <?php foreach ($roles_count as $roleKey => $roleCount): ?>
                        <li style="display:flex; justify-content:space-between; padding:2px 0;">
                            <span><?php echo htmlspecialchars($role_labels[$roleKey] ?? ucwords($roleKey)); ?>:</span>
                            <strong><?php echo number_format($roleCount); ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <i class="fas fa-users"></i>
        </a>

        <!-- 7. Notifications -->
        <a href="notifications.php" class="tile tile-7">
            <div class="tile-content">
                <h3>Notifications</h3>
                <div class="stat-row">
                    <span>Broadcasts:</span> <strong><?php echo number_format($total_broadcasts); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Queued:</span> <strong><?php echo number_format($queued_broadcasts); ?></strong>
                </div>
            </div>
            <i class="fas fa-bell"></i>
        </a>

        <!-- 8. Expenditure -->
        <a href="expenditure.php" class="tile tile-8">
            <div class="tile-content">
                <h3>Expenditure</h3>
                <div class="stat-row">
                    <span>Total Expenditure:</span> <strong>₹<?php echo number_format($month_expenditure); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Today:</span> <strong>₹<?php echo number_format($today_expenditure); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Most Spent:</span> <strong>₹<?php echo number_format($most_spent_amount); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Most Spent On:</span> <strong><?php echo htmlspecialchars($most_spent_category); ?></strong>
                </div>
            </div>
            <i class="fas fa-wallet"></i>
        </a>

        <!-- 9. Monthly Analysis -->
        <a href="monthly_analysis.php" class="tile tile-9">
            <div class="tile-content">
                <h3>Monthly Analysis</h3>
                <div class="stat-row">
                    <span>Revenue of the Month:</span> <strong <?php echo $revenue_class_attr; ?>>₹<?php echo number_format($month_revenue); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Expenditure of the Month:</span> <strong>₹<?php echo number_format($month_expenditure); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Total Discounts:</span> <strong>₹<?php echo number_format($total_month_discounts); ?></strong>
                </div>
                <div class="stat-row">
                    <span>Revenue Growth:</span> 
                    <strong <?php echo $revenue_class_attr; ?>><?php echo $revenue_growth_display; ?></strong>
                </div>
            </div>
            <i class="fas fa-calendar-alt"></i>
        </a>
    </div>
</div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tileDateEl = document.getElementById('testTileDate');
        if (!tileDateEl) return;
        const formatter = new Intl.DateTimeFormat('en-IN', {
            weekday: 'short',
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });

        const updateTileDate = () => {
            tileDateEl.textContent = formatter.format(new Date());
        };

        updateTileDate();
        setInterval(updateTileDate, 60000);
    });
    </script>


<?php require_once '../includes/footer.php'; ?>
