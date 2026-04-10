<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

if (ob_get_length()) ob_clean();

header('Content-Type: application/json');

// Fetch all events with optional scope filtering
$scope = isset($_GET['scope']) ? strtolower(trim($_GET['scope'])) : '';
$events = [];

$baseQuery = "SELECT id, title, event_date AS start, DATE_FORMAT(event_date, '%Y-%m-%d') AS event_date, event_type, details FROM calendar_events";

if ($scope === 'dashboard') {
    // Only current month events that are today or upcoming
    $dashboardQuery = $baseQuery . " WHERE YEAR(event_date) = YEAR(CURDATE()) AND MONTH(event_date) = MONTH(CURDATE()) AND event_date >= CURDATE() ORDER BY event_date ASC, title ASC";
    if ($result = $conn->query($dashboardQuery)) {
        $events = $result->fetch_all(MYSQLI_ASSOC);
    }
} else {
    $defaultQuery = $baseQuery . " ORDER BY event_date DESC";
    if ($result = $conn->query($defaultQuery)) {
        $events = $result->fetch_all(MYSQLI_ASSOC);
    }
}

echo json_encode($events);
exit();
