<?php
$page_title = "Receptionist Dashboard";
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/header.php';
?>

<div class="dashboard-container">
    <h1>Receptionist Dashboard</h1>
    <p>From here you can manage patient billing and view history.</p>
    <div class="dashboard-actions">
        <a href="generate_bill.php" class="action-card">
            <h2>Generate New Bill</h2>
            <p>Create a new bill for an incoming patient.</p>
        </a>
        <a href="bill_history.php" class="action-card">
            <h2>Bill History</h2>
            <p>View and manage previously generated bills.</p>
        </a>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
