<?php
// This script generates a CSV file for download and has no HTML output.
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$referral_doctors_source_lookup = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd_lookup') : '`referral_doctors` rd_lookup';
$tests_source_lookup = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't_lookup') : '`tests` t_lookup';
$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';
$referral_doctors_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd') : '`referral_doctors` rd';

// --- Get filter parameters from the URL, identical to analytics.php ---
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$referral_type = isset($_GET['referral_type']) ? $_GET['referral_type'] : 'all';
$doctor_id = isset($_GET['doctor_id']) && $_GET['doctor_id'] !== 'all' ? (int)$_GET['doctor_id'] : 'all';
$main_test = isset($_GET['main_test']) ? $_GET['main_test'] : 'all';
$sub_test_id = isset($_GET['sub_test']) && $_GET['sub_test'] !== 'all' ? (int)$_GET['sub_test'] : 'all';

// --- NEW: Build a list of active filters for the report header ---
$active_filters = [];
if ($referral_type !== 'all') {
    $active_filters[] = ["Filter Type" => "Referral Type", "Value" => ucfirst($referral_type)];
}
if ($doctor_id !== 'all') {
    $stmt_doc = $conn->prepare("SELECT rd_lookup.doctor_name FROM {$referral_doctors_source_lookup} WHERE rd_lookup.id = ?");
    $stmt_doc->bind_param("i", $doctor_id);
    $stmt_doc->execute();
    $doc_name = $stmt_doc->get_result()->fetch_assoc()['doctor_name'] ?? 'N/A';
    $active_filters[] = ["Filter Type" => "Doctor", "Value" => "Dr. " . $doc_name];
    $stmt_doc->close();
}
if ($main_test !== 'all') {
    $active_filters[] = ["Filter Type" => "Test Category", "Value" => $main_test];
}
if ($sub_test_id !== 'all') {
    $stmt_test = $conn->prepare("SELECT t_lookup.sub_test_name FROM {$tests_source_lookup} WHERE t_lookup.id = ?");
    $stmt_test->bind_param("i", $sub_test_id);
    $stmt_test->execute();
    $test_name = $stmt_test->get_result()->fetch_assoc()['sub_test_name'] ?? 'N/A';
    $active_filters[] = ["Filter Type" => "Specific Test", "Value" => $test_name];
    $stmt_test->close();
}
// --- END of new filter logic ---


// --- Query Building ---
$query = "
    SELECT 
        b.id as bill_id, 
        p.name as patient_name, 
        b.created_at, 
        b.gross_amount,
        b.discount,
        b.net_amount,
        b.referral_type, 
        b.referral_source_other, 
        rd.doctor_name, 
        GROUP_CONCAT(t.sub_test_name SEPARATOR ', ') as tests
    FROM {$bills_source}
    JOIN {$patients_source} ON b.patient_id = p.id
    JOIN {$bill_items_source} ON b.id = bi.bill_id
    JOIN {$tests_source} ON bi.test_id = t.id
    LEFT JOIN {$referral_doctors_source} ON b.referral_doctor_id = rd.id
    WHERE b.created_at BETWEEN ? AND ?
";
$params = [$start_date, $end_date . ' 23:59:59'];
$types = 'ss';

// Append additional filters if they are provided
if ($referral_type !== 'all') { $query .= " AND b.referral_type = ?"; $params[] = $referral_type; $types .= 's'; }
if ($doctor_id !== 'all') { $query .= " AND b.referral_doctor_id = ?"; $params[] = $doctor_id; $types .= 'i'; }
if ($main_test !== 'all') { $query .= " AND t.main_test_name = ?"; $params[] = $main_test; $types .= 's'; }
if ($sub_test_id !== 'all') { $query .= " AND t.id = ?"; $params[] = $sub_test_id; $types .= 'i'; }

// Always add the GROUP BY and ORDER BY clauses
$query .= " GROUP BY b.id ORDER BY b.id DESC";

// Prepare the statement
$stmt = $conn->prepare($query);
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

// Bind the parameters and execute
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// --- Set HTTP headers to trigger a file download ---
$filename = "diagnostic_analytics_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// --- Open the output stream and write the CSV data ---
$output = fopen('php://output', 'w');

// Add the date range to the top of the CSV file
$formatted_start = date('d-M-Y', strtotime($start_date));
$formatted_end = date('d-M-Y', strtotime($end_date));
fputcsv($output, ["Report for Period:", $formatted_start . " to " . $formatted_end]);
fputcsv($output, []); // Add a blank row for spacing

// --- NEW: Add the active filters to the CSV ---
if (!empty($active_filters)) {
    fputcsv($output, ["Filters Applied:"]);
    foreach ($active_filters as $filter) {
        fputcsv($output, [$filter['Filter Type'], $filter['Value']]);
    }
    fputcsv($output, []); // Add another blank row for spacing
}
// --- END of new filter output ---

// Add the main data header row to the CSV file
fputcsv($output, ['Bill ID', 'Patient Name', 'Date', 'Referred By', 'Tests Performed', 'Gross Amount', 'Discount', 'Net Amount']);

// Loop through the database results and write each row to the CSV
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $referred_by = 'Self'; // Default
        if ($row['referral_type'] == 'Doctor' && !empty($row['doctor_name'])) {
            $referred_by = 'Dr. ' . $row['doctor_name'];
        } elseif ($row['referral_type'] == 'Other') {
            $referred_by = 'Other (' . $row['referral_source_other'] . ')';
        }

        fputcsv($output, [
            $row['bill_id'],
            $row['patient_name'],
            date('d-m-Y', strtotime($row['created_at'])),
            $referred_by,
            $row['tests'],
            $row['gross_amount'],
            $row['discount'],
            $row['net_amount']
        ]);
    }
}

fclose($output);
$stmt->close();
exit();
?>