<?php
$page_title = "Edit Patient";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';

$patient_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error_message = '';
$success_message = '';
$patient = null;

if ($patient_id <= 0) {
    $error_message = 'Invalid patient id.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $patient_id > 0) {
    $patient_name = trim($_POST['patient_name'] ?? '');
    $patient_age = isset($_POST['patient_age']) ? (int)$_POST['patient_age'] : -1;
    $patient_sex = trim($_POST['patient_sex'] ?? '');
    $patient_mobile = trim($_POST['patient_mobile'] ?? '');
    $patient_address = trim($_POST['patient_address'] ?? '');
    $patient_city = trim($_POST['patient_city'] ?? '');

    if ($patient_name === '' || $patient_age < 0 || $patient_mobile === '') {
        $error_message = 'Name, age and mobile are required.';
    } elseif (!in_array($patient_sex, ['Male', 'Female', 'Other'], true)) {
        $error_message = 'Please choose a valid gender.';
    } elseif (!preg_match('/^\d{10}$/', $patient_mobile)) {
        $error_message = 'Mobile number must be exactly 10 digits.';
    } else {
        $stmt_update = $conn->prepare("UPDATE patients SET name = ?, age = ?, sex = ?, mobile_number = ?, address = ?, city = ? WHERE id = ?");
        if (!$stmt_update) {
            $error_message = 'Unable to prepare patient update.';
        } else {
            $stmt_update->bind_param('sissssi', $patient_name, $patient_age, $patient_sex, $patient_mobile, $patient_address, $patient_city, $patient_id);
            if ($stmt_update->execute()) {
                $success_message = 'Patient details updated successfully.';
                log_system_action($conn, 'PATIENT_UPDATED', $patient_id, 'Updated patient details from Manager Existing Patients.');
            } else {
                $error_message = 'Update failed: ' . $stmt_update->error;
            }
            $stmt_update->close();
        }
    }
}

if ($error_message === '' && $patient_id > 0) {
    $stmt = $conn->prepare("SELECT p.id, p.uid, p.name, p.age, p.sex, p.mobile_number, p.address, p.city FROM {$patients_source} WHERE p.id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $patient_id);
        $stmt->execute();
        $patient = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$patient) {
        $error_message = 'Patient not found.';
    }
}

require_once '../includes/header.php';
?>

<div class="form-container">
    <h1>Edit Patient</h1>

    <?php if ($error_message): ?>
        <div class="error-banner"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="success-banner"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <?php if ($patient): ?>
        <p><strong>Patient ID:</strong> <?php echo htmlspecialchars($patient['uid']); ?></p>

        <form method="POST" action="edit_patient.php?id=<?php echo (int)$patient['id']; ?>">
            <fieldset>
                <legend>Patient Details</legend>

                <div class="form-row">
                    <div class="form-group">
                        <label for="patient_name">Name</label>
                        <input type="text" id="patient_name" name="patient_name" value="<?php echo htmlspecialchars($patient['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="patient_age">Age</label>
                        <input type="number" id="patient_age" name="patient_age" min="0" value="<?php echo (int)$patient['age']; ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="patient_sex">Gender</label>
                        <select id="patient_sex" name="patient_sex" required>
                            <option value="Male" <?php echo ($patient['sex'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($patient['sex'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                            <option value="Other" <?php echo ($patient['sex'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="patient_mobile">Mobile</label>
                        <input type="text" id="patient_mobile" name="patient_mobile" pattern="\d{10}" maxlength="10" inputmode="numeric" value="<?php echo htmlspecialchars($patient['mobile_number'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="patient_city">City</label>
                        <input type="text" id="patient_city" name="patient_city" value="<?php echo htmlspecialchars($patient['city'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="patient_address">Address</label>
                    <textarea id="patient_address" name="patient_address" rows="3"><?php echo htmlspecialchars($patient['address'] ?? ''); ?></textarea>
                </div>

                <div class="actions-cell">
                    <button type="submit" class="btn-submit">Save Changes</button>
                    <a href="existing_patients.php" class="btn-action">Back</a>
                </div>
            </fieldset>
        </form>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
