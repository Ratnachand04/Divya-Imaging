<?php
// 1. Include security and database connection
require_once '../includes/auth_check.php'; // Ensures only a manager can run this
require_once '../includes/db_connect.php';

// 2. Check if the bill_item_id was sent from the form
if (isset($_POST['bill_item_id'])) {
    
    $bill_item_id = (int)$_POST['bill_item_id'];

    if ($bill_item_id > 0) {
        // 3. Prepare a secure DELETE statement
        // This deletes only the single row from the 'bill_items' table
        $stmt = $conn->prepare("DELETE FROM bill_items WHERE id = ?");
        
        if ($stmt === false) {
            die("Error preparing the delete query: " . $conn->error);
        }

        // 4. Bind the ID and execute
        $stmt->bind_param("i", $bill_item_id);
        $stmt->execute();
        $stmt->close();
    }
}

// 5. Redirect back to the analytics page
// This line sends the user right back to the analytics page they were just on,
// with all their filters (date, doctor, etc.) still active.
header('Location: ' . $_SERVER['HTTP_REFERER']);
exit;

?>