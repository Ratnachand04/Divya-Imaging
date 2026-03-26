<?php
/**
 * API: Generate a new patient UID without inserting a row.
 * Returns the next available UID in JSON format.
 */
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

try {
    $uid = generate_patient_uid($conn);
    echo json_encode(['success' => true, 'uid' => $uid]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
