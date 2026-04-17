<?php
$page_title = "Saved Bills";
$required_role = "writer";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$status_options = function_exists('writer_saved_bills_status_options')
    ? writer_saved_bills_status_options()
    : [
        'completed' => 'Completed',
        'needs_changes' => 'Needs Changes',
        'needs_approval' => 'Needs Approval',
    ];

$flash_success = '';
$flash_error = '';
if (!empty($_SESSION['writer_saved_bills_success'])) {
    $flash_success = (string)$_SESSION['writer_saved_bills_success'];
    unset($_SESSION['writer_saved_bills_success']);
}
if (!empty($_SESSION['writer_saved_bills_error'])) {
    $flash_error = (string)$_SESSION['writer_saved_bills_error'];
    unset($_SESSION['writer_saved_bills_error']);
}

try {
    if (function_exists('ensure_writer_saved_bills_stage_table')) {
        ensure_writer_saved_bills_stage_table($conn);
    }
} catch (Throwable $e) {
    if ($flash_error === '') {
        $flash_error = 'Saved Bills staging is unavailable right now. Please try again in a moment.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    $bill_item_id = isset($_POST['bill_item_id']) ? (int)$_POST['bill_item_id'] : 0;
    $updated_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

    if ($bill_item_id <= 0) {
        $_SESSION['writer_saved_bills_error'] = 'Invalid saved bill selected.';
        header('Location: saved_bills.php');
        exit();
    }

    if ($action === 'update_status') {
        $bookmark_status = function_exists('writer_saved_bills_normalize_status')
            ? writer_saved_bills_normalize_status($_POST['bookmark_status'] ?? '')
            : 'completed';

        $stmt = $conn->prepare('UPDATE writer_saved_bills_stage SET bookmark_status = ?, updated_by = ?, updated_at = NOW() WHERE bill_item_id = ?');
        if (!$stmt) {
            $_SESSION['writer_saved_bills_error'] = 'Unable to update saved bill status right now.';
        } else {
            $stmt->bind_param('sii', $bookmark_status, $updated_by, $bill_item_id);
            if ($stmt->execute()) {
                $_SESSION['writer_saved_bills_success'] = 'Saved bill status updated.';
            } else {
                $_SESSION['writer_saved_bills_error'] = 'Unable to update saved bill status right now.';
            }
            $stmt->close();
        }
    } elseif ($action === 'send_to_manager') {
        $bill_items_write_table = 'bill_items';
        if (function_exists('table_scale_find_physical_table_by_id')) {
            $resolved_table = table_scale_find_physical_table_by_id($conn, 'bill_items', $bill_item_id, 'id');
            if (is_string($resolved_table) && preg_match('/^[A-Za-z0-9_]+$/', $resolved_table)) {
                $bill_items_write_table = $resolved_table;
            }
        }

        $send_stmt = $conn->prepare("UPDATE `{$bill_items_write_table}` SET report_status = 'Completed', updated_at = NOW() WHERE id = ?");
        if (!$send_stmt) {
            $_SESSION['writer_saved_bills_error'] = 'Unable to send this report to manager right now.';
        } else {
            $send_stmt->bind_param('i', $bill_item_id);
            if ($send_stmt->execute()) {
                $send_stmt->close();

                $stage_delete = $conn->prepare('DELETE FROM writer_saved_bills_stage WHERE bill_item_id = ?');
                if ($stage_delete) {
                    $stage_delete->bind_param('i', $bill_item_id);
                    $stage_delete->execute();
                    $stage_delete->close();
                }

                $_SESSION['writer_saved_bills_success'] = 'Report sent to manager successfully.';
            } else {
                $_SESSION['writer_saved_bills_error'] = 'Unable to send this report to manager right now.';
                $send_stmt->close();
            }
        }
    } else {
        $_SESSION['writer_saved_bills_error'] = 'Invalid action requested.';
    }

    header('Location: saved_bills.php');
    exit();
}

$patient_uid_expression = get_patient_identifier_expression($conn, 'p');
$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';

$saved_rows = [];
if ($flash_error === '') {
    $sql = "SELECT
                s.bill_item_id,
                s.bookmark_status,
                b.id AS bill_id,
                {$patient_uid_expression} AS patient_uid,
                p.name AS patient_name,
                t.main_test_name,
                t.sub_test_name
            FROM writer_saved_bills_stage s
            JOIN {$bill_items_source} ON bi.id = s.bill_item_id
            JOIN {$bills_source} ON b.id = bi.bill_id
            JOIN {$patients_source} ON p.id = b.patient_id
            JOIN {$tests_source} ON t.id = bi.test_id
            WHERE b.bill_status != 'Void'
              AND COALESCE(TRIM(bi.report_docx_path), '') != ''
              AND COALESCE(TRIM(bi.report_content), '') != ''
              AND COALESCE(bi.report_status, 'Pending') = 'Pending'
            ORDER BY s.updated_at DESC, s.id DESC
            LIMIT 500";

    $result = $conn->query($sql);
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $status_key = function_exists('writer_saved_bills_normalize_status')
                ? writer_saved_bills_normalize_status($row['bookmark_status'] ?? '')
                : 'completed';

            $main_name = trim((string)($row['main_test_name'] ?? ''));
            $sub_name = trim((string)($row['sub_test_name'] ?? ''));
            $test_label = $main_name;
            if ($sub_name !== '' && strcasecmp($sub_name, $main_name) !== 0) {
                $test_label .= ' - ' . $sub_name;
            }

            $saved_rows[] = [
                'bill_item_id' => (int)$row['bill_item_id'],
                'bill_id' => (int)$row['bill_id'],
                'patient_uid' => (string)($row['patient_uid'] ?? ''),
                'patient_name' => (string)($row['patient_name'] ?? ''),
                'test_name' => $test_label !== '' ? $test_label : '-',
                'bookmark_status' => $status_key,
            ];
        }
        $result->free();
    } else {
        $flash_error = 'Unable to load saved bills right now. Please refresh and try again.';
    }
}

