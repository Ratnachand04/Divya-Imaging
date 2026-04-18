<?php
$page_title = "Employee Management";
// FIX: The required role must be set to "manager"
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$sa_active_page = 'employees.php';

$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS full_name VARCHAR(120) NULL AFTER username");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS account_details TEXT NULL AFTER role");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS document_path VARCHAR(255) NULL AFTER account_details");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS employee_role VARCHAR(120) NULL AFTER full_name");

$feedback = isset($_SESSION['feedback']) ? $_SESSION['feedback'] : '';
unset($_SESSION['feedback']);

if (!function_exists('upload_employee_document')) {
    function upload_employee_document(array $file): array
    {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return [true, null, ''];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [false, null, 'Document upload failed. Please try again.'];
        }

        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            return [false, null, 'Document size must be 5MB or less.'];
        }

        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
        if (!in_array($extension, $allowed, true)) {
            return [false, null, 'Allowed document types: PDF, JPG, JPEG, PNG, DOC, DOCX.'];
        }

        $relativeDir = 'uploads/employee_docs';
        $absoluteDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'employee_docs';
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            return [false, null, 'Could not create employee document folder.'];
        }

        $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo((string)$file['name'], PATHINFO_FILENAME));
        if ($safeBase === '' || $safeBase === null) {
            $safeBase = 'employee_doc';
        }

        $targetName = $safeBase . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $targetPath = $absoluteDir . DIRECTORY_SEPARATOR . $targetName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return [false, null, 'Failed to save uploaded document.'];
        }

        return [true, $relativeDir . '/' . $targetName, ''];
    }
}

if (!function_exists('build_employee_account_details')) {
    function build_employee_account_details(array $input): string
    {
        $map = [
            'Account Holder' => trim((string)($input['account_holder_name'] ?? '')),
            'Bank Name' => trim((string)($input['bank_name'] ?? '')),
            'Account Number' => trim((string)($input['account_number'] ?? '')),
            'IFSC' => strtoupper(trim((string)($input['ifsc_code'] ?? ''))),
            'MMID' => trim((string)($input['mmid'] ?? '')),
            'Branch' => trim((string)($input['branch_name'] ?? '')),
            'UPI ID' => trim((string)($input['upi_id'] ?? '')),
            'Notes' => trim((string)($input['account_notes'] ?? '')),
        ];

        $lines = [];
        foreach ($map as $label => $value) {
            if ($value !== '') {
                $lines[] = $label . ': ' . $value;
            }
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('generate_employee_username')) {
    function generate_employee_username(mysqli $conn, string $fullName): string
    {
        $base = strtolower(trim($fullName));
        $base = preg_replace('/[^a-z0-9]+/', '.', $base);
        $base = trim((string)$base, '.');
        if ($base === '') {
            $base = 'employee';
        }
        $base = substr($base, 0, 40);

        $candidate = $base;
        $index = 1;
        while (true) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            if (!$stmt) {
                return $candidate . '.' . bin2hex(random_bytes(2));
            }
            $stmt->bind_param('s', $candidate);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$exists) {
                return $candidate;
            }

            $index++;
            $candidate = substr($base, 0, 34) . '.' . $index;
        }
    }
}

if (!function_exists('resolve_auth_role_for_employee_master')) {
    function resolve_auth_role_for_employee_master(string $employeeRole): string
    {
        $normalized = strtolower(trim($employeeRole));
        $map = [
            'receptionist' => 'receptionist',
            'accountant' => 'accountant',
            'writer' => 'writer',
            'manager' => 'manager',
        ];

        return $map[$normalized] ?? 'receptionist';
    }
}

// --- HANDLE ADD NEW EMPLOYEE FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $employee_role = trim($_POST['employee_role'] ?? '');
    $account_details = build_employee_account_details($_POST);

    if (empty($full_name) || empty($employee_role)) {
        $feedback = "<div class='error-banner'>Name and role are required.</div>";
    } else {
        [$uploadOk, $documentPath, $uploadMessage] = upload_employee_document($_FILES['employee_document'] ?? []);
        if (!$uploadOk) {
            $feedback = "<div class='error-banner'>" . htmlspecialchars($uploadMessage) . "</div>";
        } else {
            $username = generate_employee_username($conn, $full_name);
            $hashed_password = password_hash(bin2hex(random_bytes(10)), PASSWORD_DEFAULT);
            $authRole = resolve_auth_role_for_employee_master($employee_role);

            $stmt_insert = $conn->prepare("INSERT INTO users (username, full_name, employee_role, password, role, account_details, document_path, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt_insert->bind_param("sssssss", $username, $full_name, $employee_role, $hashed_password, $authRole, $account_details, $documentPath);

            if ($stmt_insert->execute()) {
                $new_user_id = $stmt_insert->insert_id;
                $feedback = "<div class='success-banner'>Employee '" . htmlspecialchars($full_name) . "' added successfully.</div>";

                require_once '../includes/functions.php';
                $details = "Manager created employee '{$full_name}' (ID: {$new_user_id}) with role '{$employee_role}'.";
                log_system_action($conn, 'USER_CREATED', $new_user_id, $details);
            } else {
                $feedback = "<div class='error-banner'>Error: Could not add the employee.</div>";
            }
            $stmt_insert->close();
        }
    }
}

