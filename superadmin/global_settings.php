<?php
$page_title = "Settings";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

$sa_active_page = 'global_settings.php';
?>

<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-settings-page { display: grid; gap: 1rem; }
.sa-settings-head {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-settings-head h1 { margin: 0; color: #1e3a8a; font-size: 1.5rem; }
.sa-settings-head p { margin: 0.2rem 0 0; color: #64748b; }
.sa-settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 0.9rem;
}
.sa-setting-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
    display: grid;
    gap: 0.6rem;
}
.sa-setting-card h3 { margin: 0; color: #1e3a8a; font-size: 1.05rem; }
.sa-setting-card p { margin: 0; color: #64748b; font-size: 0.9rem; }
.sa-setting-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    width: fit-content;
    border-radius: 999px;
    border: 1px solid #1e3a8a;
    background: #1e3a8a;
    color: #fff;
    text-decoration: none;
    padding: 0.44rem 0.9rem;
    font-weight: 700;
}
.sa-setting-btn:hover { background: #1d4ed8; border-color: #1d4ed8; color: #fff; }
.sa-setting-btn.is-placeholder {
    border-color: #cbd5e1;
    background: #f8fafc;
    color: #64748b;
    cursor: default;
}
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<section class="sa-settings-page">
    <article class="sa-settings-head">
        <h1>Settings</h1>
        <p>Choose a settings section.</p>
    </article>

    <section class="sa-settings-grid">
        <article class="sa-setting-card">
            <h3>Employee Management</h3>
            <p>Employee Access</p>
            <a class="sa-setting-btn" href="employee_access.php"><i class="fas fa-user-shield"></i> Open</a>
        </article>

        <article class="sa-setting-card">
            <h3>Employee Logs</h3>
            <p>Audit Log</p>
            <a class="sa-setting-btn" href="audit_log.php"><i class="fas fa-clipboard-list"></i> Open</a>
        </article>

        <article class="sa-setting-card">
            <h3>Email</h3>
            <p>Button only (no action)</p>
            <span class="sa-setting-btn is-placeholder"><i class="fas fa-envelope"></i> Email</span>
        </article>
    </section>
</section>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>
