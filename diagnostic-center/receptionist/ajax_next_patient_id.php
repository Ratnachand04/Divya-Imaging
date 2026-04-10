<?php
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/patient_registration_helper.php';

header('Content-Type: application/json');

try {
    ensure_patient_registration_schema($conn);
    $next_id = generate_next_patient_unique_id($conn);
    echo json_encode(['success' => true, 'patient_unique_id' => $next_id]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
