<?php
// Turn off error reporting for production to prevent HTML errors breaking JSON
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$referral_doctors_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd') : '`referral_doctors` rd';
$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';

// Ensure no output has been sent yet
if (ob_get_length()) ob_clean();

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !isset($_GET['date'])) {
        throw new Exception('Invalid request');
    }

    $date = $_GET['date'];
    // Validate date format Y-m-d
    if (!DateTime::createFromFormat('Y-m-d', $date)) {
        throw new Exception('Invalid date format');
    }

    $start_time = $date . ' 00:00:00';
    $end_time = $date . ' 23:59:59';

    $response = [
        'success' => true,
        'date' => date('d M Y', strtotime($date)),
        'bills' => [],
        'tests' => [],
        'summary' => [
            'revenue' => 0,
            'patients' => 0,
            'tests' => 0
        ]
    ];

    // Fetch Bills
    $query = "
        SELECT 
            b.id, 
            p.name AS patient_name, 
            b.net_amount, 
            b.payment_mode,
            rd.doctor_name
        FROM {$bills_source}
        JOIN {$patients_source} ON b.patient_id = p.id
        LEFT JOIN {$referral_doctors_source} ON b.referral_doctor_id = rd.id
        WHERE b.created_at BETWEEN ? AND ? AND b.bill_status != 'Void'
        ORDER BY b.created_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    $stmt->bind_param('ss', $start_time, $end_time);
    if (!$stmt->execute()) {
        throw new Exception("Execution error: " . $stmt->error);
    }
    
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $response['bills'][] = [
            'id' => $row['id'],
            'patient' => $row['patient_name'],
            'amount' => (float)$row['net_amount'],
            'mode' => $row['payment_mode'] ?: 'Cash',
            'doctor' => $row['doctor_name'] ?: 'Self'
        ];
        $response['summary']['revenue'] += (float)$row['net_amount'];
    }
    $stmt->close();

    $response['summary']['patients'] = count($response['bills']);

    // Fetch Top Tests for the day
    $queryTests = "
        SELECT 
            COALESCE(CONCAT_WS(' - ', t.main_test_name, NULLIF(t.sub_test_name, '')), 'Uncategorized') AS test_name,
            COUNT(*) as count
        FROM {$bill_items_source}
        JOIN {$bills_source} ON bi.bill_id = b.id
        LEFT JOIN {$tests_source} ON bi.test_id = t.id
        WHERE b.created_at BETWEEN ? AND ? AND b.bill_status != 'Void' AND bi.item_status = 0
        GROUP BY test_name
        ORDER BY count DESC
        LIMIT 5
    ";
    
    $stmt = $conn->prepare($queryTests);
    if ($stmt) {
        $stmt->bind_param('ss', $start_time, $end_time);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $response['tests'][] = [
                'name' => $row['test_name'],
                'count' => (int)$row['count']
            ];
            $response['summary']['tests'] += (int)$row['count'];
        }
        $stmt->close();
    }

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