require_once '../includes/header.php';
?>

<div class="main-content page-container writer-reports-view writer-saved-bills-page">
    <div class="dashboard-header">
        <div>
            <h1>Saved Bills</h1>
            <p class="description">This is your staging area for saved report documents before sending them to manager.</p>
        </div>
        <div class="page-actions">
            <a class="btn-secondary" href="dashboard.php">Back to Dashboard</a>
        </div>
    </div>

    <?php if ($flash_success !== ''): ?>
        <div class="reports-alert"><?php echo htmlspecialchars($flash_success); ?></div>
    <?php endif; ?>

    <?php if ($flash_error !== ''): ?>
        <div class="reports-alert is-warning"><?php echo htmlspecialchars($flash_error); ?></div>
    <?php endif; ?>

    <?php if ($flash_error === '' && empty($saved_rows)): ?>
        <div class="reports-placeholder">
            No saved bills in staging yet. Click Save Document in the Word app to place reports here.
        </div>
    <?php elseif ($flash_error === ''): ?>
        <div class="report-table-card">
            <h2>Saved Bills Staging Queue</h2>
            <div class="report-table-wrapper">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Patient ID</th>
                            <th>Bill No</th>
                            <th>Patient Name</th>
                            <th>Test Name</th>
                            <th>Report Status</th>
                            <th>Send to Manager</th>
                            <th>Print / Download</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($saved_rows as $row): ?>
                            <tr>
                                <td><span style="font-size:0.82rem;color:#666;"><?php echo htmlspecialchars($row['patient_uid']); ?></span></td>
                                <td><?php echo (int)$row['bill_id']; ?></td>
                                <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['test_name']); ?></td>
                                <td>
                                    <form method="POST" class="bookmark-status-form">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="bill_item_id" value="<?php echo (int)$row['bill_item_id']; ?>">
                                        <select name="bookmark_status" class="bookmark-select" aria-label="Report bookmark status">
                                            <?php foreach ($status_options as $status_key => $status_label): ?>
                                                <option value="<?php echo htmlspecialchars($status_key); ?>" <?php echo ($row['bookmark_status'] === $status_key) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($status_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn-timeline">Update</button>
                                    </form>
                                </td>
                                <td>
                                    <form method="POST" class="send-manager-form">
                                        <input type="hidden" name="action" value="send_to_manager">
                                        <input type="hidden" name="bill_item_id" value="<?php echo (int)$row['bill_item_id']; ?>">
                                        <button type="submit" class="btn-view-report">Send to Manager</button>
                                    </form>
                                </td>
                                <td>
                                    <div class="saved-bills-actions">
                                        <a class="btn-view-report" href="../templates/print_report.php?item_id=<?php echo urlencode((string)$row['bill_item_id']); ?>&print=1" target="_blank" rel="noopener">Print</a>
                                        <a class="btn-view-report" href="../templates/print_report.php?item_id=<?php echo urlencode((string)$row['bill_item_id']); ?>&download=1">Download</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
body.role-writer .writer-saved-bills-page {
    padding: 3rem;
    display: flex;
    flex-direction: column;
    gap: 2rem;
}

body.role-writer .bookmark-status-form {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

body.role-writer .bookmark-select {
    border: 1px solid rgba(233, 30, 99, 0.25);
    border-radius: 999px;
    min-height: 34px;
    padding: 0.28rem 0.75rem;
    background: #fff;
    color: #6f274d;
    font-weight: 600;
}

body.role-writer .bookmark-select:focus {
    outline: 2px solid rgba(233, 30, 99, 0.22);
    outline-offset: 1px;
}

body.role-writer .send-manager-form {
    margin: 0;
}

body.role-writer .saved-bills-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

body.role-writer .saved-bills-actions .btn-view-report,
body.role-writer .send-manager-form .btn-view-report {
    white-space: nowrap;
}
</style>

<?php require_once '../includes/footer.php'; ?>
