<?php
$page_title = "Patient Details";
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/patient_registration_helper.php';

$error_message = '';

try {
    ensure_patient_registration_schema($conn);
} catch (Exception $schema_error) {
    $error_message = "Patient module initialization failed: " . $schema_error->getMessage();
}

$search = trim($_GET['search'] ?? '');
$patients = [];

try {
    if ($search !== '') {
        $search_like = '%' . $search . '%';
        $stmt_search = $conn->prepare(
            "SELECT id, patient_unique_id, name, age, sex, mobile_number, address, referring_doctor_name, referring_doctor_contact, emergency_contact_person
             FROM patients
              WHERE (is_archived = 0 OR is_archived IS NULL) AND (name LIKE ? OR mobile_number LIKE ? OR patient_unique_id LIKE ?)
             ORDER BY id DESC
             LIMIT 100"
        );
        $stmt_search->bind_param("sss", $search_like, $search_like, $search_like);
    } else {
        $stmt_search = $conn->prepare(
            "SELECT id, patient_unique_id, name, age, sex, mobile_number, address, referring_doctor_name, referring_doctor_contact, emergency_contact_person
             FROM patients
              WHERE is_archived = 0 OR is_archived IS NULL
             ORDER BY id DESC
             LIMIT 100"
        );
    }

    if ($stmt_search) {
        $stmt_search->execute();
        $patients_result = $stmt_search->get_result();
        while ($row = $patients_result->fetch_assoc()) {
            $patients[] = $row;
        }
        $stmt_search->close();
    }
} catch (Exception $search_error) {
    if ($error_message === '') {
        $error_message = 'Could not load patient list: ' . $search_error->getMessage();
    }
}

require_once '../includes/header.php';
?>

<div class="form-container">
    <h1>Patient Details</h1>
    <?php if ($error_message): ?><div class="error-banner"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <p>Only existing patient records are shown here. New patient creation is handled from Generate Bill using the New Patient checkbox.</p>
</div>

<div class="table-container">
    <h2>Search Existing Patients</h2>
    <form method="GET" action="patient_registration.php" class="date-filter-form">
        <div class="form-group">
            <label for="search">Search by Name / Mobile / Patient ID</label>
            <input type="text" id="search" name="search" placeholder="e.g., DC20250001 or 9876543210" value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <button type="submit" class="btn-submit">Search</button>
    </form>

    <div class="table-responsive">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Patient ID</th>
                    <th>Name</th>
                    <th>Age/Gender</th>
                    <th>Mobile</th>
                    <th>Ref. Doctor</th>
                    <th>Emergency Contact</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($patients)): ?>
                    <?php foreach ($patients as $patient): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($patient['patient_unique_id'] ?: ('P-' . $patient['id'])); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($patient['name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($patient['address'] ?? ''); ?></small>
                            </td>
                            <td><?php echo (int)$patient['age']; ?> / <?php echo htmlspecialchars($patient['sex']); ?></td>
                            <td><?php echo htmlspecialchars($patient['mobile_number'] ?? '-'); ?></td>
                            <td>
                                <?php echo htmlspecialchars($patient['referring_doctor_name'] ?: '-'); ?><br>
                                <small><?php echo htmlspecialchars($patient['referring_doctor_contact'] ?: ''); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($patient['emergency_contact_person'] ?: '-'); ?></td>
                            <td class="actions-cell">
                                <a class="btn-action btn-edit" href="edit_patient.php?id=<?php echo (int)$patient['id']; ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align:center;">No patients found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>