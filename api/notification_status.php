<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$role = $_SESSION['role'] ?? '';
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

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
    case 'half_paid':
        if (!in_array($role, ['manager', 'receptionist'], true)) {
            respondForbidden();
        }
        $query = "SELECT b.id, p.name AS patient_name, b.net_amount, b.balance_amount, b.updated_at
                   FROM bills b
                   JOIN patients p ON b.patient_id = p.id
                   WHERE b.payment_status = 'Half Paid' AND b.bill_status != 'Void'
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

        if ($stmt = $conn->prepare("SELECT COUNT(*) AS total FROM bill_edit_requests WHERE status = 'pending'")) {
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
                                                                            AND b.payment_status IN ('Due', 'Half Paid')")) {
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
                                    WHERE bi.report_status = 'Pending' AND b.bill_status != 'Void'")) {
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
        $lastRequestId = isset($_GET['last_request_id']) ? (int)$_GET['last_request_id'] : 0;

        $stmt = $conn->prepare("SELECT ber.id, ber.bill_id, ber.reason_for_change, ber.created_at, u.username
                                 FROM bill_edit_requests ber
                                 JOIN users u ON ber.receptionist_id = u.id
                                 WHERE ber.status = 'pending'
                                 ORDER BY ber.id DESC
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
            'id' => (int)$latest['id'],
            'bill_id' => (int)$latest['bill_id'],
            'reason' => $latest['reason_for_change'],
            'created_at' => $latest['created_at'],
            'receptionist' => $latest['username']
        ];

        echo json_encode([
            'success' => true,
            'latest' => $latestPayload,
            'hasNew' => $latestPayload['id'] > $lastRequestId
        ]);
        exit;

    default:
        respondBadRequest('Unsupported action.');
}
?>
