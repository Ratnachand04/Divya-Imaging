<?php
$page_title = "Writer Dashboard";
$required_role = "writer";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$pending_uploads = [];
$writer_task_queue = [];
$today_task_count = 0;
$pending_task_count = 0;

$today = date('Y-m-d');
$start_date = $_GET['start_date'] ?? $today;
$end_date = $_GET['end_date'] ?? $today;
$search_term = trim($_GET['search'] ?? '');
$rows_per_page_options = [10, 20, 50, 100];
$rows_per_page_input = isset($_GET['rows_per_page']) ? (int) $_GET['rows_per_page'] : 20;
$rows_per_page = in_array($rows_per_page_input, $rows_per_page_options, true) ? $rows_per_page_input : 20;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$total_records = 0;
$total_pages = 1;
$showing_start = 0;
$showing_end = 0;
$patient_uid_expression = get_patient_identifier_expression($conn, 'p');

$validateDate = function ($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
};

if (!$validateDate($start_date)) {
    $start_date = $today;
}
if (!$validateDate($end_date)) {
    $end_date = $today;
}
if (strtotime($start_date) > strtotime($end_date)) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}

$bindParams = function ($stmt, $types, $params) {
    if (empty($types) || empty($params)) {
        return;
    }
    $bind_values = [];
    $bind_values[] = $types;
    foreach ($params as $key => $value) {
        $bind_values[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_values);
};

$taskQueueSql = "SELECT
    bi.id AS bill_item_id,
    b.id AS bill_id,
    b.created_at AS bill_created_at,
    {$patient_uid_expression} AS patient_uid,
    p.name AS patient_name,
    p.age AS patient_age,
    p.sex AS patient_sex,
    t.sub_test_name AS test_name,
    CASE WHEN DATE(b.created_at) = CURDATE() THEN 1 ELSE 0 END AS is_today
FROM bill_items bi
JOIN bills b ON bi.bill_id = b.id
JOIN patients p ON b.patient_id = p.id
JOIN tests t ON bi.test_id = t.id
WHERE b.bill_status != 'Void'
  AND COALESCE(bi.report_status, 'Pending') = 'Pending'";

$taskQueueParams = [];
$taskQueueTypes = '';
if ($search_term !== '') {
    $taskQueueLike = '%' . $search_term . '%';
    $taskQueueSql .= "
    AND ({$patient_uid_expression} LIKE ? OR CAST(b.id AS CHAR) LIKE ? OR t.sub_test_name LIKE ? OR t.main_test_name LIKE ?)";
    $taskQueueParams[] = $taskQueueLike;
    $taskQueueParams[] = $taskQueueLike;
    $taskQueueParams[] = $taskQueueLike;
    $taskQueueParams[] = $taskQueueLike;
    $taskQueueTypes .= 'ssss';
}

$taskQueueSql .= "
ORDER BY
    CASE WHEN DATE(b.created_at) = CURDATE() THEN 0 ELSE 1 END ASC,
    b.created_at ASC,
    bi.id ASC";

if ($taskQueueStmt = $conn->prepare($taskQueueSql)) {
    if (!empty($taskQueueParams)) {
        $bindParams($taskQueueStmt, $taskQueueTypes, $taskQueueParams);
    }
    $taskQueueStmt->execute();
    $taskQueueResult = $taskQueueStmt->get_result();
    if ($taskQueueResult instanceof mysqli_result) {
        while ($row = $taskQueueResult->fetch_assoc()) {
            $age = isset($row['patient_age']) ? trim((string)$row['patient_age']) : '';
            $sex = isset($row['patient_sex']) ? trim((string)$row['patient_sex']) : '';
            $isToday = (int)($row['is_today'] ?? 0) === 1;
            $createdAt = !empty($row['bill_created_at']) ? strtotime((string)$row['bill_created_at']) : false;

            $row['age_gender'] = trim($age . ($age !== '' && $sex !== '' ? ' / ' : '') . $sex);
            $row['queue_label'] = $isToday ? 'Today' : 'Pending';
            $row['created_label'] = $createdAt ? date('d M Y, h:i A', $createdAt) : '—';

            if ($isToday) {
                $today_task_count++;
            } else {
                $pending_task_count++;
            }

            $writer_task_queue[] = $row;
        }
        $taskQueueResult->free();
    }
    $taskQueueStmt->close();
}

$where_clauses = [
    "b.bill_status != 'Void'",
    "COALESCE(bi.report_status, 'Pending') = 'Pending'",
    "DATE(b.created_at) BETWEEN ? AND ?"
];
$where_params = [$start_date, $end_date];
$where_types = 'ss';

if ($search_term !== '') {
    $search_like = '%' . $search_term . '%';
    $where_clauses[] = "({$patient_uid_expression} LIKE ? OR CAST(b.id AS CHAR) LIKE ? OR t.sub_test_name LIKE ? OR t.main_test_name LIKE ?)";
    $where_params[] = $search_like;
    $where_params[] = $search_like;
    $where_params[] = $search_like;
    $where_params[] = $search_like;
    $where_types .= 'ssss';
}

$where_sql = implode(' AND ', $where_clauses);

$countSql = "SELECT COUNT(*) AS total
    FROM bill_items bi
    JOIN bills b ON bi.bill_id = b.id
    JOIN patients p ON b.patient_id = p.id
    JOIN tests t ON bi.test_id = t.id
    WHERE $where_sql";

if ($count_stmt = $conn->prepare($countSql)) {
    if (!empty($where_params)) {
        $bindParams($count_stmt, $where_types, $where_params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    if ($count_result) {
        $total_records = (int) ($count_result->fetch_assoc()['total'] ?? 0);
    }
    $count_stmt->close();
}

if ($total_records > 0) {
    $total_pages = (int) ceil($total_records / $rows_per_page);
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $rows_per_page;
    $showing_start = $offset + 1;
    $showing_end = min($total_records, $offset + $rows_per_page);
} else {
    $total_pages = 1;
    $page = 1;
    $offset = 0;
}

$dataSql = "SELECT
    bi.id AS bill_item_id,
    b.id AS bill_id,
    {$patient_uid_expression} AS patient_uid,
    p.name AS patient_name,
    p.age AS patient_age,
    p.sex AS patient_sex,
    t.sub_test_name AS test_name
    FROM bill_items bi
    JOIN bills b ON bi.bill_id = b.id
    JOIN patients p ON b.patient_id = p.id
    JOIN tests t ON bi.test_id = t.id
    WHERE $where_sql
    ORDER BY b.created_at DESC
    LIMIT ? OFFSET ?";

$data_params = $where_params;
$data_types = $where_types . 'ii';
$data_params[] = $rows_per_page;
$data_params[] = $offset;

if ($data_stmt = $conn->prepare($dataSql)) {
    $bindParams($data_stmt, $data_types, $data_params);
    $data_stmt->execute();
    $pendingResult = $data_stmt->get_result();
    if ($pendingResult instanceof mysqli_result) {
        while ($row = $pendingResult->fetch_assoc()) {
            $age = isset($row['patient_age']) ? trim((string)$row['patient_age']) : '';
            $sex = isset($row['patient_sex']) ? trim((string)$row['patient_sex']) : '';
            $row['age_gender'] = trim($age . ($age !== '' && $sex !== '' ? ' / ' : '') . $sex);
            $pending_uploads[] = $row;
        }
        $pendingResult->free();
    }
    $data_stmt->close();
}

$filter_query_params = [
    'start_date' => $start_date,
    'end_date' => $end_date,
    'search' => $search_term,
];
$pagination_query_params = $filter_query_params;
$pagination_query_params['rows_per_page'] = $rows_per_page;

require_once '../includes/header.php';
?>

<div class="main-content page-container writer-dashboard">
    <div class="dashboard-header">
        <div>
            <h1>Writer's Dashboard</h1>
            <p>Select an action to begin your reporting workflow.</p>
        </div>
        <div class="page-actions">
            <a class="btn-outline" href="templates.php">Templates</a>
        </div>
    </div>

    <div class="writer-task-panel">
        <div class="writer-task-header">
            <div>
                <h2>Current & Pending Reports</h2>
                <p>Today's report queue appears first, followed by all remaining pending reports.</p>
            </div>
            <div class="writer-task-stats" aria-label="Task counts">
                <span class="task-count-chip is-today">Today: <?php echo (int)$today_task_count; ?></span>
                <span class="task-count-chip is-pending">Pending: <?php echo (int)$pending_task_count; ?></span>
            </div>
        </div>

        <?php if (!empty($writer_task_queue)): ?>
            <div class="writer-task-table-wrapper">
                <table class="writer-task-table">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Queue</th>
                            <th>Bill #</th>
                            <th>Patient ID</th>
                            <th>Patient Name</th>
                            <th>Test Name</th>
                            <th>Age / Gender</th>
                            <th>Bill Time</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $task_serial = 1; foreach ($writer_task_queue as $task_row): ?>
                            <tr>
                                <td><?php echo $task_serial++; ?></td>
                                <td>
                                    <span class="task-queue-pill <?php echo ((int)$task_row['is_today'] === 1) ? 'is-today' : 'is-pending'; ?>">
                                        <?php echo htmlspecialchars($task_row['queue_label']); ?>
                                    </span>
                                </td>
                                <td><?php echo (int)$task_row['bill_id']; ?></td>
                                <td><span style="font-size:0.82rem;color:#666;"><?php echo htmlspecialchars($task_row['patient_uid'] ?? ''); ?></span></td>
                                <td><?php echo htmlspecialchars($task_row['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($task_row['test_name']); ?></td>
                                <td><?php echo htmlspecialchars($task_row['age_gender']); ?></td>
                                <td><?php echo htmlspecialchars($task_row['created_label']); ?></td>
                                <td>
                                    <a href="fill_report.php?item_id=<?php echo urlencode($task_row['bill_item_id']); ?>"
                                       class="btn-link">
                                        Open Word App
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="writer-task-empty-state">
                No pending reports in queue right now. New reports for today will appear here automatically.
            </div>
        <?php endif; ?>
    </div>

    <div class="writer-actions">
        <a class="writer-action-card" href="write_reports.php">
            <span class="action-icon">WR</span>
            <h2>Write Reports</h2>
            <p>Draft new diagnostic reports or continue your saved work.</p>
        </a>
        <a class="writer-action-card" href="view_reports.php">
            <span class="action-icon">VR</span>
            <h2>View Reports</h2>
            <p>Review submitted reports and track their approval status.</p>
        </a>
    </div>

    <div class="writer-upload-panel">
        <div>
            <h2>In-Site Report Workspace</h2>
            <p>Open pending reports directly in the built-in Word editor. No download or re-upload is required.</p>
        </div>

        <div class="writer-filter-bar">
            <form method="GET" action="dashboard.php" class="writer-filter-form">
                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <div class="form-group search-group">
                    <label for="universal_search">Universal Search</label>
                    <input type="text" id="universal_search" name="search" placeholder="UID, Bill #, Test name" value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
                <input type="hidden" name="rows_per_page" value="<?php echo (int) $rows_per_page; ?>">
                <input type="hidden" name="page" value="1">
                <div class="filter-actions">
                    <button type="submit" class="btn-primary">Filter</button>
                    <a href="dashboard.php" class="btn-secondary">Reset</a>
                </div>
            </form>
            <form method="GET" action="dashboard.php" class="rows-control-form">
                <?php foreach ($filter_query_params as $param_key => $param_value): ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($param_key); ?>" value="<?php echo htmlspecialchars($param_value); ?>">
                <?php endforeach; ?>
                <input type="hidden" name="page" value="1">
                <label for="rows_per_page" class="rows-label">Rows:</label>
                <select id="rows_per_page" name="rows_per_page" onchange="this.form.submit()">
                    <?php foreach ($rows_per_page_options as $option): ?>
                        <option value="<?php echo $option; ?>" <?php echo ($option === $rows_per_page) ? 'selected' : ''; ?>><?php echo $option; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ($total_records > 0): ?>
            <div class="upload-table-wrapper">
                <table class="upload-table">
                    <thead>
                        <tr>
                            <th>Bill #</th>
                            <th>Patient ID</th>
                            <th>Patient Name</th>
                            <th>Test Name</th>
                            <th>Age / Gender</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_uploads as $row): ?>
                            <tr>
                                <td><?php echo (int) $row['bill_id']; ?></td>
                                <td><span style="font-size:0.82rem;color:#666;"><?php echo htmlspecialchars($row['patient_uid'] ?? ''); ?></span></td>
                                <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['test_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['age_gender']); ?></td>
                                <td>
                                    <div class="action-stack">
                                        <a href="fill_report.php?item_id=<?php echo urlencode($row['bill_item_id']); ?>" class="btn-upload-report">
                                            Open Word App
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="writer-pagination-bar">
                <div class="pagination-info">
                    Showing <?php echo $showing_start; ?> - <?php echo $showing_end; ?> of <?php echo number_format($total_records); ?> pending item<?php echo ($total_records === 1) ? '' : 's'; ?>
                </div>
                <?php if ($total_pages > 1): ?>
                <div class="writer-pagination">
                    <?php
                    $window = 2;
                    $start_loop = max(1, $page - $window);
                    $end_loop = min($total_pages, $page + $window);
                    ?>
                    <?php if ($page > 1): ?>
                        <a class="page-link" href="<?php echo htmlspecialchars('dashboard.php?' . http_build_query(array_merge($pagination_query_params, ['page' => $page - 1]))); ?>">Prev</a>
                    <?php else: ?>
                        <span class="page-link disabled">Prev</span>
                    <?php endif; ?>

                    <?php if ($start_loop > 1): ?>
                        <a class="page-link" href="<?php echo htmlspecialchars('dashboard.php?' . http_build_query(array_merge($pagination_query_params, ['page' => 1]))); ?>">1</a>
                        <?php if ($start_loop > 2): ?><span class="page-ellipsis">…</span><?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start_loop; $i <= $end_loop; $i++): ?>
                        <?php $is_active = ($i === $page); ?>
                        <a class="page-link<?php echo $is_active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars('dashboard.php?' . http_build_query(array_merge($pagination_query_params, ['page' => $i]))); ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($end_loop < $total_pages): ?>
                        <?php if ($end_loop < $total_pages - 1): ?><span class="page-ellipsis">…</span><?php endif; ?>
                        <a class="page-link" href="<?php echo htmlspecialchars('dashboard.php?' . http_build_query(array_merge($pagination_query_params, ['page' => $total_pages]))); ?>"><?php echo $total_pages; ?></a>
                    <?php endif; ?>

                    <?php if ($page < $total_pages): ?>
                        <a class="page-link" href="<?php echo htmlspecialchars('dashboard.php?' . http_build_query(array_merge($pagination_query_params, ['page' => $page + 1]))); ?>">Next</a>
                    <?php else: ?>
                        <span class="page-link disabled">Next</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="upload-empty-state">
                No pending reports found for the selected filters. Adjust the dates or search criteria to see more items.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>