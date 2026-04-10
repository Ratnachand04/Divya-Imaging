<?php
// api/verify_manager_password.php

session_start();
require_once '../includes/db_connect.php'; // Path is correct as per your file structure
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}
if (!isset($_POST['password'])) {
    echo json_encode(['success' => false, 'message' => 'Password not provided.']);
    exit();
}

$manager_id = $_SESSION['user_id'];
$submitted_password = $_POST['password'];

try {
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND role = 'manager'");
    $stmt->bind_param("i", $manager_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Manager account not found.']);
        exit();
    }

    $hashed_password = $user['password'];

    if (password_verify($submitted_password, $hashed_password)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
    }

} catch (Exception $e) {
    error_log("Password verification error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
}
?>