<header class="main-header">
    <div class="header-container">
        <div class="logo-area">
            <button id="sidebar-toggle" class="shell-icon-btn" aria-label="Toggle sidebar" title="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <a href="<?php echo htmlspecialchars($home_link); ?>">
                <img src="<?php echo $base_url; ?>/assets/images/logo.jpg" alt="Divya Imaging Center Logo">
                <span>Divya Imaging</span>
            </a>
        </div>

        <div class="user-info-area">
            <?php if ($raw_role === 'superadmin'): ?>
                <a
                    href="<?php echo $base_url; ?>/superadmin/manage_calendar.php"
                    class="shell-icon-btn"
                    aria-label="Calendar"
                    title="Calendar"
                >
                    <i class="far fa-calendar-alt"></i>
                </a>
            <?php endif; ?>
            <div class="header-notification">
                <button
                    id="notification-bell-btn"
                    class="shell-icon-btn notification-bell-btn"
                    aria-label="Notifications"
                    title="Notifications"
                    aria-haspopup="true"
                    aria-expanded="false"
                    aria-controls="partial-paid-dropdown"
                >
                    <i class="fas fa-bell"></i>
                    <span id="partial-paid-badge" class="notification-badge is-hidden" aria-hidden="true">0</span>
                </button>
                <div id="partial-paid-dropdown" class="notification-dropdown" hidden></div>
            </div>
            <?php if ($raw_role === 'superadmin'): ?>
                <a
                    href="<?php echo $base_url; ?>/superadmin/global_settings.php"
                    class="shell-icon-btn"
                    aria-label="Settings"
                    title="Settings"
                >
                    <i class="fas fa-cog"></i>
                </a>
            <?php else: ?>
                <button class="shell-icon-btn" aria-label="Settings" title="Settings" type="button">
                    <i class="fas fa-cog"></i>
                </button>
            <?php endif; ?>
            <span class="user-chip" title="<?php echo $username; ?> (<?php echo ucfirst($role); ?>)">
                <span class="user-avatar"><?php echo $user_initial; ?></span>
                <span class="user-meta">
                    <strong><?php echo $username; ?></strong>
                    <small><?php echo ucfirst($role); ?></small>
                </span>
            </span>
            <a href="<?php echo $base_url; ?>/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
</header>
