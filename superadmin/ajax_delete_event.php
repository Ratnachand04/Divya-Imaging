<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (ob_get_length()) ob_clean();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_id'])) {
    $event_id = (int)$_POST['event_id'];

    $delete_table = function_exists('table_scale_find_physical_table_by_id')
        ? table_scale_find_physical_table_by_id($conn, 'calendar_events', $event_id)
        : 'calendar_events';
    if (!$delete_table || (function_exists('table_scale_is_safe_identifier') && !table_scale_is_safe_identifier($delete_table))) {
        $delete_table = 'calendar_events';
    }
    $delete_table_sql = function_exists('table_scale_quote_identifier')
        ? table_scale_quote_identifier($delete_table)
        : '`' . str_replace('`', '', $delete_table) . '`';
    
    $stmt = $conn->prepare("DELETE FROM {$delete_table_sql} WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
exit();
