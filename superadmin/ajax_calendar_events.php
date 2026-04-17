<?php
error_reporting(0);
ini_set('display_errors', 0);

$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$calendar_events_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'calendar_events', 'ce') : '`calendar_events` ce';

if (ob_get_length()) ob_clean();

header('Content-Type: application/json');

// Check if table exists first to avoid fatal errors
if (!function_exists('schema_has_table') || !schema_has_table($conn, 'calendar_events')) {
    echo json_encode([]);
    exit();
}

// Fetch all events for FullCalendar
$result = $conn->query("SELECT ce.id, ce.title, ce.event_date as start, ce.event_type FROM {$calendar_events_source}");
$events = [];

$colors = [
    'Doctor Event' => '#4e73df', // Blue
    'Company Event' => '#1cc88a', // Green
    'Holiday' => '#e74a3b', // Red
    'Birthday' => '#f6c23e', // Yellow
    'Anniversary' => '#36b9cc', // Cyan
    'Other' => '#858796' // Gray
];

while ($row = $result->fetch_assoc()) {
    $row['backgroundColor'] = $colors[$row['event_type']] ?? '#858796';
    $row['borderColor'] = $colors[$row['event_type']] ?? '#858796';
    
    // Set text color for better contrast
    if ($row['event_type'] === 'Birthday' || $row['event_type'] === 'Anniversary') {
        $row['textColor'] = '#2c3e50'; // Dark text for bright backgrounds
    } else {
        $row['textColor'] = '#ffffff'; // White text for dark backgrounds
    }
    
    $events[] = $row;
}

header('Content-Type: application/json');
echo json_encode($events);
exit();