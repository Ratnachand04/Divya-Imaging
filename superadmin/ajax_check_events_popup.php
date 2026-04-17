<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$calendar_events_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'calendar_events', 'ce') : '`calendar_events` ce';

if (ob_get_length()) ob_clean();

header('Content-Type: application/json');

// Fetch all events with optional scope filtering
$scope = isset($_GET['scope']) ? strtolower(trim($_GET['scope'])) : '';
$events = [];

$baseQuery = "SELECT ce.id, ce.title, ce.event_date AS start, DATE_FORMAT(ce.event_date, '%Y-%m-%d') AS event_date, ce.event_type, ce.details FROM {$calendar_events_source}";

if ($scope === 'dashboard') {
    // Only current month events that are today or upcoming
    $dashboardQuery = $baseQuery . " WHERE YEAR(ce.event_date) = YEAR(CURDATE()) AND MONTH(ce.event_date) = MONTH(CURDATE()) AND ce.event_date >= CURDATE() ORDER BY ce.event_date ASC, ce.title ASC";
    if ($result = $conn->query($dashboardQuery)) {
        $events = $result->fetch_all(MYSQLI_ASSOC);
    }
} else {
    $defaultQuery = $baseQuery . " ORDER BY ce.event_date DESC";
    if ($result = $conn->query($defaultQuery)) {
        $events = $result->fetch_all(MYSQLI_ASSOC);
    }
}

echo json_encode($events);
exit();
