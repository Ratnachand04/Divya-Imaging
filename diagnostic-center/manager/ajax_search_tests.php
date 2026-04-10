<?php
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

header('Content-Type: application/json');

$term = isset($_GET['term']) ? trim($_GET['term']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : 'all';
$results = [];

if (!empty($term)) {
    $search_param = "%{$term}%";
    
    // Build the query dynamically
    $query = "SELECT DISTINCT sub_test_name FROM tests WHERE sub_test_name LIKE ?";
    $params = [$search_param];
    $types = 's';

    if ($category !== 'all') {
        $query .= " AND main_test_name = ?";
        $params[] = $category;
        $types .= 's';
    }
    
    $query .= " ORDER BY sub_test_name LIMIT 10";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $results[] = $row;
    }
    $stmt->close();
}

echo json_encode($results);
exit();