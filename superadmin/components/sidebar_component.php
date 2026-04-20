<?php
$sa_menu_items = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'fa-th-large'],
    ['label' => 'Analysis', 'href' => 'analysis.php', 'icon' => 'fa-chart-line'],
    ['label' => 'Scans', 'href' => 'scans.php', 'icon' => 'fa-flask'],
    ['label' => 'Doctors', 'href' => 'view_doctors.php', 'icon' => 'fa-user-md'],
    ['label' => 'Radiology', 'href' => 'test_count.php', 'icon' => 'fa-radiation'],
    ['label' => 'Financials', 'href' => 'expenditure.php', 'icon' => 'fa-wallet'],
    ['label' => 'Patients', 'href' => 'patients.php', 'icon' => 'fa-bed'],
    ['label' => 'Employee', 'href' => 'employees.php', 'icon' => 'fa-users'],
    ['label' => 'Settings', 'href' => 'global_settings.php', 'icon' => 'fa-cog']
];
?>

<nav class="sa-topnav" aria-label="Superadmin navigation">
    <?php foreach ($sa_menu_items as $item): ?>
        <a
            href="<?php echo htmlspecialchars($item['href']); ?>"
            class="sa-topnav-link <?php echo $sa_active_page === $item['href'] ? 'active' : ''; ?>"
        >
            <i class="fas <?php echo htmlspecialchars($item['icon']); ?>"></i>
            <span><?php echo htmlspecialchars($item['label']); ?></span>
        </a>
    <?php endforeach; ?>
    <a href="<?php echo $base_url; ?>/logout.php" class="sa-topnav-link sa-topnav-link-logout">
        <i class="fas fa-sign-out-alt"></i>
        <span>Logout</span>
    </a>
</nav>
