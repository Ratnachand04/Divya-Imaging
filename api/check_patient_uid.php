<?php
/**
 * API: Check if a patient UID exists and return the patient data.
 * GET/POST ?uid=DC20260001
 */
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

ensure_patient_registration_schema($conn);

$uid = isset($_REQUEST['uid']) ? trim($_REQUEST['uid']) : '';

if ($uid === '') {
    echo json_encode(['success' => false, 'message' => 'UID is required.']);
    exit;
}

$stmt = $conn->prepare("SELECT id, uid, name, age, sex, address, city, mobile_number FROM patients WHERE uid = ?");
$stmt->bind_param('s', $uid);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $patient = $result->fetch_assoc();
    echo json_encode(['success' => true, 'patient' => $patient]);
} else {
    echo json_encode(['success' => false, 'message' => 'No patient found with this UID.']);
}

$stmt->close();
