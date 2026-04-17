<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$role = $_SESSION['role'] ?? '';
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

ensure_bill_edit_request_workflow_schema($conn);

function respondForbidden(): void {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

function respondBadRequest(string $message): void {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

switch ($action) {
    case 'partial_paid':
        if (!in_array($role, ['manager', 'receptionist'], true)) {
            respondForbidden();
        }
        $query = "SELECT b.id, p.name AS patient_name, b.net_amount, b.balance_amount, b.updated_at
                   FROM bills b
                   JOIN patients p ON b.patient_id = p.id
                   WHERE b.bill_status != 'Void'
                     AND b.amount_paid > 0.01
                                         AND ROUND(GREATEST(b.net_amount - COALESCE(b.amount_paid, 0), 0), 2) > 0.01
                   ORDER BY b.updated_at DESC
                   LIMIT 10";
        $result = $conn->query($query);
        $bills = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $bills[] = [
                    'id' => (int)$row['id'],
                    'patient_name' => $row['patient_name'],
                    'net_amount' => (float)$row['net_amount'],
                    'balance_amount' => (float)$row['balance_amount'],
                    'updated_at' => $row['updated_at']
                ];
            }
            $result->free();
        }
        echo json_encode([
            'success' => true,
            'data' => [
                'count' => count($bills),
                'bills' => $bills
            ]
        ]);
        exit;

    case 'manager_nav_counts':
        if ($role !== 'manager') {
            respondForbidden();
        }
        $counts = [
            'requests' => 0,
            'pending_bills' => 0,
            'pending_reports' => 0
        ];

        if ($stmt = $conn->prepare("SELECT COUNT(*) AS total FROM bill_edit_requests WHERE manager_unread = 1")) {
            $stmt->execute();
            $stmt->bind_result($total);
            if ($stmt->fetch()) {
                $counts['requests'] = (int)$total;
            }
            $stmt->close();
        }

                if ($stmt = $conn->prepare("SELECT COUNT(DISTINCT b.id) AS total
                                                                        FROM bills b
                                                                        WHERE b.bill_status != 'Void'
                                                                            AND ROUND(GREATEST(b.net_amount - COALESCE(b.amount_paid, 0), 0), 2) > 0.01")) {
            $stmt->execute();
            $stmt->bind_result($total);
            if ($stmt->fetch()) {
                $counts['pending_bills'] = (int)$total;
            }
            $stmt->close();
        }

                if ($stmt = $conn->prepare("SELECT COUNT(*) AS total
                                                                        FROM bill_items bi
                                                                        JOIN bills b ON bi.bill_id = b.id
                                                                        JOIN tests t ON t.id = bi.test_id
                                                                        WHERE COALESCE(bi.report_status, 'Pending') = 'Completed'
                                                                            AND b.bill_status != 'Void'")) {
            $stmt->execute();
            $stmt->bind_result($total);
            if ($stmt->fetch()) {
                $counts['pending_reports'] = (int)$total;
            }
            $stmt->close();
        }

        echo json_encode(['success' => true, 'counts' => $counts]);
        exit;

    case 'latest_request':
        if ($role !== 'manager') {
            respondForbidden();
        }
        $lastEventId = isset($_GET['last_event_id']) ? (int)$_GET['last_event_id'] : 0;

        $stmt = $conn->prepare("SELECT
                                    e.id AS event_id,
                                    ber.id AS request_id,
                                    ber.bill_id,
                                    ber.status,
                                    ber.reason_for_change,
                                    ber.receptionist_response,
                                    e.created_at,
                                    u.username
                                 FROM bill_edit_request_events e
                                 JOIN bill_edit_requests ber ON ber.id = e.request_id
                                 JOIN users u ON ber.receptionist_id = u.id
                                 WHERE ber.manager_unread = 1
                                   AND e.actor_role = 'receptionist'
                                 ORDER BY e.id DESC
                                 LIMIT 1");
        if (!$stmt) {
            respondBadRequest('Failed to prepare request lookup.');
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $latest = $result->fetch_assoc();
        $stmt->close();

        if (!$latest) {
            echo json_encode(['success' => true, 'latest' => null, 'hasNew' => false]);
            exit;
        }

        $latestPayload = [
            'id' => (int)$latest['request_id'],
            'event_id' => (int)$latest['event_id'],
            'bill_id' => (int)$latest['bill_id'],
            'status' => get_bill_edit_request_status_label($latest['status'] ?? 'pending'),
            'reason' => !empty($latest['receptionist_response']) ? $latest['receptionist_response'] : $latest['reason_for_change'],
            'created_at' => $latest['created_at'],
            'receptionist' => $latest['username']
        ];

        echo json_encode([
            'success' => true,
            'latest' => $latestPayload,
            'hasNew' => $latestPayload['event_id'] > $lastEventId
        ]);
        exit;

    case 'receptionist_nav_counts':
        if ($role !== 'receptionist') {
            respondForbidden();
        }

        $counts = [
            'request_updates' => 0,
        ];

        $receptionistId = (int)($_SESSION['user_id'] ?? 0);
        if ($stmt = $conn->prepare("SELECT COUNT(*) AS total
                                    FROM bill_edit_requests
                                    WHERE receptionist_id = ?
                                      AND receptionist_unread = 1")) {
            $stmt->bind_param('i', $receptionistId);
            $stmt->execute();
            $stmt->bind_result($total);
            if ($stmt->fetch()) {
                $counts['request_updates'] = (int)$total;
            }
            $stmt->close();
        }

        echo json_encode(['success' => true, 'counts' => $counts]);
        exit;

    case 'latest_receptionist_update':
        if ($role !== 'receptionist') {
            respondForbidden();
        }

        $lastEventId = isset($_GET['last_event_id']) ? (int)$_GET['last_event_id'] : 0;
        $receptionistId = (int)($_SESSION['user_id'] ?? 0);

        $stmt = $conn->prepare("SELECT
                                    e.id AS event_id,
                                    ber.id AS request_id,
                                    ber.bill_id,
                                    ber.status,
                                    ber.manager_comment,
                                    e.created_at,
                                    u.username AS manager_name
                                 FROM bill_edit_request_events e
                                 JOIN bill_edit_requests ber ON ber.id = e.request_id
                                 LEFT JOIN users u ON e.actor_user_id = u.id
                                 WHERE ber.receptionist_id = ?
                                   AND ber.receptionist_unread = 1
                                   AND e.actor_role = 'manager'
                                 ORDER BY e.id DESC
                                 LIMIT 1");
        if (!$stmt) {
            respondBadRequest('Failed to prepare receptionist update lookup.');
        }
        $stmt->bind_param('i', $receptionistId);
        $stmt->execute();
        $result = $stmt->get_result();
        $latest = $result->fetch_assoc();
        $stmt->close();

        if (!$latest) {
            echo json_encode(['success' => true, 'latest' => null, 'hasNew' => false]);
            exit;
        }

        $latestPayload = [
            'id' => (int)$latest['request_id'],
            'event_id' => (int)$latest['event_id'],
            'bill_id' => (int)$latest['bill_id'],
            'status' => get_bill_edit_request_status_label($latest['status'] ?? 'pending'),
            'manager_comment' => (string)($latest['manager_comment'] ?? ''),
            'created_at' => (string)($latest['created_at'] ?? ''),
            'manager_name' => (string)($latest['manager_name'] ?? 'Manager')
        ];

        echo json_encode([
            'success' => true,
            'latest' => $latestPayload,
            'hasNew' => $latestPayload['event_id'] > $lastEventId
        ]);
        exit;

    default:
        respondBadRequest('Unsupported action.');
}
?>
