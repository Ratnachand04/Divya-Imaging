<?php
$page_title = "View Request Details";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$bill_edit_requests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_edit_requests', 'r') : '`bill_edit_requests` r';
$users_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'users', 'u') : '`users` u';
$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';

if (!isset($_GET['request_id']) || !is_numeric($_GET['request_id'])) {
    $_SESSION['feedback'] = "<div class='error-banner'>Invalid request ID.</div>";
    header("Location: requests.php");
    exit();
}

$request_id = (int)$_GET['request_id'];

// Fetch Request Details along with Bill, Patient, and Receptionist Info
$stmt_req = $conn->prepare("SELECT
                                r.id as request_id, r.bill_id, r.reason_for_change, r.created_at as requested_at, r.status,
                                u.username AS receptionist_name,
                                b.gross_amount, b.discount, b.net_amount, b.created_at as bill_created_at,
                                p.name as patient_name, p.age, p.sex
                      FROM {$bill_edit_requests_source}
                      JOIN {$users_source} ON r.receptionist_id = u.id
                      JOIN {$bills_source} ON r.bill_id = b.id
                      JOIN {$patients_source} ON b.patient_id = p.id
                           WHERE r.id = ?"); //
$stmt_req->bind_param("i", $request_id); //
$stmt_req->execute(); //
$request_details = $stmt_req->get_result()->fetch_assoc(); //
$stmt_req->close(); //

if (!$request_details) {
    $_SESSION['feedback'] = "<div class='error-banner'>Request not found.</div>";
    header("Location: requests.php");
    exit();
}

// Fetch Bill Items
$bill_id = $request_details['bill_id']; //
$items_stmt = $conn->prepare(
    "SELECT t.main_test_name, t.sub_test_name, t.price
    FROM {$bill_items_source}
    JOIN {$tests_source} ON bi.test_id = t.id
     WHERE bi.bill_id = ?"
); //
$items_stmt->bind_param("i", $bill_id); //
$items_stmt->execute(); //
$items_result = $items_stmt->get_result(); //
$bill_items = $items_result->fetch_all(MYSQLI_ASSOC); //
$items_stmt->close(); //

require_once '../includes/header.php'; //
?>



<div class="page-container">
    <div class="dashboard-header">
        <h1>Details for Bill Edit Request #<?php echo $request_id; ?></h1>
        <p>Review the bill information and the reason for the edit request below.</p>
    </div>

    <div class="detail-section">
        <h3>Request Information</h3>
        <div class="detail-grid">
            <p><strong>Request ID:</strong> <?php echo $request_details['request_id']; ?></p>
            <p><strong>Current Status:</strong> <span class="status-<?php echo strtolower($request_details['status']); ?>"><?php echo ucfirst($request_details['status']); ?></span></p>
            <p><strong>Receptionist:</strong> <?php echo htmlspecialchars($request_details['receptionist_name']); ?></p>
            <p><strong>Requested At:</strong> <?php echo date('d-m-Y H:i A', strtotime($request_details['requested_at'])); ?></p>
            <p style="grid-column: 1 / -1;"><strong>Reason for Change:</strong> <?php echo nl2br(htmlspecialchars($request_details['reason_for_change'])); ?></p>
        </div>
    </div>

    <div class="detail-section">
        <h3>Bill & Patient Details</h3>
        <div class="detail-grid">
            <p><strong>Bill ID:</strong> <?php echo $request_details['bill_id']; ?></p>
            <p><strong>Bill Created:</strong> <?php echo date('d-m-Y H:i A', strtotime($request_details['bill_created_at'])); ?></p>
            <p><strong>Patient Name:</strong> <?php echo htmlspecialchars($request_details['patient_name']); ?></p>
            <p><strong>Age/Gender:</strong> <?php echo $request_details['age']; ?> / <?php echo $request_details['sex']; ?></p>
            <p><strong>Gross Amount:</strong> ₹ <?php echo number_format($request_details['gross_amount'], 2); ?></p>
            <p><strong>Discount:</strong> ₹ <?php echo number_format($request_details['discount'], 2); ?></p>
            <p><strong>Net Amount:</strong> ₹ <?php echo number_format($request_details['net_amount'], 2); ?></p>
        </div>
    </div>

    <div class="detail-section">
        <h3>Tests Included</h3>
        <?php if (!empty($bill_items)): ?>
            <table class="data-table minimal-padding">
                <thead><tr><th>Test Name</th><th>Price (₹)</th></tr></thead>
                <tbody>
                    <?php foreach($bill_items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['main_test_name'] . ' - ' . $item['sub_test_name']); ?></td>
                        <td style="text-align: right;"><?php echo number_format($item['price'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No test items found for this bill.</p>
        <?php endif; ?>
    </div>

    <?php // Only show actions if the request is still pending ?>
    <?php if ($request_details['status'] === 'pending'): ?>
        <div class="actions-container">
            <form method="POST" action="reject_request.php" style="display:inline;">
                <input type="hidden" name="request_id" value="<?php echo $request_id; ?>">
                <button type="submit" class="btn-cancel" onclick="return confirm('Are you sure you want to reject this edit request?');">Reject Request</button>
            </form>

            <form method="POST" action="delete_bill.php" style="display:inline;">
                <input type="hidden" name="bill_id" value="<?php echo $bill_id; ?>">
                <button type="submit" class="btn-action btn-delete" onclick="return confirm('WARNING: Are you sure you want to PERMANENTLY DELETE Bill #<?php echo $bill_id; ?> and all related records? This cannot be undone.');">Delete Bill Permanently</button>
            </form>
        </div>
    <?php else: ?>
        <p>This request has already been processed (Status: <?php echo ucfirst($request_details['status']); ?>).</p>
        <a href="requests.php" class="btn-cancel">Back to Requests</a>
    <?php endif; ?>

</div>

<?php require_once '../includes/footer.php'; ?>