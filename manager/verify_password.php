<?php
// manager/verify_password.php

header('Content-Type: application/json');
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

// Ensure this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Get the password from the POST request
$data = json_decode(file_get_contents('php://input'), true);
$submitted_password = $data['password'] ?? '';

if (empty($submitted_password)) {
    echo json_encode(['success' => false, 'message' => 'Password not provided.']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch the hashed password from the database for the current user
$stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit();
}

// Verify the submitted password against the stored hash
if (password_verify($submitted_password, $user['password'])) {
    // Passwords match
    echo json_encode(['success' => true]);
} else {
    // Passwords do not match
    echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
}
?>