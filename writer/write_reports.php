<?php
$page_title = "Write Reports";
$required_role = "writer";
require_once '../includes/auth_check.php';
require_once '../includes/header.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$selected_main_test = isset($_GET['main_test']) ? trim($_GET['main_test']) : 'all';
$search_term = isset($_GET['q']) ? trim($_GET['q']) : '';

$main_tests = [];
$main_test_stmt = $conn->query("SELECT DISTINCT main_test_name FROM tests ORDER BY main_test_name ASC");
if ($main_test_stmt instanceof mysqli_result) {
    while ($row = $main_test_stmt->fetch_assoc()) {
        if (!empty($row['main_test_name'])) {
            $main_tests[] = $row['main_test_name'];
        }
    }
    $main_test_stmt->free();
}

$show_main_test_column = ($selected_main_test === 'all' || $selected_main_test === '');
$patient_uid_expression = get_patient_identifier_expression($conn, 'p');

$report_rows = [];

$filters = ["DATE(b.created_at) BETWEEN ? AND ?", "b.bill_status != 'Void'", "COALESCE(bi.report_status, 'Pending') = 'Pending'"];
$params = [$start_date, $end_date];
$types = 'ss';

if (!$show_main_test_column) {
    $filters[] = "t.main_test_name = ?";
    $params[] = $selected_main_test;
    $types .= 's';
}

if ($search_term !== '') {
    $filters[] = "({$patient_uid_expression} LIKE ? OR CAST(b.id AS CHAR) LIKE ? OR t.sub_test_name LIKE ? OR t.main_test_name LIKE ?)";
    $likeTerm = "%{$search_term}%";
    $params[] = $likeTerm;
    $params[] = $likeTerm;
    $params[] = $likeTerm;
    $params[] = $likeTerm;
    $types .= 'ssss';
}

$sql = "SELECT
            bi.id AS bill_item_id,
            b.id AS bill_id,
            b.created_at AS bill_date,
            {$patient_uid_expression} AS patient_uid,
            p.name AS patient_name,
            p.age AS patient_age,
            p.sex AS patient_sex,
            t.main_test_name,
            t.sub_test_name
        FROM bill_items bi
        JOIN bills b ON bi.bill_id = b.id
        JOIN patients p ON b.patient_id = p.id
        JOIN tests t ON bi.test_id = t.id
        WHERE " . implode(' AND ', $filters) . "
        ORDER BY b.created_at DESC, bi.id DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $age = isset($row['patient_age']) ? trim((string)$row['patient_age']) : '';
        $sex = isset($row['patient_sex']) ? trim((string)$row['patient_sex']) : '';
        $age_gender = trim($age . ($age !== '' && $sex !== '' ? ' / ' : '') . $sex);

        $report_rows[] = [
            'bill_item_id' => $row['bill_item_id'],
            'bill_no' => $row['bill_id'],
            'patient_uid' => $row['patient_uid'],
            'patient_name' => $row['patient_name'],
            'age_gender' => $age_gender,
            'main_test' => $row['main_test_name'],
            'subtest' => $row['sub_test_name'],
        ];
    }
    $stmt->close();
}
?>

<div class="main-content page-container writer-workspace">
    <div class="dashboard-header">
        <div>
            <h1>Report Authoring</h1>
            <p class="description">Filter pending examinations and open the Word editor to begin drafting.</p>
        </div>
        <div class="page-actions">
            <a class="btn-secondary" href="dashboard.php">Back to Dashboard</a>
        </div>
    </div>

    <div class="filter-panel">
        <form id="report-filter-form" method="GET" class="filter-grid">
            <div class="form-group">
                <label for="start_date">Start Date</label>
                <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div class="form-group">
                <label for="end_date">End Date</label>
                <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <div class="form-group">
                <label for="main_test">Main Test</label>
                <select id="main_test" name="main_test">
                    <option value="all" <?php echo $show_main_test_column ? 'selected' : ''; ?>>All Tests</option>
                    <?php foreach ($main_tests as $category): ?>
                        <option value="<?php echo htmlspecialchars($category); ?>" <?php echo ($selected_main_test === $category) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="search_term">Search</label>
                <input type="text" id="search_term" name="q" placeholder="UID, bill number, or test name" value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-primary">Apply</button>
            </div>
        </form>
    </div>

    <div class="report-table-card">
        <h2>Pending Report Queue</h2>
        <div class="report-table-wrapper">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>S.No</th>
                        <th>Bill No</th>
                        <th>Patient ID</th>
                        <th>Patient Name</th>
                        <th>Age / Gender</th>
                        <?php if ($show_main_test_column): ?><th>Main Test</th><?php endif; ?>
                        <th>Subtest Name</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($report_rows)): ?>
                        <?php $serial = 1; foreach ($report_rows as $row): ?>
                            <tr>
                                <td><?php echo $serial++; ?></td>
                                <td><?php echo htmlspecialchars($row['bill_no']); ?></td>
                                <td><span style="font-size:0.82rem;color:#666;"><?php echo htmlspecialchars($row['patient_uid'] ?? ''); ?></span></td>
                                <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['age_gender']); ?></td>
                                <?php if ($show_main_test_column): ?><td><?php echo htmlspecialchars($row['main_test']); ?></td><?php endif; ?>
                                <td><?php echo htmlspecialchars($row['subtest']); ?></td>
                                <td>
                                    <a href="fill_report.php?item_id=<?php echo urlencode($row['bill_item_id']); ?>"
                                       class="btn-link">
                                        Open Word App
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $show_main_test_column ? 7 : 6; ?>" class="empty-state">
                                No reports match your filters yet. Adjust the dates or search criteria to continue.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>