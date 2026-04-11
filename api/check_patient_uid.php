<?php
/**
 * API: Check if a patient UID exists and return the patient data.
 * GET/POST ?uid=DC20260001
 */
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

try {
    ensure_patient_registration_schema($conn);

    $uid = strtoupper(isset($_REQUEST['uid']) ? trim((string)$_REQUEST['uid']) : '');
    if ($uid === '') {
        echo json_encode(['success' => false, 'message' => 'UID is required.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, uid, name, age, sex, address, city, mobile_number FROM patients WHERE uid = ?");
    if (!$stmt) {
        throw new Exception('Unable to prepare UID lookup query.');
    }

    $stmt->bind_param('s', $uid);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $patient = $result->fetch_assoc();
        echo json_encode(['success' => true, 'patient' => $patient]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No patient found with this UID.']);
    }

    $stmt->close();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to check UID right now. Please try again.']);
}
