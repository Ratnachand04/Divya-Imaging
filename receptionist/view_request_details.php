<?php
$page_title = 'Request Details';
$required_role = 'receptionist';
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_bill_edit_request_workflow_schema($conn);

$receptionist_id = (int)($_SESSION['user_id'] ?? 0);

if (!isset($_GET['request_id']) || !is_numeric($_GET['request_id'])) {
    $_SESSION['feedback'] = "<div class='error-banner'>Invalid request ID.</div>";
    header('Location: requests.php');
    exit();
}

$request_id = (int)$_GET['request_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_query_response'])) {
    $query_response = trim((string)($_POST['query_response'] ?? ''));

    if ($query_response === '') {
        $_SESSION['feedback'] = "<div class='error-banner'>Please enter a response before submitting.</div>";
        header('Location: view_request_details.php?request_id=' . $request_id . '#query-response');
        exit();
    }

    $conn->begin_transaction();
    try {
        $lock_stmt = $conn->prepare('SELECT id, status FROM bill_edit_requests WHERE id = ? AND receptionist_id = ? FOR UPDATE');
        if (!$lock_stmt) {
            throw new Exception('Failed to verify request status.');
        }
        $lock_stmt->bind_param('ii', $request_id, $receptionist_id);
        $lock_stmt->execute();
        $locked = $lock_stmt->get_result()->fetch_assoc();
        $lock_stmt->close();

        if (!$locked) {
            throw new Exception('Request not found.');
        }

        $status_key = normalize_bill_edit_request_status($locked['status'] ?? 'pending');
        if ($status_key !== 'query_raised') {
            throw new Exception('Only Query Raised requests can be responded to.');
        }

        $update_stmt = $conn->prepare("UPDATE bill_edit_requests
            SET status = 'pending',
                receptionist_response = ?,
                reason_for_change = ?,
                manager_unread = 1,
                receptionist_unread = 0,
                last_receptionist_action_at = NOW(),
                updated_at = NOW()
            WHERE id = ? AND receptionist_id = ?");
        if (!$update_stmt) {
            throw new Exception('Failed to update request.');
        }
        $update_stmt->bind_param('ssii', $query_response, $query_response, $request_id, $receptionist_id);
        if (!$update_stmt->execute()) {
            $err = $update_stmt->error;
            $update_stmt->close();
            throw new Exception('Failed to update request: ' . $err);
        }
        $update_stmt->close();

        log_bill_edit_request_event(
            $conn,
            $request_id,
            'receptionist',
            $receptionist_id,
            'query_responded',
            'query_raised',
            'pending',
            $query_response
        );

        $conn->commit();
        $_SESSION['feedback'] = "<div class='success-banner'>Your response has been sent to manager. Request is now pending review.</div>";
        header('Location: view_request_details.php?request_id=' . $request_id);
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['feedback'] = "<div class='error-banner'>" . htmlspecialchars($e->getMessage()) . "</div>";
        header('Location: view_request_details.php?request_id=' . $request_id . '#query-response');
        exit();
    }
}

$request_stmt = $conn->prepare("SELECT
        r.id AS request_id,
        r.bill_id,
        r.reason_for_change,
        r.status,
        r.manager_comment,
        r.receptionist_response,
        r.created_at AS requested_at,
        r.updated_at,
        r.receptionist_unread,
        b.id AS bill_exists,
        b.created_at AS bill_created_at,
        b.gross_amount,
        b.discount,
        b.net_amount,
        p.uid AS patient_uid,
        p.name AS patient_name,
        p.age,
        p.sex
    FROM bill_edit_requests r
    LEFT JOIN bills b ON r.bill_id = b.id
    LEFT JOIN patients p ON b.patient_id = p.id
    WHERE r.id = ? AND r.receptionist_id = ?");
if (!$request_stmt) {
    die('Failed to prepare request details query: ' . $conn->error);
}
$request_stmt->bind_param('ii', $request_id, $receptionist_id);
$request_stmt->execute();
$request_details = $request_stmt->get_result()->fetch_assoc();
$request_stmt->close();

if (!$request_details) {
    $_SESSION['feedback'] = "<div class='error-banner'>Request not found.</div>";
    header('Location: requests.php');
    exit();
}

$status_key = normalize_bill_edit_request_status($request_details['status'] ?? 'pending');
$status_label = get_bill_edit_request_status_label($status_key);
$status_class = get_bill_edit_request_status_class($status_key);
$can_reply_query = ($status_key === 'query_raised');

if (!empty($request_details['receptionist_unread'])) {
    $mark_read_stmt = $conn->prepare('UPDATE bill_edit_requests SET receptionist_unread = 0 WHERE id = ? AND receptionist_id = ?');
    if ($mark_read_stmt) {
        $mark_read_stmt->bind_param('ii', $request_id, $receptionist_id);
        $mark_read_stmt->execute();
        $mark_read_stmt->close();
    }
}

$timeline_events = [];
$timeline_stmt = $conn->prepare("SELECT
        e.id,
        e.actor_role,
        e.actor_user_id,
        e.action_type,
        e.old_status,
        e.new_status,
        e.comment_text,
        e.created_at,
        u.username AS actor_name
    FROM bill_edit_request_events e
    LEFT JOIN users u ON e.actor_user_id = u.id
    WHERE e.request_id = ?
    ORDER BY e.id ASC");
if ($timeline_stmt) {
    $timeline_stmt->bind_param('i', $request_id);
    $timeline_stmt->execute();
    $timeline_events = $timeline_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $timeline_stmt->close();
}

$action_labels = [
    'request_seeded' => 'Request initialized',
    'request_created' => 'Request submitted',
    'approved_by_manager' => 'Approved by manager',
    'rejected_by_manager' => 'Rejected by manager',
    'query_raised_by_manager' => 'Query raised by manager',
    'query_responded' => 'Response submitted by receptionist',
    'completed_by_manager' => 'Completed by manager',
    'status_updated' => 'Status updated',
];

require_once '../includes/header.php';
?>

<div class="main-content page-container">
    <div class="dashboard-header">
        <div>
            <h1>Request #<?php echo (int)$request_id; ?></h1>
            <p>Track manager decision, remarks, and full request timeline.</p>
        </div>
        <a href="requests.php" class="btn-cancel">Back to Requests</a>
    </div>

    <?php if (isset($_SESSION['feedback'])): ?>
        <?php echo $_SESSION['feedback']; ?>
        <?php unset($_SESSION['feedback']); ?>
    <?php endif; ?>

    <div class="detail-section">
        <h3>Request Information</h3>
        <div class="detail-grid">
            <p><strong>Request ID:</strong> #<?php echo (int)$request_details['request_id']; ?></p>
            <p><strong>Status:</strong> <span class="<?php echo htmlspecialchars($status_class); ?>"><?php echo htmlspecialchars($status_label); ?></span></p>
            <p><strong>Bill ID:</strong> #<?php echo (int)$request_details['bill_id']; ?></p>
            <p><strong>Requested At:</strong> <?php echo date('d-m-Y h:i A', strtotime((string)$request_details['requested_at'])); ?></p>
            <p><strong>Last Updated:</strong> <?php echo date('d-m-Y h:i A', strtotime((string)$request_details['updated_at'])); ?></p>
            <p style="grid-column: 1 / -1;"><strong>Your Request:</strong><br><?php echo nl2br(htmlspecialchars((string)$request_details['reason_for_change'])); ?></p>
            <?php if (!empty($request_details['manager_comment'])): ?>
            <p style="grid-column: 1 / -1;"><strong>Manager Remarks / Query:</strong><br><?php echo nl2br(htmlspecialchars((string)$request_details['manager_comment'])); ?></p>
            <?php endif; ?>
            <?php if (!empty($request_details['receptionist_response'])): ?>
            <p style="grid-column: 1 / -1;"><strong>Your Latest Response:</strong><br><?php echo nl2br(htmlspecialchars((string)$request_details['receptionist_response'])); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="detail-section">
        <h3>Bill and Patient Context</h3>
        <?php if (!empty($request_details['bill_exists'])): ?>
            <div class="detail-grid">
                <p><strong>Patient UID:</strong> <?php echo !empty($request_details['patient_uid']) ? htmlspecialchars((string)$request_details['patient_uid']) : 'N/A'; ?></p>
                <p><strong>Patient Name:</strong> <?php echo !empty($request_details['patient_name']) ? htmlspecialchars((string)$request_details['patient_name']) : 'N/A'; ?></p>
                <p><strong>Age / Gender:</strong> <?php echo htmlspecialchars((string)($request_details['age'] ?? 'N/A')); ?> / <?php echo htmlspecialchars((string)($request_details['sex'] ?? 'N/A')); ?></p>
                <p><strong>Bill Created:</strong> <?php echo !empty($request_details['bill_created_at']) ? date('d-m-Y h:i A', strtotime((string)$request_details['bill_created_at'])) : 'N/A'; ?></p>
                <p><strong>Gross Amount:</strong> ₹<?php echo number_format((float)$request_details['gross_amount'], 2); ?></p>
                <p><strong>Discount:</strong> ₹<?php echo number_format((float)$request_details['discount'], 2); ?></p>
                <p><strong>Net Amount:</strong> ₹<?php echo number_format((float)$request_details['net_amount'], 2); ?></p>
            </div>
        <?php else: ?>
            <div class="error-banner" style="margin-bottom:0;">Associated bill or patient record is no longer available.</div>
        <?php endif; ?>
    </div>

    <?php if ($can_reply_query): ?>
    <div class="detail-section" id="query-response">
        <h3>Respond to Manager Query</h3>
        <form method="POST" action="view_request_details.php?request_id=<?php echo (int)$request_id; ?>#query-response">
            <div class="form-group">
                <label for="query_response">Clarification / Updated Reason</label>
                <textarea id="query_response" name="query_response" rows="4" required></textarea>
            </div>
            <div class="actions-container" style="justify-content:flex-start;">
                <button type="submit" name="submit_query_response" class="btn-submit">Send Response</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="detail-section">
        <h3>Request Timeline</h3>
        <?php if (!empty($timeline_events)): ?>
            <div class="table-responsive">
                <table class="data-table minimal-padding">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>Status Change</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($timeline_events as $event): ?>
                            <?php
                            $event_actor_role = trim((string)($event['actor_role'] ?? 'system'));
                            $event_actor_name = trim((string)($event['actor_name'] ?? ''));
                            $actor_label = ucfirst($event_actor_role);
                            if ($event_actor_name !== '') {
                                $actor_label .= ' (' . $event_actor_name . ')';
                            }

                            $event_action_key = trim((string)($event['action_type'] ?? 'status_updated'));
                            $event_action_label = $action_labels[$event_action_key] ?? ucwords(str_replace('_', ' ', $event_action_key));

                            $event_old = $event['old_status'] !== null ? get_bill_edit_request_status_label((string)$event['old_status']) : null;
                            $event_new = $event['new_status'] !== null ? get_bill_edit_request_status_label((string)$event['new_status']) : null;
                            ?>
                            <tr>
                                <td><?php echo date('d-m-Y h:i A', strtotime((string)$event['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($actor_label); ?></td>
                                <td><?php echo htmlspecialchars($event_action_label); ?></td>
                                <td>
                                    <?php if ($event_old !== null || $event_new !== null): ?>
                                        <?php echo htmlspecialchars((string)($event_old ?? 'N/A')); ?>
                                        <span style="color:#64748b;">to</span>
                                        <?php echo htmlspecialchars((string)($event_new ?? 'N/A')); ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo !empty($event['comment_text']) ? nl2br(htmlspecialchars((string)$event['comment_text'])) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="margin:0;">No timeline entries found for this request.</p>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
