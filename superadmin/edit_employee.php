<?php
$page_title = "Edit Employee";
// FIX: The required role must be set to "manager"
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$users_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'users', 'u') : '`users` u';

$sa_active_page = 'employees.php';

$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS full_name VARCHAR(120) NULL AFTER username");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS account_details TEXT NULL AFTER role");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS document_path VARCHAR(255) NULL AFTER account_details");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS employee_role VARCHAR(120) NULL AFTER full_name");

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

        return [true, 'uploads/employee_docs/' . $targetName, ''];
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

if (!function_exists('parse_employee_account_details')) {
    function parse_employee_account_details(string $details): array
    {
        $parsed = [
            'account_holder_name' => '',
            'bank_name' => '',
            'account_number' => '',
            'ifsc_code' => '',
            'mmid' => '',
            'branch_name' => '',
            'upi_id' => '',
            'account_notes' => '',
        ];

        if ($details === '') {
            return $parsed;
        }

        $labelMap = [
            'account holder' => 'account_holder_name',
            'bank name' => 'bank_name',
            'account number' => 'account_number',
            'ifsc' => 'ifsc_code',
            'mmid' => 'mmid',
            'branch' => 'branch_name',
            'upi id' => 'upi_id',
            'notes' => 'account_notes',
        ];

        $unknown = [];
        $lines = preg_split('/\r\n|\r|\n/', $details) ?: [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                $unknown[] = $line;
                continue;
            }

            $key = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if (isset($labelMap[$key])) {
                $parsed[$labelMap[$key]] = $value;
            } else {
                $unknown[] = $line;
            }
        }

        if (!empty($unknown)) {
            $parsed['account_notes'] = trim($parsed['account_notes'] . "\n" . implode("\n", $unknown));
        }

        return $parsed;
    }
}

$feedback = '';
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$user_id) {
    header("Location: employees.php");
    exit();
}

// Fetch current user data
$stmt_fetch_orig = $conn->prepare("SELECT u.username, COALESCE(NULLIF(u.full_name, ''), u.username) AS full_name, COALESCE(NULLIF(u.employee_role, ''), u.role) AS employee_role, u.role, COALESCE(u.account_details, '') AS account_details, u.document_path, u.is_active FROM {$users_source} WHERE u.id = ?");
$stmt_fetch_orig->bind_param("i", $user_id);
$stmt_fetch_orig->execute();
$original_user = $stmt_fetch_orig->get_result()->fetch_assoc();
$stmt_fetch_orig->close();

if (!$original_user) {
    die("User not found.");
}

$accountFields = parse_employee_account_details((string)($original_user['account_details'] ?? ''));

