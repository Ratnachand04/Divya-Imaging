<?php
$page_title = "Edit Patient";
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/patient_registration_helper.php';

$error_message = '';
$success_message = '';

try {
    ensure_patient_registration_schema($conn);
} catch (Exception $schema_error) {
    $error_message = "Patient module initialization failed: " . $schema_error->getMessage();
}

$patient_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($patient_id <= 0) {
    $error_message = 'Invalid patient ID.';
}

$patient = null;
if ($error_message === '') {
    $stmt = $conn->prepare("SELECT id, patient_unique_id, name, age, sex, mobile_number, address, referring_doctor_name, referring_doctor_contact, medical_history, emergency_contact_person FROM patients WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $patient = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$patient) {
        $error_message = 'Patient record not found.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error_message === '') {
    $patient_name = trim($_POST['patient_name'] ?? '');
    $patient_age = isset($_POST['patient_age']) ? (int)$_POST['patient_age'] : -1;
    $patient_gender = trim($_POST['patient_gender'] ?? '');
    $patient_mobile = trim($_POST['patient_mobile'] ?? '');
    $patient_address = trim($_POST['patient_address'] ?? '');
    $referring_doctor_name = trim($_POST['referring_doctor_name'] ?? '');
    $referring_doctor_contact = trim($_POST['referring_doctor_contact'] ?? '');
    $medical_history = trim($_POST['medical_history'] ?? '');
    $emergency_contact_person = trim($_POST['emergency_contact_person'] ?? '');
    $name_regex = '/^[A-Za-z][A-Za-z .\'\-]{1,99}$/';

    $allowed_genders = ['Male', 'Female', 'Other'];
    if ($patient_name === '' || $patient_age < 0 || !in_array($patient_gender, $allowed_genders, true) || $patient_mobile === '' || $patient_address === '' || $emergency_contact_person === '') {
        $error_message = 'Please fill all required fields: name, age, gender, mobile, address, and emergency contact person.';
    } elseif (!preg_match($name_regex, $patient_name)) {
        $error_message = 'Patient name can contain only letters, spaces, dot, apostrophe, and hyphen.';
    } elseif (!preg_match('/^\d{10,15}$/', $patient_mobile)) {
        $error_message = 'Patient mobile number must be between 10 and 15 digits.';
    } elseif ($referring_doctor_name !== '' && !preg_match($name_regex, $referring_doctor_name)) {
        $error_message = 'Referring doctor name can contain only letters, spaces, dot, apostrophe, and hyphen.';
    } elseif ($referring_doctor_contact !== '' && !preg_match('/^\d{10,15}$/', $referring_doctor_contact)) {
        $error_message = 'Referring doctor contact must be between 10 and 15 digits.';
    } elseif (!preg_match($name_regex, $emergency_contact_person)) {
        $error_message = 'Emergency contact can contain only letters, spaces, dot, apostrophe, and hyphen.';
    } else {
        $stmt_update = $conn->prepare(
            "UPDATE patients
             SET name = ?, age = ?, sex = ?, mobile_number = ?, address = ?, referring_doctor_name = ?, referring_doctor_contact = ?, medical_history = ?, emergency_contact_person = ?
             WHERE id = ?"
        );

        if ($stmt_update) {
            $stmt_update->bind_param(
                "sisssssssi",
                $patient_name,
                $patient_age,
                $patient_gender,
                $patient_mobile,
                $patient_address,
                $referring_doctor_name,
                $referring_doctor_contact,
                $medical_history,
                $emergency_contact_person,
                $patient_id
            );

            if ($stmt_update->execute()) {
                $success_message = 'Patient details updated successfully.';
                log_system_action($conn, 'PATIENT_UPDATED', $patient_id, "Updated patient {$patient_name}.");

                $patient['name'] = $patient_name;
                $patient['age'] = $patient_age;
                $patient['sex'] = $patient_gender;
                $patient['mobile_number'] = $patient_mobile;
                $patient['address'] = $patient_address;
                $patient['referring_doctor_name'] = $referring_doctor_name;
                $patient['referring_doctor_contact'] = $referring_doctor_contact;
                $patient['medical_history'] = $medical_history;
                $patient['emergency_contact_person'] = $emergency_contact_person;
            } else {
                $error_message = 'Update failed: ' . $stmt_update->error;
            }
            $stmt_update->close();
        } else {
            $error_message = 'Could not prepare update: ' . $conn->error;
        }
    }
}

require_once '../includes/header.php';
?>

<div class="form-container">
    <h1>Edit Patient Details</h1>
    <?php if ($error_message): ?><div class="error-banner"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="success-banner"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <?php if ($patient): ?>
        <div class="patient-id-badge">
            <strong>Patient ID:</strong> <?php echo htmlspecialchars($patient['patient_unique_id'] ?: ('P-' . $patient['id'])); ?>
        </div>

        <form action="edit_patient.php?id=<?php echo (int)$patient['id']; ?>" method="POST">
            <fieldset>
                <legend>Patient Information</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label for="patient_name">Patient Name</label>
                        <input type="text" id="patient_name" name="patient_name" value="<?php echo htmlspecialchars($patient['name']); ?>" required pattern="[A-Za-z][A-Za-z .'-]{1,99}" title="Name should contain letters only (plus space, dot, apostrophe, hyphen)" oninput="this.value=this.value.replace(/[^A-Za-z .'-]/g,'').replace(/\s{2,}/g,' ')" onkeypress="if(!/[A-Za-z .'-]/.test(event.key)){event.preventDefault();}">
                    </div>
                    <div class="form-group">
                        <label for="patient_age">Age</label>
                        <input type="number" id="patient_age" name="patient_age" min="0" value="<?php echo (int)$patient['age']; ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="patient_gender">Gender</label>
                        <select id="patient_gender" name="patient_gender" required>
                            <option value="Male" <?php echo ($patient['sex'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($patient['sex'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                            <option value="Other" <?php echo ($patient['sex'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="patient_mobile">Mobile Number</label>
                        <input type="text" id="patient_mobile" name="patient_mobile" maxlength="15" value="<?php echo htmlspecialchars($patient['mobile_number'] ?? ''); ?>" required pattern="\d{10,15}" inputmode="numeric" title="Mobile number should be 10 to 15 digits" oninput="this.value=this.value.replace(/\D/g,'').slice(0,15)" onkeypress="if(!/[0-9]/.test(event.key)){event.preventDefault();}">
                    </div>
                    <div class="form-group">
                        <label for="referring_doctor_name">Referring Doctor Name</label>
                        <input type="text" id="referring_doctor_name" name="referring_doctor_name" value="<?php echo htmlspecialchars($patient['referring_doctor_name'] ?? ''); ?>" pattern="[A-Za-z][A-Za-z .'-]{1,99}" title="Doctor name should contain letters only (plus space, dot, apostrophe, hyphen)" oninput="this.value=this.value.replace(/[^A-Za-z .'-]/g,'').replace(/\s{2,}/g,' ')" onkeypress="if(!/[A-Za-z .'-]/.test(event.key)){event.preventDefault();}">
                    </div>
                    <div class="form-group">
                        <label for="referring_doctor_contact">Referring Doctor Contact</label>
                        <input type="text" id="referring_doctor_contact" name="referring_doctor_contact" maxlength="15" value="<?php echo htmlspecialchars($patient['referring_doctor_contact'] ?? ''); ?>" pattern="\d{10,15}" inputmode="numeric" title="Contact number should be 10 to 15 digits" oninput="this.value=this.value.replace(/\D/g,'').slice(0,15)" onkeypress="if(!/[0-9]/.test(event.key)){event.preventDefault();}">
                    </div>
                </div>

                <div class="form-group">
                    <label for="patient_address">Address</label>
                    <textarea id="patient_address" name="patient_address" rows="2" required><?php echo htmlspecialchars($patient['address'] ?? ''); ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="emergency_contact_person">Emergency Contact Person</label>
                        <input type="text" id="emergency_contact_person" name="emergency_contact_person" value="<?php echo htmlspecialchars($patient['emergency_contact_person'] ?? ''); ?>" required pattern="[A-Za-z][A-Za-z .'-]{1,99}" title="Emergency contact should contain letters only (plus space, dot, apostrophe, hyphen)" oninput="this.value=this.value.replace(/[^A-Za-z .'-]/g,'').replace(/\s{2,}/g,' ')" onkeypress="if(!/[A-Za-z .'-]/.test(event.key)){event.preventDefault();}">
                    </div>
                    <div class="form-group">
                        <label for="medical_history">Medical History (Optional)</label>
                        <textarea id="medical_history" name="medical_history" rows="2"><?php echo htmlspecialchars($patient['medical_history'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="actions-cell">
                    <button type="submit" class="btn-submit">Update Patient</button>
                    <a href="existing_patients.php" class="btn-action">Back to Existing Patients</a>
                </div>
            </fieldset>
        </form>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>