<?php
$required_role = 'accountant';
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: view_expenses.php');
    exit();
}

$expense_id = isset($_POST['expense_id']) ? (int) $_POST['expense_id'] : 0;
$delete_reason = isset($_POST['delete_reason']) ? trim($_POST['delete_reason']) : '';

if ($expense_id <= 0) {
    header('Location: view_expenses.php?status=error&message=' . urlencode('Invalid expense selected.'));
    exit();
}

if ($delete_reason === '' || mb_strlen($delete_reason) < 5) {
    header('Location: view_expenses.php?status=error&message=' . urlencode('Please provide a reason of at least 5 characters.'));
    exit();
}

$expense_stmt = $conn->prepare('SELECT id, expense_type, amount, status, proof_path FROM expenses WHERE id = ?');
$expense_stmt->bind_param('i', $expense_id);
$expense_stmt->execute();
$expense_result = $expense_stmt->get_result();
$expense = $expense_result->fetch_assoc();
$expense_stmt->close();

if (!$expense) {
    header('Location: view_expenses.php?status=error&message=' . urlencode('Expense record not found.'));
    exit();
}

$delete_stmt = $conn->prepare('DELETE FROM expenses WHERE id = ?');
$delete_stmt->bind_param('i', $expense_id);

if ($delete_stmt->execute()) {
    $delete_stmt->close();

    if (!empty($expense['proof_path'])) {
        $relative_path = ltrim(str_replace(['..\\', '../'], '', $expense['proof_path']), '/');
        $absolute_path = realpath(dirname(__DIR__) . '/' . $relative_path);
        if ($absolute_path && strpos($absolute_path, realpath(dirname(__DIR__))) === 0 && file_exists($absolute_path)) {
            @unlink($absolute_path);
        }
    }

    $details = sprintf(
        'Expense #%d (%s, ₹%0.2f, %s) deleted. Reason: %s',
        $expense['id'],
        $expense['expense_type'],
        $expense['amount'],
        $expense['status'],
        $delete_reason
    );
    log_system_action($conn, 'EXPENSE_DELETED', $expense['id'], $details);

    header('Location: view_expenses.php?status=success&message=' . urlencode('Expense deleted successfully.'));
    exit();
}

$delete_stmt->close();
header('Location: view_expenses.php?status=error&message=' . urlencode('Unable to delete the expense. Please try again.'));
exit();