// Security: Prevent manager from editing a superadmin or another manager
if ($original_user['role'] === 'superadmin' || ($original_user['role'] === 'manager' && $_SESSION['user_id'] != $user_id)) {
     die("Forbidden: You do not have permission to edit this user.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_user_id = (int)$_POST['user_id'];
    $full_name = trim($_POST['full_name'] ?? '');
    $employee_role = trim($_POST['employee_role'] ?? '');
    $account_details = build_employee_account_details($_POST);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Prevent manager from deactivating their own account
    if ($posted_user_id === $_SESSION['user_id'] && !$is_active) {
        $feedback = "<div class='error-banner'>You cannot deactivate your own account.</div>";
    } elseif ($full_name === '' || $employee_role === '') {
        $feedback = "<div class='error-banner'>Name and role are required.</div>";
    } else {
        [$uploadOk, $newDocumentPath, $uploadMessage] = upload_employee_document($_FILES['employee_document'] ?? []);
        if (!$uploadOk) {
            $feedback = "<div class='error-banner'>" . htmlspecialchars($uploadMessage) . "</div>";
        } else {
        $documentPathToSave = $newDocumentPath ?: ($original_user['document_path'] ?? null);

        // Update employee master details
        $stmt_update = $conn->prepare("UPDATE users SET full_name = ?, employee_role = ?, account_details = ?, document_path = ?, is_active = ? WHERE id = ?");
        $stmt_update->bind_param("ssssii", $full_name, $employee_role, $account_details, $documentPathToSave, $is_active, $posted_user_id);
        $stmt_update->execute();
        $stmt_update->close();

        require_once '../includes/functions.php';
        $details = "Manager updated employee '{$full_name}' (ID: {$posted_user_id}) with role '{$employee_role}' and status " . ($is_active ? 'Active' : 'Inactive') . ".";
        log_system_action($conn, 'USER_UPDATED', $posted_user_id, $details);

        $feedback = "<div class='success-banner'>User updated successfully!</div>";
        // Refresh original user data to show new state
        $original_user = [
            'username' => $original_user['username'],
            'full_name' => $full_name,
            'employee_role' => $employee_role,
            'role' => $original_user['role'],
            'account_details' => $account_details,
            'document_path' => $documentPathToSave,
            'is_active' => $is_active
        ];
        $accountFields = parse_employee_account_details($account_details);
        }
    }
}

require_once '../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<style>
.sa-employee-edit-page { display: grid; gap: 1rem; }
.sa-employee-edit-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.sa-employee-edit-head h1 { margin: 0; color: #1e3a8a; font-size: 1.5rem; }
.sa-employee-edit-card fieldset {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.9rem;
    margin: 0 0 0.9rem;
}
.sa-employee-edit-card legend {
    color: #1e3a8a;
    font-weight: 700;
    padding: 0 0.35rem;
}
.employee-edit-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 12px;
    align-items: end;
}
.employee-edit-grid .wide-field {
    grid-column: 1 / -1;
}
.employee-edit-grid .form-group,
.sa-employee-edit-card .form-group { margin: 0; }
.employee-edit-grid label,
.sa-employee-edit-card .form-group > label {
    display: block;
    margin-bottom: 0.35rem;
    color: #475569;
    font-weight: 700;
    font-size: 0.86rem;
}
.employee-edit-grid input[type="text"],
.employee-edit-grid input[type="password"],
.employee-edit-grid input[type="file"],
.employee-edit-grid select,
.employee-edit-grid textarea {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 0.55rem 0.65rem;
    font-size: 0.92rem;
    background: #fff;
}
.employee-edit-grid textarea {
    min-height: 78px;
    resize: vertical;
}
.sa-employee-edit-card .btn-submit,
.sa-employee-edit-card .btn-cancel {
    border-radius: 999px;
    padding-left: 1rem;
    padding-right: 1rem;
}
@media (max-width: 768px) {
    .employee-edit-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<section class="sa-employee-edit-page">
    <article class="sa-employee-edit-card sa-employee-edit-head">
        <h1>Edit Employee: <?php echo htmlspecialchars($original_user['full_name'] ?? $original_user['username']); ?></h1>
    </article>

    <?php echo $feedback; ?>

    <article class="sa-employee-edit-card management-form">
        <form action="edit_employee.php?id=<?php echo $user_id; ?>" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
            <fieldset>
                <legend>User Details</legend>
                <div class="employee-edit-grid">
                    <div class="form-group"><label for="full_name">Name</label><input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($original_user['full_name'] ?? $original_user['username']); ?>" required></div>
                    <div class="form-group"><label for="employee_role">Role</label><input type="text" id="employee_role" name="employee_role" value="<?php echo htmlspecialchars($original_user['employee_role'] ?? ''); ?>" placeholder="Enter employee role" required></div>
                    <div class="form-group wide-field">
                        <label>Account Details</label>
                        <div class="employee-edit-grid">
                            <div class="form-group">
                                <label for="account_holder_name">Account Holder Name</label>
                                <input type="text" id="account_holder_name" name="account_holder_name" value="<?php echo htmlspecialchars($accountFields['account_holder_name'] ?? ''); ?>" placeholder="Enter account holder name">
                            </div>
                            <div class="form-group">
                                <label for="bank_name">Bank Name</label>
                                <input type="text" id="bank_name" name="bank_name" value="<?php echo htmlspecialchars($accountFields['bank_name'] ?? ''); ?>" placeholder="Enter bank name">
                            </div>
                            <div class="form-group">
                                <label for="account_number">Account Number</label>
                                <input type="text" id="account_number" name="account_number" value="<?php echo htmlspecialchars($accountFields['account_number'] ?? ''); ?>" placeholder="Enter account number">
                            </div>
                            <div class="form-group">
                                <label for="ifsc_code">IFSC Code</label>
                                <input type="text" id="ifsc_code" name="ifsc_code" value="<?php echo htmlspecialchars($accountFields['ifsc_code'] ?? ''); ?>" placeholder="Enter IFSC code">
                            </div>
                            <div class="form-group">
                                <label for="mmid">MMID</label>
                                <input type="text" id="mmid" name="mmid" value="<?php echo htmlspecialchars($accountFields['mmid'] ?? ''); ?>" placeholder="Enter MMID">
                            </div>
                            <div class="form-group">
                                <label for="branch_name">Branch</label>
                                <input type="text" id="branch_name" name="branch_name" value="<?php echo htmlspecialchars($accountFields['branch_name'] ?? ''); ?>" placeholder="Enter branch name">
                            </div>
                            <div class="form-group">
                                <label for="upi_id">UPI ID</label>
                                <input type="text" id="upi_id" name="upi_id" value="<?php echo htmlspecialchars($accountFields['upi_id'] ?? ''); ?>" placeholder="Enter UPI ID">
                            </div>
                            <div class="form-group wide-field">
                                <label for="account_notes">Notes</label>
                                <textarea id="account_notes" name="account_notes" rows="2" placeholder="Any additional account notes"><?php echo htmlspecialchars($accountFields['account_notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="form-group"><label for="employee_document">Upload / Replace Document</label><input type="file" id="employee_document" name="employee_document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"></div>
                    <div class="form-group">
                        <label>Current Document</label>
                        <div>
                            <?php if (!empty($original_user['document_path'])): ?>
                                <a href="<?php echo $base_url . '/' . htmlspecialchars($original_user['document_path']); ?>" target="_blank" rel="noopener">View Existing Document</a>
                            <?php else: ?>
                                <span>-</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="form-group"><label><input type="checkbox" name="is_active" value="1" <?php echo $original_user['is_active'] ? 'checked' : ''; ?>> Active</label></div>
            </fieldset>
            <button type="submit" class="btn-submit">Update User</button>
            <a href="employees.php" class="btn-cancel">Cancel</a>
        </form>
    </article>
</section>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>