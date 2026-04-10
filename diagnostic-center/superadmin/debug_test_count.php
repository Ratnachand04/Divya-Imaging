<?php
require_once '../includes/db_connect.php';

echo "<h2>Debug Test Count</h2>";

$start_date = '2025-01-01';
$end_date = '2025-12-30';

echo "Date Range: $start_date to $end_date<br><br>";

// 1. Check total bills in range
$sql_bills = "SELECT COUNT(*) as count FROM bills WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'";
$res_bills = $conn->query($sql_bills);
$row_bills = $res_bills->fetch_assoc();
echo "Total Bills in Date Range: " . $row_bills['count'] . "<br>";

// 2. Check total bill items linked to these bills
$sql_items = "SELECT COUNT(*) as count FROM bill_items bi 
              JOIN bills b ON bi.bill_id = b.id 
              WHERE DATE(b.created_at) BETWEEN '$start_date' AND '$end_date'";
$res_items = $conn->query($sql_items);
$row_items = $res_items->fetch_assoc();
echo "Total Bill Items in Date Range: " . $row_items['count'] . "<br>";

// 3. Check top 5 most frequent test_ids in bill_items
echo "<h3>Top 5 Frequent Test IDs in Bill Items (All Time)</h3>";
$sql_freq = "SELECT test_id, COUNT(*) as c FROM bill_items GROUP BY test_id ORDER BY c DESC LIMIT 5";
$res_freq = $conn->query($sql_freq);
while($row = $res_freq->fetch_assoc()) {
    echo "Test ID: " . $row['test_id'] . " - Count: " . $row['c'] . "<br>";
}

// 4. Check if these Test IDs exist in tests table
echo "<h3>Check Tests Table for Top IDs</h3>";
$res_freq->data_seek(0); // Reset pointer
while($row = $res_freq->fetch_assoc()) {
    $tid = $row['test_id'];
    $sql_t = "SELECT * FROM tests WHERE id = $tid";
    $res_t = $conn->query($sql_t);
    if($res_t->num_rows > 0) {
        $t_data = $res_t->fetch_assoc();
        echo "Test ID $tid found: " . $t_data['main_test_name'] . " - " . $t_data['sub_test_name'] . "<br>";
    } else {
        echo "Test ID $tid NOT FOUND in tests table!<br>";
    }
}

// 5. Run the actual query for a specific test ID if found
echo "<h3>Run Main Query for Test ID 22 (if it exists)</h3>";
$test_id = 22;
$sql_main = "SELECT 
            t.id,
            t.main_test_name, 
            t.sub_test_name, 
            COUNT(b.id) as performed_count
        FROM tests t 
        LEFT JOIN bill_items bi ON t.id = bi.test_id AND bi.item_status = 0
        LEFT JOIN bills b ON bi.bill_id = b.id 
            AND b.bill_status != 'Void'
            AND DATE(b.created_at) BETWEEN '$start_date' AND '$end_date'
        WHERE t.id = $test_id
        GROUP BY t.id";
$res_main = $conn->query($sql_main);
if($res_main && $res_main->num_rows > 0) {
    $row_main = $res_main->fetch_assoc();
    echo "Query Result for ID 22: " . $row_main['performed_count'] . " performed.<br>";
} else {
    echo "Query returned no rows for ID 22.<br>";
}

?>