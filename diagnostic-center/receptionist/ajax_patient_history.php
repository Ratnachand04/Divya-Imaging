<?php
/**
 * AJAX endpoint to fetch patient visit history and scans performed
 */
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/patient_registration_helper.php';

header('Content-Type: application/json');

function log_history_lookup_event($message, $context = []) {
    $safe_context = json_encode($context, JSON_UNESCAPED_SLASHES);
    error_log('[patient_history_lookup] ' . $message . ' | context=' . $safe_context);
}

try {
    ensure_patient_registration_schema($conn);

    $raw_patient_id = trim((string)($_GET['patient_id'] ?? ''));
    $patient_unique_id = normalize_patient_unique_id($_GET['patient_unique_id'] ?? '');

    if ($patient_unique_id === '' && $raw_patient_id !== '') {
        $patient_unique_id = normalize_patient_unique_id($raw_patient_id);
    }

    log_history_lookup_event('request_received', [
        'raw_patient_id' => $raw_patient_id,
        'patient_unique_id' => $patient_unique_id,
        'user_id' => $_SESSION['user_id'] ?? null
    ]);

    if ($patient_unique_id === '') {
        throw new Exception('Patient ID is required.');
    }

    if (!is_valid_patient_unique_id($patient_unique_id)) {
        throw new Exception('Patient ID must be in DCYYYYNNNN format.');
    }

    $stmt = $conn->prepare(
        "SELECT id, patient_unique_id, name FROM patients WHERE patient_unique_id = ? AND (is_archived = 0 OR is_archived IS NULL) LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception('Failed to prepare patient query: ' . $conn->error);
    }

    $stmt->bind_param('s', $patient_unique_id);

    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$patient) {
        log_history_lookup_event('patient_not_found', [
            'raw_patient_id' => $raw_patient_id,
            'patient_unique_id' => $patient_unique_id
        ]);
        throw new Exception('Patient not found.');
    }

    $patient_id = (int)$patient['id'];

    log_history_lookup_event('patient_resolved', [
        'resolved_patient_id' => $patient_id,
        'resolved_patient_unique_id' => $patient['patient_unique_id'] ?? null
    ]);

    // Get visit count (total bills)
    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT id) as visit_count
         FROM bills
         WHERE patient_id = ? AND bill_status != 'Void'"
    );
    if (!$stmt) {
        throw new Exception('Failed to prepare visit count query: ' . $conn->error);
    }

    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $visit_result = $stmt->get_result()->fetch_assoc();
    $visit_count = $visit_result['visit_count'] ?? 0;
    $stmt->close();

    // Get all scans/tests performed with visit details
    $stmt = $conn->prepare(
        "SELECT 
            b.id as bill_id,
            b.created_at as visit_date,
            t.main_test_name,
            t.sub_test_name,
            bi.report_status,
            bi.created_at as test_date
         FROM bills b
         INNER JOIN bill_items bi ON b.id = bi.bill_id
         INNER JOIN tests t ON bi.test_id = t.id
         WHERE b.patient_id = ? AND b.bill_status != 'Void' AND bi.item_status = 0
         ORDER BY b.created_at DESC, t.main_test_name"
    );

    if (!$stmt) {
        throw new Exception('Failed to prepare tests query: ' . $conn->error);
    }

    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $tests_result = $stmt->get_result();
    
    $visits = [];
    $all_scans = [];
    
    while ($row = $tests_result->fetch_assoc()) {
        $bill_id = $row['bill_id'];
        
        // Group by visit/bill
        if (!isset($visits[$bill_id])) {
            $visits[$bill_id] = [
                'bill_id' => $bill_id,
                'visit_date' => $row['visit_date'],
                'tests' => []
            ];
        }
        
        $test_info = [
            'main_test_name' => $row['main_test_name'],
            'sub_test_name' => $row['sub_test_name'],
            'report_status' => $row['report_status'],
            'test_date' => $row['test_date']
        ];
        
        $visits[$bill_id]['tests'][] = $test_info;
        
        // Collect all unique scans
        $scan_key = $row['main_test_name'] . '_' . ($row['sub_test_name'] ?? '');
        $all_scans[$scan_key] = [
            'main_test_name' => $row['main_test_name'],
            'sub_test_name' => $row['sub_test_name']
        ];
    }
    
    $stmt->close();

    // Re-index arrays and sort
    $visits_array = array_values($visits);
    $scans_array = array_values($all_scans);

    echo json_encode([
        'success' => true,
        'patient' => $patient,
        'visit_count' => $visit_count,
        'visits' => $visits_array,
        'all_scans' => $scans_array,
        'total_unique_scans' => count($scans_array)
    ]);

} catch (Exception $e) {
    log_history_lookup_event('request_failed', [
        'error' => $e->getMessage()
    ]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
