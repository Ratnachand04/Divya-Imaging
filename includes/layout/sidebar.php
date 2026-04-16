<nav class="main-navbar" id="main-nav" aria-label="Primary">
    <div class="sidebar-role-label"><?php echo strtoupper($raw_role); ?> PANEL</div>
    <ul>
        <?php if ($role === 'superadmin'): ?>
            <li><a href="<?php echo $base_url; ?>/superadmin/dashboard.php" data-tooltip="Dashboard" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i><span class="nav-label">Dashboard</span></a></li>
            <li><a href="<?php echo $base_url; ?>/superadmin/lists.php" data-tooltip="Lists" class="<?php echo in_array($current_page, ['lists.php', 'view_tests.php', 'view_doctors.php']) ? 'active' : ''; ?>"><i class="fas fa-list-alt"></i><span class="nav-label">Lists</span></a></li>
            <li><a href="<?php echo $base_url; ?>/manager/manage_packages.php" data-tooltip="Packages" class="<?php echo ($current_page == 'manage_packages.php') ? 'active' : ''; ?>"><i class="fas fa-box-open"></i><span class="nav-label">Packages</span></a></li>
            <li><a href="<?php echo $base_url; ?>/superadmin/detailed_report.php" data-tooltip="Reports" class="<?php echo ($current_page == 'detailed_report.php') ? 'active' : ''; ?>"><i class="fas fa-file-alt"></i><span class="nav-label">Reports</span></a></li>
            <li><a href="<?php echo $base_url; ?>/superadmin/manage_calendar.php" data-tooltip="Calendar" class="<?php echo ($current_page == 'manage_calendar.php') ? 'active' : ''; ?>"><i class="fas fa-calendar-alt"></i><span class="nav-label">Calendar</span></a></li>
            <li><a href="<?php echo $base_url; ?>/superadmin/global_settings.php" data-tooltip="Global Settings" class="<?php echo ($current_page == 'global_settings.php') ? 'active' : ''; ?>"><i class="fas fa-cogs"></i><span class="nav-label">Global Settings</span></a></li>
        <?php elseif ($role === 'manager'): ?>
            <li><a href="dashboard.php" data-tooltip="Dashboard" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i><span class="nav-label">Dashboard</span></a></li>
            <li><a href="analytics.php" data-tooltip="Analytics" class="<?php echo ($current_page == 'analytics.php') ? 'active' : ''; ?>"><i class="fas fa-chart-pie"></i><span class="nav-label">Analytics</span></a></li>
            <li><a href="manage_tests.php" data-tooltip="Tests" class="<?php echo ($current_page == 'manage_tests.php') ? 'active' : ''; ?>"><i class="fas fa-vials"></i><span class="nav-label">Tests</span></a></li>
            <li><a href="manage_packages.php" data-tooltip="Packages" class="<?php echo ($current_page == 'manage_packages.php') ? 'active' : ''; ?>"><i class="fas fa-box-open"></i><span class="nav-label">Packages</span></a></li>
            <li><a href="manage_doctors.php" data-tooltip="Doctors" class="<?php echo in_array($current_page, ['manage_doctors.php', 'manage_doctor_commissions.php']) ? 'active' : ''; ?>"><i class="fas fa-user-md"></i><span class="nav-label">Doctors</span></a></li>
            <li><a href="expenses.php" data-tooltip="Expenses" class="<?php echo ($current_page == 'expenses.php') ? 'active' : ''; ?>"><i class="fas fa-wallet"></i><span class="nav-label">Expenses</span></a></li>
            <li><a href="requests.php" data-tooltip="Requests" class="<?php echo ($current_page == 'requests.php') ? 'active' : ''; ?>"><i class="fas fa-inbox"></i><span class="nav-label">Requests</span><span class="nav-badge is-hidden" data-nav-count="requests">0</span></a></li>
            <li><a href="manage_employees.php" data-tooltip="Employees" class="<?php echo ($current_page == 'manage_employees.php') ? 'active' : ''; ?>"><i class="fas fa-users"></i><span class="nav-label">Employees</span></a></li>
            <li><a href="existing_patients.php" data-tooltip="Patients" class="<?php echo in_array($current_page, ['existing_patients.php', 'edit_patient.php']) ? 'active' : ''; ?>"><i class="fas fa-users"></i><span class="nav-label">Patients</span></a></li>
            <li><a href="<?php echo $base_url; ?>/manager/view_due_bills.php" data-tooltip="Pending Bills" class="<?php echo ($current_page == 'view_due_bills.php') ? 'active' : ''; ?>"><i class="fas fa-file-invoice-dollar"></i><span class="nav-label">Pending Bills</span><span class="nav-badge is-hidden" data-nav-count="pending-bills">0</span></a></li>
            <li><a href="print_reports.php" data-tooltip="Print Reports" class="<?php echo ($current_page == 'print_reports.php') ? 'active' : ''; ?>"><i class="fas fa-print"></i><span class="nav-label">Print Reports</span><span class="nav-badge is-hidden" data-nav-count="pending-reports">0</span></a></li>
            <li><a href="reporting_doctors.php" data-tooltip="Reporting Doctors" class="<?php echo ($current_page == 'reporting_doctors.php') ? 'active' : ''; ?>"><i class="fas fa-user-md"></i><span class="nav-label">Reporting Doctors</span></a></li>

        <?php elseif ($role === 'accountant'): ?>
            <li><a href="dashboard.php" data-tooltip="Dashboard" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i><span class="nav-label">Dashboard</span></a></li>
            <li><a href="manage_payments.php" data-tooltip="Payments" class="<?php echo ($current_page == 'manage_payments.php') ? 'active' : ''; ?>"><i class="fas fa-credit-card"></i><span class="nav-label">Payments</span></a></li>
            <li><a href="doctor_payouts.php" data-tooltip="Professional Charges" class="<?php echo ($current_page == 'doctor_payouts.php') ? 'active' : ''; ?>"><i class="fas fa-hand-holding-usd"></i><span class="nav-label">Professional Charges</span></a></li>
            <li><a href="view_expenses.php" data-tooltip="Expenses" class="<?php echo in_array($current_page, ['view_expenses.php', 'log_expense.php']) ? 'active' : ''; ?>"><i class="fas fa-receipt"></i><span class="nav-label">Expenses</span></a></li>
            <li><a href="discount_report.php" data-tooltip="Discounts" class="<?php echo ($current_page == 'discount_report.php') ? 'active' : ''; ?>"><i class="fas fa-tag"></i><span class="nav-label">Discounts</span></a></li>
        <?php elseif ($role === 'writer'): ?>
            <li><a href="dashboard.php" data-tooltip="Dashboard" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i><span class="nav-label">Dashboard</span></a></li>
            <li><a href="write_reports.php" data-tooltip="Write Report" class="<?php echo ($current_page == 'write_reports.php') ? 'active' : ''; ?>"><i class="fas fa-pen-nib"></i><span class="nav-label">Write Report</span></a></li>
            <li><a href="view_reports.php" data-tooltip="Completed" class="<?php echo ($current_page == 'view_reports.php') ? 'active' : ''; ?>"><i class="fas fa-file-medical-alt"></i><span class="nav-label">Completed</span></a></li>
            <li><a href="templates.php" data-tooltip="Templates" class="<?php echo ($current_page == 'templates.php') ? 'active' : ''; ?>"><i class="fas fa-layer-group"></i><span class="nav-label">Templates</span></a></li>
        <?php elseif ($role === 'receptionist'): ?>
            <li><a href="dashboard.php" data-tooltip="Dashboard" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i><span class="nav-label">Dashboard</span></a></li>
            <li><a href="generate_bill.php" data-tooltip="Generate Bill" class="<?php echo in_array($current_page, ['generate_bill.php', 'edit_bill.php']) ? 'active' : ''; ?>"><i class="fas fa-file-medical"></i><span class="nav-label">Generate Bill</span></a></li>
            <li><a href="existing_patients.php" data-tooltip="Patients" class="<?php echo in_array($current_page, ['existing_patients.php', 'edit_patient.php']) ? 'active' : ''; ?>"><i class="fas fa-users"></i><span class="nav-label">Patients</span></a></li>
            <li><a href="bill_history.php" data-tooltip="Bill History" class="<?php echo ($current_page == 'bill_history.php') ? 'active' : ''; ?>"><i class="fas fa-history"></i><span class="nav-label">Bill History</span></a></li>
        <?php endif; ?>
    </ul>
</nav>
