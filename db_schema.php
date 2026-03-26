<?php
require_once 'includes/db_connect.php';

$tables = [];
$result = $conn->query("SHOW TABLES");
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}

foreach ($tables as $table) {
    echo "Table: $table\n";
    $cols = $conn->query("DESCRIBE $table");
    while ($col = $cols->fetch_assoc()) {
        echo "  " . $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    echo "\n";
}
?>