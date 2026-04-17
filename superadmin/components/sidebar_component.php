<?php
$sa_menu_items = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'fa-th-large'],
    ['label' => 'Analysis', 'href' => 'analysis.php', 'icon' => 'fa-chart-line'],
    ['label' => 'Scans', 'href' => 'scans.php', 'icon' => 'fa-x-ray'],
    ['label' => 'Doctors', 'href' => 'view_doctors.php', 'icon' => 'fa-user-md'],
    ['label' => 'Radiology', 'href' => 'test_count.php', 'icon' => 'fa-radiation'],
    ['label' => 'Financials', 'href' => 'expenditure.php', 'icon' => 'fa-wallet'],
    ['label' => 'Patients', 'href' => 'patients.php', 'icon' => 'fa-procedures'],
    ['label' => 'Reports', 'href' => 'print_reports.php', 'icon' => 'fa-file-medical-alt'],
    ['label' => 'Employee', 'href' => 'employees.php', 'icon' => 'fa-users']
];
?>

<aside class="sa-sidebar" id="sa-sidebar">
    <div class="sa-brand">
        <h2>Divya Imaging</h2>
        <p>Clinical Precision</p>
    </div>

    <nav class="sa-menu" aria-label="Superadmin navigation">
        <?php foreach ($sa_menu_items as $item): ?>
            <a
                href="<?php echo htmlspecialchars($item['href']); ?>"
                class="sa-menu-link <?php echo $sa_active_page === $item['href'] ? 'active' : ''; ?>"
            >
                <i class="fas <?php echo htmlspecialchars($item['icon']); ?>"></i>
                <span><?php echo htmlspecialchars($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sa-sidebar-bottom">
        <a
            href="global_settings.php"
            class="sa-menu-link <?php echo $sa_active_page === 'global_settings.php' ? 'active' : ''; ?>"
        >
            <i class="fas fa-cog"></i>
            <span>Settings</span>
        </a>
    </div>
</aside>
