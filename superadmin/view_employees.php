<?php
$page_title = "View Employees";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

$sa_active_page = 'employees.php';

$users_result = $conn->query("SELECT id, COALESCE(NULLIF(full_name, ''), username) AS full_name, COALESCE(NULLIF(employee_role, ''), role) AS display_role, COALESCE(account_details, '') AS account_details, document_path FROM users WHERE role NOT IN ('superadmin', 'platform_admin', 'developer') ORDER BY full_name ASC, username ASC");
?>

<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-view-emp-page { display: grid; gap: 1rem; }
.sa-view-emp-head {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-view-emp-head h1 { margin: 0; color: #1e3a8a; font-size: 1.45rem; }
.sa-view-emp-head p { margin: 0.2rem 0 0; color: #64748b; }
.sa-back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin-top: 0.7rem;
    color: #1e40af;
    text-decoration: none;
    font-weight: 700;
}
.sa-view-emp-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.employee-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}
.employee-table thead th {
    background: #f8fafc;
    color: #1e3a8a;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding: 0.65rem 0.55rem;
    border-bottom: 1px solid #e2e8f0;
}
.employee-table tbody td {
    padding: 0.66rem 0.55rem;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: middle;
}
.employee-table tbody tr:nth-child(even) { background: #fcfdff; }
.employee-table tbody tr:hover { background: #f8fafc; }
.employee-table .col-account {
    max-width: 320px;
    white-space: normal;
    word-break: break-word;
    line-height: 1.35;
}
.employee-table .col-doc,
.employee-table .col-actions { white-space: nowrap; }
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<section class="sa-view-emp-page">
    <article class="sa-view-emp-head">
        <h1>View Employees</h1>
        <p>Employee master list with role and account details.</p>
        <a class="sa-back-link" href="employees.php"><i class="fas fa-arrow-left"></i> Back to Add Employee</a>
    </article>

    <article class="sa-view-emp-card">
        <div class="table-responsive">
            <table class="data-table custom-table employee-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Account Details</th>
                        <th>Document</th>
                        <th>Edit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($users_result && $users_result->num_rows > 0): while($user = $users_result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $user['id']; ?></td>
                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($user['display_role']); ?></td>
                        <td class="col-account"><?php echo nl2br(htmlspecialchars($user['account_details'] !== '' ? $user['account_details'] : '-')); ?></td>
                        <td class="col-doc">
                            <?php if (!empty($user['document_path'])): ?>
                                <a href="<?php echo $base_url . '/' . htmlspecialchars($user['document_path']); ?>" target="_blank" rel="noopener">View</a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td class="col-actions"><a href="edit_employee.php?id=<?php echo $user['id']; ?>" class="btn-action btn-edit">Edit</a></td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="6">No employees found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>
