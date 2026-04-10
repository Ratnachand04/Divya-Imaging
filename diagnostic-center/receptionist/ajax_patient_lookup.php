<?php
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/patient_registration_helper.php';

header('Content-Type: application/json');

try {
    ensure_patient_registration_schema($conn);

    $raw_patient_id = trim((string)($_GET['patient_unique_id'] ?? ''));
    if ($raw_patient_id === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Patient ID is required.']);
        exit;
    }

    $patient_unique_id = normalize_patient_unique_id($raw_patient_id);
    if (!is_valid_patient_unique_id($patient_unique_id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Patient ID must be in DCYYYYNNNN format.']);
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT id, patient_unique_id, name, age, sex, address, city, mobile_number, emergency_contact_person
         FROM patients
            WHERE patient_unique_id = ? AND (is_archived = 0 OR is_archived IS NULL)
         LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception('Failed to prepare lookup query.');
    }

    $stmt->bind_param('s', $patient_unique_id);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$patient) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Patient not found for this ID.']);
        exit;
    }

    echo json_encode(['success' => true, 'patient' => $patient]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