require_once '../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-employee-page { display: grid; gap: 1rem; }
.sa-employee-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-employee-head h1 { margin: 0; color: #1e3a8a; font-size: 1.55rem; }
.sa-employee-head p { margin: 0.2rem 0 0; color: #64748b; }
.sa-view-employees-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    margin-top: 0.75rem;
    background: #1e3a8a;
    color: #fff;
    text-decoration: none;
    border-radius: 999px;
    padding: 0.48rem 0.95rem;
    font-weight: 700;
    width: fit-content;
}
.sa-view-employees-btn:hover { background: #1d4ed8; color: #fff; }
.sa-employee-card h3 { margin: 0 0 0.85rem; color: #1e3a8a; font-size: 1.08rem; }
.employee-form-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(180px, 1fr));
    gap: 12px;
    align-items: start;
}
.employee-form-grid .wide-field {
    grid-column: 1 / -1;
}
.employee-form-grid .form-group { margin: 0; }
.employee-form-grid label {
    display: block;
    margin-bottom: 0.35rem;
    color: #475569;
    font-weight: 700;
    font-size: 0.86rem;
}
.employee-form-grid input[type="text"],
.employee-form-grid input[type="password"],
.employee-form-grid input[type="file"],
.employee-form-grid select,
.employee-form-grid textarea {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 0.55rem 0.65rem;
    font-size: 0.92rem;
    background: #fff;
}
.employee-form-grid textarea {
    min-height: 70px;
    resize: vertical;
}
.employee-form-grid .btn-submit {
    width: 100%;
    margin-top: 0 !important;
    border-radius: 999px;
}
.sa-password-toggle {
    margin-top: 0.15rem;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.84rem;
    color: #64748b;
    font-weight: 600;
    width: fit-content;
}
.employee-form-grid label.sa-password-toggle {
    display: inline-flex;
    margin-bottom: 0;
}
.sa-password-toggle input[type="checkbox"] {
    width: 16px;
    height: 16px;
    margin: 0;
}
.sa-file-action-row {
    display: grid;
    grid-template-columns: 1fr minmax(180px, 280px);
    gap: 12px;
    align-items: end;
}
@media (max-width: 1200px) {
    .employee-form-grid {
        grid-template-columns: repeat(2, minmax(240px, 1fr));
    }
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
    max-width: 280px;
    white-space: normal;
    word-break: break-word;
    line-height: 1.35;
}
.employee-table .col-doc,
.employee-table .col-actions {
    white-space: nowrap;
}
.employee-table .col-actions .btn-action { margin-right: 0.35rem; }
.employee-table .col-actions .btn-action:last-child { margin-right: 0; }
@media (max-width: 768px) {
    .employee-form-grid {
        grid-template-columns: 1fr;
    }
    .sa-file-action-row {
        grid-template-columns: 1fr;
    }
    .employee-table thead th,
    .employee-table tbody td { font-size: 0.82rem; }
}
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<section class="sa-employee-page">
    <article class="sa-employee-card sa-employee-head">
        <h1>Employee Management</h1>
        <p>Add, view, and manage employee accounts in the system.</p>
        <a class="sa-view-employees-btn" href="view_employees.php"><i class="fas fa-list"></i> View Employees</a>
    </article>

    <?php if($feedback) echo $feedback; ?>

    <article class="sa-employee-card management-form">
        <h3>Add New Employee</h3>
        <form action="employees.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="add_employee" value="1">
            <div class="employee-form-grid">
                <div class="form-group">
                    <label for="full_name">Name</label>
                    <input type="text" id="full_name" name="full_name" required>
                </div>
                <div class="form-group">
                    <label for="employee_role">Role</label>
                    <input type="text" id="employee_role" name="employee_role" placeholder="Enter employee role" required>
                </div>
                <div class="form-group wide-field">
                    <label for="account_details">Account Details</label>
                    <div class="employee-form-grid">
                        <div class="form-group">
                            <label for="account_holder_name">Account Holder Name</label>
                            <input type="text" id="account_holder_name" name="account_holder_name" placeholder="Enter account holder name">
                        </div>
                        <div class="form-group">
                            <label for="bank_name">Bank Name</label>
                            <input type="text" id="bank_name" name="bank_name" placeholder="Enter bank name">
                        </div>
                        <div class="form-group">
                            <label for="account_number">Account Number</label>
                            <input type="text" id="account_number" name="account_number" placeholder="Enter account number">
                        </div>
                        <div class="form-group">
                            <label for="ifsc_code">IFSC Code</label>
                            <input type="text" id="ifsc_code" name="ifsc_code" placeholder="Enter IFSC code">
                        </div>
                        <div class="form-group">
                            <label for="mmid">MMID</label>
                            <input type="text" id="mmid" name="mmid" placeholder="Enter MMID">
                        </div>
                        <div class="form-group">
                            <label for="branch_name">Branch</label>
                            <input type="text" id="branch_name" name="branch_name" placeholder="Enter branch name">
                        </div>
                        <div class="form-group">
                            <label for="upi_id">UPI ID</label>
                            <input type="text" id="upi_id" name="upi_id" placeholder="Enter UPI ID">
                        </div>
                        <div class="form-group wide-field">
                            <label for="account_notes">Notes</label>
                            <textarea id="account_notes" name="account_notes" rows="2" placeholder="Any additional account notes"></textarea>
                        </div>
                    </div>
                </div>
                <div class="form-group wide-field sa-file-action-row">
                    <div class="form-group">
                        <label for="employee_document">Document Upload</label>
                        <input type="file" id="employee_document" name="employee_document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn-submit">Add Employee</button>
                    </div>
                </div>
            </div>
        </form>
    </article>

</section>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Reserved for future employee page interactions.
});
</script>
<?php require_once '../includes/footer.php'; ?>