<?php
$page_title = "Detailed Analytics";
$required_role = "manager"; //
require_once '../includes/auth_check.php'; //
require_once '../includes/db_connect.php'; //
require_once '../includes/functions.php';

ensure_bill_payment_split_columns($conn);

$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$users_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'users', 'u') : '`users` u';
$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';
$bill_item_screenings_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_item_screenings', 'bis') : '`bill_item_screenings` bis';
$referral_doctors_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd') : '`referral_doctors` rd';
$doctor_test_payables_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'doctor_test_payables', 'dtp') : '`doctor_test_payables` dtp';

$referral_doctors_source_lookup = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd_lookup') : '`referral_doctors` rd_lookup';
$users_source_lookup = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'users', 'u_lookup') : '`users` u_lookup';
$tests_source_lookup = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't_lookup') : '`tests` t_lookup';
$users_source_receptionists = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'users', 'ur') : '`users` ur';

// --- 1. GET AND PREPARE FILTERS & PAGINATION ---
$today_date = date('Y-m-d'); //
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $today_date; //
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : $today_date;     //
$referral_type = isset($_GET['referral_type']) ? $_GET['referral_type'] : 'all'; //
$doctor_id = isset($_GET['doctor_id']) && $_GET['doctor_id'] !== 'all' ? (int)$_GET['doctor_id'] : 'all'; //
$receptionist_id = isset($_GET['receptionist_id']) && $_GET['receptionist_id'] !== 'all' ? (int)$_GET['receptionist_id'] : 'all'; //
$main_test = isset($_GET['main_test']) ? $_GET['main_test'] : 'all'; //
$sub_test_id = isset($_GET['sub_test']) && $_GET['sub_test'] !== 'all' ? (int)$_GET['sub_test'] : 'all'; //
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1; //
$allowed_per_page = [20, 50, 100];
$records_per_page = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $allowed_per_page) ? (int)$_GET['per_page'] : 20; //
$offset = ($page - 1) * $records_per_page; //

$showReferredByColumn = true; //
$showReceptionistColumn = true; //
$showMainTestColumn = true; //
$showSubTestColumn = true; //
$selectedReceptionistName = null; //

// --- 2. BUILD DYNAMIC WHERE CLAUSE FOR MAIN DATA QUERY ---
$base_query_from = "
    FROM {$bills_source}
    JOIN {$patients_source} ON b.patient_id = p.id
    JOIN {$users_source} ON b.receptionist_id = u.id --
    JOIN {$bill_items_source} ON b.id = bi.bill_id AND bi.item_status = 0 -- Filter active items here
    JOIN {$tests_source} ON bi.test_id = t.id
    LEFT JOIN {$bill_item_screenings_source} ON bis.bill_item_id = bi.id
    LEFT JOIN {$referral_doctors_source} ON b.referral_doctor_id = rd.id
    LEFT JOIN {$doctor_test_payables_source} ON rd.id = dtp.doctor_id AND bi.test_id = dtp.test_id
"; //
$where_clauses = ["b.created_at BETWEEN ? AND ?", "b.bill_status != 'Void'"]; //
$end_date_for_query = $end_date . ' 23:59:59'; //
$params = [$start_date, $end_date_for_query]; //
$types = 'ss'; //
$active_filters = []; //
// --- Active filter display logic ---
if ($start_date !== $today_date || $end_date !== $today_date) { //
    if ($start_date === $end_date) { $active_filters[] = "Date: " . htmlspecialchars($start_date); } else { $active_filters[] = "Date: " . htmlspecialchars($start_date) . " to " . htmlspecialchars($end_date); } //
} else { $active_filters[] = "Date: Today"; } //
if ($referral_type !== 'all') { $where_clauses[] = "b.referral_type = ?"; $params[] = $referral_type; $types .= 's'; $active_filters[] = "Referral Type: " . htmlspecialchars($referral_type); if ($referral_type === 'Self') { $showReferredByColumn = false; } } //
if ($doctor_id !== 'all') { $where_clauses[] = "b.referral_doctor_id = ?"; $params[] = $doctor_id; $types .= 'i'; $doc_stmt = $conn->prepare("SELECT rd_lookup.doctor_name FROM {$referral_doctors_source_lookup} WHERE rd_lookup.id = ?"); $doc_stmt->bind_param("i", $doctor_id); $doc_stmt->execute(); $doc_name_result = $doc_stmt->get_result(); $doc_name = $doc_name_result->num_rows > 0 ? $doc_name_result->fetch_assoc()['doctor_name'] : 'N/A'; $active_filters[] = "Doctor: " . htmlspecialchars($doc_name); $doc_stmt->close(); $showReferredByColumn = false; } //
if ($receptionist_id !== 'all') { $where_clauses[] = "b.receptionist_id = ?"; $params[] = $receptionist_id; $types .= 'i'; $rec_stmt = $conn->prepare("SELECT u_lookup.username FROM {$users_source_lookup} WHERE u_lookup.id = ?"); $rec_stmt->bind_param("i", $receptionist_id); $rec_stmt->execute(); $rec_name_result = $rec_stmt->get_result(); $selectedReceptionistName = $rec_name_result->num_rows > 0 ? $rec_name_result->fetch_assoc()['username'] : 'N/A'; $active_filters[] = "Receptionist: " . htmlspecialchars($selectedReceptionistName); $rec_stmt->close(); $showReceptionistColumn = false; } //
if ($main_test !== 'all') { $where_clauses[] = "t.main_test_name = ?"; $params[] = $main_test; $types .= 's'; $active_filters[] = "Test Category: " . htmlspecialchars($main_test); $showMainTestColumn = false; } //
if ($sub_test_id !== 'all') { $where_clauses[] = "t.id = ?"; $params[] = $sub_test_id; $types .= 'i'; $sub_stmt = $conn->prepare("SELECT t_lookup.sub_test_name FROM {$tests_source_lookup} WHERE t_lookup.id = ?"); $sub_stmt->bind_param("i", $sub_test_id); $sub_stmt->execute(); $sub_name_result = $sub_stmt->get_result(); $sub_name = $sub_name_result->num_rows > 0 ? $sub_name_result->fetch_assoc()['sub_test_name'] : 'N/A'; $active_filters[] = "Test: " . htmlspecialchars($sub_name); $sub_stmt->close(); $showSubTestColumn = false; } //
$where_sql = " WHERE " . implode(' AND ', $where_clauses); //

// --- 3. GET TOTAL RECORD COUNT (Total Tests) FOR PAGINATION & CARD ---
// This remains the same, it correctly counts the filtered items.
$count_query = "SELECT COUNT(bi.id) as total " . $base_query_from . $where_sql; //
$stmt_count = $conn->prepare($count_query); //
if ($stmt_count === false) { die("Error preparing count query: " . $conn->error); } //
$stmt_count->bind_param($types, ...$params); //
$stmt_count->execute(); //
$total_records = $stmt_count->get_result()->fetch_assoc()['total']; // This IS the correct Total Tests count
$total_pages = ceil($total_records / $records_per_page); //
$stmt_count->close(); //

// --- 4. GET PAGINATED DATA FOR THE TABLE ---
// This query remains the same as the previous correct version
$data_query = "
    SELECT
        b.id as bill_id, b.invoice_number, p.id as patient_db_id, p.uid as patient_uid, p.name as patient_name, b.created_at,
        u.username as receptionist_name, --
        b.gross_amount, b.net_amount, b.discount, b.discount_by,
        b.payment_mode, b.cash_amount, b.card_amount, b.upi_amount, b.other_amount,
        b.referral_type, b.referral_source_other, rd.doctor_name, b.referral_doctor_id, --
        t.default_payable_amount,
        t.main_test_name, t.sub_test_name, t.price as test_price,
        COALESCE(bis.screening_amount, 0) AS screening_amount,
        COALESCE(bi.discount_amount, 0) AS item_discount,
        dtp.payable_amount as specific_payable_amount,
        bi.id as bill_item_id
    " . $base_query_from . $where_sql . " ORDER BY b.id DESC, bi.id ASC LIMIT ? OFFSET ?"; //
$data_params = $params; $data_types = $types; //
$data_params[] = $records_per_page; $data_params[] = $offset; $data_types .= 'ii'; //
$stmt_data = $conn->prepare($data_query); //
if ($stmt_data === false) { die("Error preparing data query: " . $conn->error); } //
$stmt_data->bind_param($data_types, ...$data_params); //
$stmt_data->execute(); //
$report_data = $stmt_data->get_result()->fetch_all(MYSQLI_ASSOC); //
$stmt_data->close(); //

// --- 5. CALCULATE SUMMARY CARDS BASED ON FILTERED DATASET ---
$summary_query = "SELECT
        COUNT(bi.id) AS total_tests,
        SUM(t.price + COALESCE(bis.screening_amount, 0)) AS gross_total,
        SUM(COALESCE(bi.discount_amount, 0)) AS total_discount,
        SUM((t.price + COALESCE(bis.screening_amount, 0)) - COALESCE(bi.discount_amount, 0)) AS net_total,
        SUM(CASE WHEN b.discount_by = 'Doctor' THEN COALESCE(bi.discount_amount, 0) ELSE 0 END) AS discount_by_doctor,
        SUM(CASE WHEN b.discount_by != 'Doctor' THEN COALESCE(bi.discount_amount, 0) ELSE 0 END) AS discount_by_center,
        SUM(
            CASE WHEN b.referral_type = 'Doctor' THEN
                CASE 
                    WHEN COALESCE(dtp.payable_amount, t.default_payable_amount, 0) > 0
                         AND COALESCE(bi.discount_amount, 0) < COALESCE(dtp.payable_amount, t.default_payable_amount, 0)
                    THEN COALESCE(dtp.payable_amount, t.default_payable_amount, 0) - COALESCE(bi.discount_amount, 0)
                    ELSE 0
                END
            ELSE 0
            END
        ) AS total_doctor_payable
    " . $base_query_from . $where_sql;

$stmt_summary = $conn->prepare($summary_query);
if ($stmt_summary === false) { die("Error preparing summary query: " . $conn->error); }
$stmt_summary->bind_param($types, ...$params);
$stmt_summary->execute();
$summary_row = $stmt_summary->get_result()->fetch_assoc() ?: [];
$stmt_summary->close();

$total_tests = (int)($summary_row['total_tests'] ?? 0);
$total_gross_items = (float)($summary_row['gross_total'] ?? 0);
$total_discount_amount = (float)($summary_row['total_discount'] ?? 0);
$total_net_amount = (float)($summary_row['net_total'] ?? 0);
$total_discount_by_doctor = (float)($summary_row['discount_by_doctor'] ?? 0);
$total_discount_by_center = (float)($summary_row['discount_by_center'] ?? 0);
$total_doctor_payable = (float)($summary_row['total_doctor_payable'] ?? 0);

$doctor_gross = 0;
$doctor_net = 0;
$doctor_discount = 0;
$doctor_total_payable = 0;
$total_revenue = $total_net_amount;

if ($doctor_id !== 'all') {
    $doctor_gross = $total_gross_items;
    $doctor_discount = $total_discount_amount;
    $doctor_net = $total_net_amount;
    $doctor_total_payable = $total_doctor_payable;
}


// --- Fetch data for dropdowns (Existing Code) ---
$doctors = $conn->query("SELECT rd.id, rd.doctor_name FROM {$referral_doctors_source} WHERE rd.is_active = 1 ORDER BY rd.doctor_name"); //
$main_tests_result = $conn->query("SELECT DISTINCT t.main_test_name FROM {$tests_source} ORDER BY t.main_test_name"); //
$all_tests_result = $conn->query("SELECT t.id, t.main_test_name, t.sub_test_name FROM {$tests_source} ORDER BY t.main_test_name, t.sub_test_name"); //
$all_tests_by_category = []; //
while($row = $all_tests_result->fetch_assoc()) { $all_tests_by_category[$row['main_test_name']][] = ['id' => $row['id'], 'name' => $row['sub_test_name']]; } //

// --- Colspan (Existing Code) ---
$colspan = 9; // base columns always shown (S.No, Bill ID, Patient ID, Patient, Item Discount, Test Total, Prof Charges, Date, Actions)
if ($showReferredByColumn) { $colspan++; } //
if ($showReceptionistColumn) { $colspan++; } //
if ($showMainTestColumn) { $colspan++; } //
if ($showSubTestColumn) { $colspan++; } //

if (!function_exists('calculateDoctorProfessionalCharge')) {
    function calculateDoctorProfessionalCharge(array $row): float {
        if (($row['referral_type'] ?? '') !== 'Doctor') {
            return 0.0;
        }
        $base = $row['specific_payable_amount'] ?? $row['default_payable_amount'] ?? 0;
        $base = (float) $base;
        if ($base <= 0) {
            return 0.0;
        }
        $itemDiscount = (float)($row['item_discount'] ?? 0);
        if ($itemDiscount < $base) {
            return max($base - $itemDiscount, 0.0);
        }
        return 0.0;
    }
}

require_once '../includes/header.php'; //
?>



<div class="page-container">
    <div class="dashboard-header">
        <h1>Detailed Analysis & Reporting</h1>
        <p>Monitor key performance metrics and generate detailed reports.</p>
    </div>
    <form method="GET" action="analytics.php" id="filter-form" class="filter-form compact-filters">
        <div class="filter-group" style="width: 100%; max-width: 100%;">
            <label>Quick Dates</label>
            <div class="quick-date-pills">
                <button type="button" class="btn-action" data-range="today">Today</button>
                <button type="button" class="btn-action" data-range="week">This Week</button>
                <button type="button" class="btn-action" data-range="month">This Month</button>
                <button type="button" class="btn-action" data-range="last_month">Last Month</button>
            </div>
        </div>

        <div class="filter-group">
            <label for="start_date">Start</label>
            <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>
        <div class="filter-group">
            <label for="end_date">End</label>
            <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>

        <div class="filter-group">
            <label for="analytics_referral_type">Referral Type</label>
            <select name="referral_type" id="analytics_referral_type">
                <option value="all">All Types</option>
                <option value="Doctor" <?php if($referral_type == 'Doctor') echo 'selected'; ?>>Doctor</option>
                <option value="Self" <?php if($referral_type == 'Self') echo 'selected'; ?>>Self</option>
                <option value="Other" <?php if($referral_type == 'Other') echo 'selected'; ?>>Other</option>
            </select>
        </div>

        <div class="filter-group" id="analytics_doctor_filter" style="display:<?php echo ($referral_type === 'Doctor') ? 'flex' : 'none'; ?>;">
            <label for="doctor_id">Doctor</label>
            <select name="doctor_id" id="doctor_id">
                <option value="all">All Doctors</option>
                <?php $doctors->data_seek(0); while($doc = $doctors->fetch_assoc()): ?>
                <option value="<?php echo $doc['id']; ?>" <?php if($doctor_id == $doc['id']) echo 'selected'; ?>><?php echo htmlspecialchars($doc['doctor_name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="analytics_receptionist">Receptionist</label>
            <select name="receptionist_id" id="analytics_receptionist">
                <option value="all">All Receptionists</option>
                <?php
                $recep_stmt = $conn->query("SELECT ur.id, ur.username FROM {$users_source_receptionists} WHERE ur.role = 'receptionist' ORDER BY ur.username");
                if ($recep_stmt) {
                    while ($rec = $recep_stmt->fetch_assoc()) {
                        $selected = ($receptionist_id == (int)$rec['id']) ? 'selected' : '';
                        echo '<option value="' . (int)$rec['id'] . '" ' . $selected . '>' . htmlspecialchars($rec['username']) . '</option>';
                    }
                    $recep_stmt->free();
                }
            ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="analytics_main_test">Test Category</label>
            <select name="main_test" id="analytics_main_test">
                <option value="all">All Categories</option>
                <?php $main_tests_result->data_seek(0); while($cat = $main_tests_result->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($cat['main_test_name']); ?>" <?php if($main_test == $cat['main_test_name']) echo 'selected'; ?>><?php echo htmlspecialchars($cat['main_test_name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="analytics_sub_test">Specific Test</label>
            <select name="sub_test" id="analytics_sub_test">
                <option value="all">All Tests</option>
            </select>
        </div>

        <div class="filter-actions">
            <a href="analytics.php" class="btn-cancel">Reset</a>
            <button type="submit" class="btn-submit">Apply</button>
            <a href="#" id="export-link" class="btn-export">
                <i class="fas fa-file-csv"></i> Download CSV
            </a>
        </div>
    </form>
    <?php if(!empty($active_filters)): ?>
    <div class="active-filters analytics-active-filters">
        <strong>Active Filters:</strong>
        <?php foreach($active_filters as $filter): ?><span class="analytics-filter-tag"><?php echo $filter; ?></span><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="summary-cards">
        <?php if ($doctor_id !== 'all'): // Specific Doctor View ?>
            <div class="summary-card" id="delete-mode-toggle"><h3>Total Tests</h3><p><?php echo $total_tests; ?></p></div>
            <div class="summary-card"><h3>Gross Amount (Filtered)</h3><p>₹ <?php echo number_format($doctor_gross, 2); ?></p></div>
            <div class="summary-card"><h3>Discount (Filtered)</h3><p>₹ <?php echo number_format($doctor_discount, 2); ?></p></div>
            <div class="summary-card"><h3>Net Revenue (Filtered)</h3><p>₹ <?php echo number_format($doctor_net, 2); ?></p></div>
            <div class="summary-card"><h3>Professional Charges (Filtered)</h3><p>₹ <?php echo number_format($doctor_total_payable, 2); ?></p></div>
        <?php else: // All Doctors View ?>
            <div class="summary-card" id="delete-mode-toggle"><h3>Total Tests</h3><p><?php echo $total_tests; ?></p></div>
            <div class="summary-card"><h3>Gross Amount (Filtered)</h3><p>₹ <?php echo number_format($total_gross_items, 2); ?></p></div>
            <div class="summary-card"><h3>Total Revenue (Filtered)</h3><p>₹ <?php echo number_format($total_revenue, 2); ?></p></div>
            <div class="summary-card"><h3>Discount by Center (Filtered)</h3><p>₹ <?php echo number_format($total_discount_by_center, 2); ?></p></div>
            <div class="summary-card"><h3>Discount by Doctors (Filtered)</h3><p>₹ <?php echo number_format($total_discount_by_doctor, 2); ?></p></div>
            <?php if ($receptionist_id !== 'all'): ?><div class="summary-card"><h3>Selected Receptionist</h3><p><?php echo htmlspecialchars($selectedReceptionistName ?? ''); ?></p></div><?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="table-responsive analytics-table-shell">
    <div class="analytics-entries-control">
        <label>Show
            <select id="analytics-per-page">
                <option value="20" <?php if($records_per_page == 20) echo 'selected'; ?>>20</option>
                <option value="50" <?php if($records_per_page == 50) echo 'selected'; ?>>50</option>
                <option value="100" <?php if($records_per_page == 100) echo 'selected'; ?>>100</option>
            </select>
            entries
        </label>
        <button type="button" class="btn-apply-entries" id="btn-apply-entries">Apply</button>
    </div>
    <table class="data-table custom-table" id="analytics-table">
        <colgroup>
            <col style="width: 11%;">
            <col style="width: 20%;">
            <col style="width: 25%;">
            <col style="width: 10%;">
            <col style="width: 8%;">
            <col style="width: 9%;">
            <col style="width: 9%;">
            <col style="width: 14%;">
            <col style="width: 8%;">
        </colgroup>
        <thead>
            <tr>
                <th class="col-bill">Bill</th>
                <th class="col-patient">Patient</th>
                <th class="col-test-details">Test Details</th>
                <th class="col-amount col-gross">GROSS AMOUNT</th>
                <th class="col-amount col-discount">Item Disc.</th>
                <th class="col-amount col-testtotal">Test Total</th>
                <th class="col-amount col-profcharge">PROF. CHARGE</th>
                <th class="col-payment-mode">PAYMENT MODE</th>
                <th class="action-header col-actions">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($report_data)): ?>
                <?php
                    $current_bill_id = null;
                    $display_gross_total = 0.0;
                    $display_item_discount_total = 0.0;
                    $display_test_total_total = 0.0;
                    $display_prof_charge_total = 0.0;

                    foreach($report_data as $row):
                        $is_first_row_for_bill = ($row['bill_id'] !== $current_bill_id);
                        $row_class = $is_first_row_for_bill ? 'bill-start' : 'bill-continue';
                        $payable_for_this_row = calculateDoctorProfessionalCharge($row);

                        $bill_id_text = (string)$row['bill_id'];
                        $bill_date_text = date('d-m-Y', strtotime((string)$row['created_at']));
                        $patient_name_text = (string)($row['patient_name'] ?? '');
                        $patient_uid_text = (string)($row['patient_uid'] ?? ('P' . $row['patient_db_id']));
                        $main_test_text = (string)($row['main_test_name'] ?? '');
                        $sub_test_text = (string)($row['sub_test_name'] ?? '');

                        $referred_by_text = 'Ref: Self';
                        if (($row['referral_type'] ?? '') === 'Doctor' && !empty($row['doctor_name'])) {
                            $referred_by_text = 'Ref: ' . (string)$row['doctor_name'];
                        } elseif (($row['referral_type'] ?? '') === 'Other') {
                            $other_ref = trim((string)($row['referral_source_other'] ?? ''));
                            $referred_by_text = $other_ref !== '' ? ('Ref: Other (' . $other_ref . ')') : 'Ref: Other';
                        }

                        $item_discount = (float)($row['item_discount'] ?? 0);
                        $discount_source = '';
                        if ($item_discount > 0) {
                            $discount_source = (($row['discount_by'] ?? '') === 'Doctor') ? 'D' : 'C';
                        }
                        $screening_amount = (float)($row['screening_amount'] ?? 0);
                        $test_total = (float)($row['test_price'] ?? 0) + $screening_amount;
                        $bill_gross_amount = (float)($row['gross_amount'] ?? 0);

                        $payment_mode_label = format_payment_mode_display($row, false);
                        $payment_mode_badge_class = 'mode-other';
                        $normalized_payment_mode = strtolower(str_replace([' ', '-', '+'], '', (string)$payment_mode_label));
                        if (strpos((string)$payment_mode_label, '+') !== false) {
                            $payment_mode_badge_class = 'mode-mixed';
                        } elseif ($normalized_payment_mode === 'cash') {
                            $payment_mode_badge_class = 'mode-cash';
                        } elseif ($normalized_payment_mode === 'card') {
                            $payment_mode_badge_class = 'mode-card';
                        } elseif ($normalized_payment_mode === 'upi') {
                            $payment_mode_badge_class = 'mode-upi';
                        }

                        $payment_breakdown_parts = [];
                        $cash_amount = (float)($row['cash_amount'] ?? 0);
                        $card_amount = (float)($row['card_amount'] ?? 0);
                        $upi_amount = (float)($row['upi_amount'] ?? 0);
                        $other_amount = (float)($row['other_amount'] ?? 0);
                        if ($cash_amount > 0.01) {
                            $payment_breakdown_parts[] = 'Cash: ₹' . number_format($cash_amount, 2);
                        }
                        if ($card_amount > 0.01) {
                            $payment_breakdown_parts[] = 'Card: ₹' . number_format($card_amount, 2);
                        }
                        if ($upi_amount > 0.01) {
                            $payment_breakdown_parts[] = 'UPI: ₹' . number_format($upi_amount, 2);
                        }
                        if ($other_amount > 0.01) {
                            $payment_breakdown_parts[] = 'Other: ₹' . number_format($other_amount, 2);
                        }
                        $payment_breakdown = implode(' | ', $payment_breakdown_parts);
                        $show_payment_breakdown = count($payment_breakdown_parts) > 1;

                        if ($is_first_row_for_bill) {
                            $display_gross_total += $bill_gross_amount;
                        }
                        $display_item_discount_total += $item_discount;
                        $display_test_total_total += $test_total;
                        $display_prof_charge_total += $payable_for_this_row;
                ?>
                <tr class="<?php echo $row_class; ?>">
                    <td class="col-bill <?php echo $is_first_row_for_bill ? '' : 'merged-cell'; ?>">
                        <?php if ($is_first_row_for_bill): ?>
                            <a class="bill-detail-link" href="preview_bill.php?bill_id=<?php echo urlencode($bill_id_text); ?>" target="_blank" rel="noopener" title="Open bill preview for Bill #<?php echo htmlspecialchars($bill_id_text, ENT_QUOTES); ?>">#<?php echo htmlspecialchars($bill_id_text); ?></a>
                            <span class="bill-meta-line"><?php echo htmlspecialchars($bill_date_text); ?></span>
                            <?php if ($showReceptionistColumn): ?>
                                <span class="bill-meta-line">By: <?php echo htmlspecialchars((string)$row['receptionist_name']); ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            &nbsp;
                        <?php endif; ?>
                    </td>
                    <td class="col-patient <?php echo $is_first_row_for_bill ? '' : 'merged-cell'; ?>">
                        <?php if ($is_first_row_for_bill): ?>
                            <span class="patient-name"><?php echo htmlspecialchars($patient_name_text); ?></span>
                            <span class="patient-ref"><?php echo htmlspecialchars($referred_by_text); ?></span>
                            <span class="patient-uid"><?php echo htmlspecialchars($patient_uid_text); ?></span>
                        <?php else: ?>
                            &nbsp;
                        <?php endif; ?>
                    </td>
                    <td class="col-test-details">
                        <span class="test-main"><?php echo htmlspecialchars($main_test_text); ?></span>
                        <span class="test-sub"><?php echo htmlspecialchars($sub_test_text); ?></span>
                        <?php if ($screening_amount > 0.0): ?>
                            <span class="screening-note">+ Screening: ₹<?php echo number_format($screening_amount, 2); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="cell-amount col-gross <?php echo $is_first_row_for_bill ? '' : 'merged-cell'; ?>">
                        <?php if ($is_first_row_for_bill): ?>
                            ₹<?php echo number_format($bill_gross_amount, 2); ?>
                        <?php else: ?>
                            &nbsp;
                        <?php endif; ?>
                    </td>
                    <td class="cell-amount col-discount">
                        ₹<?php echo number_format($item_discount, 2); ?>
                        <?php if ($discount_source !== ''): ?>
                            <span class="discount-tag">(<?php echo $discount_source; ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td class="cell-amount col-testtotal">₹<?php echo number_format($test_total, 2); ?></td>
                    <td class="cell-amount col-profcharge">₹<?php echo number_format($payable_for_this_row, 2); ?></td>
                    <td class="col-payment-mode <?php echo $is_first_row_for_bill ? '' : 'merged-cell'; ?>">
                        <?php if ($is_first_row_for_bill): ?>
                            <span class="payment-mode-pill <?php echo htmlspecialchars($payment_mode_badge_class); ?>"><?php echo htmlspecialchars($payment_mode_label); ?></span>
                            <?php if ($show_payment_breakdown): ?>
                                <span class="payment-mode-breakdown"><?php echo htmlspecialchars($payment_breakdown); ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            &nbsp;
                        <?php endif; ?>
                    </td>
                    <td class="action-cell col-actions">
                        <form action="delete_bill_item.php" method="POST" class="analytics-delete-form">
                            <input type="hidden" name="bill_item_id" value="<?php echo (int)$row['bill_item_id']; ?>">
                            <button type="submit" class="btn-action btn-danger" onclick="return confirm('Are you sure you want to hide this test from the report?');">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php
                        $current_bill_id = $row['bill_id'];
                    endforeach;
                ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" style="text-align: center;">No records found for the selected filters.</td>
                </tr>
            <?php endif; ?>
        </tbody>
        <?php if (!empty($report_data)): ?>
        <tfoot>
            <tr class="analytics-total-row">
                <th colspan="3" class="analytics-total-label">Filtered Totals</th>
                <th class="col-amount col-gross">₹<?php echo number_format($display_gross_total, 2); ?></th>
                <th class="col-amount col-discount">₹<?php echo number_format($display_item_discount_total, 2); ?></th>
                <th class="col-amount col-testtotal">₹<?php echo number_format($display_test_total_total, 2); ?></th>
                <th class="col-amount col-profcharge">₹<?php echo number_format($display_prof_charge_total, 2); ?></th>
                <th class="col-payment-mode">—</th>
                <th class="col-actions">&nbsp;</th>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    </div>

    <?php echo render_unified_pagination('analytics.php', (int)$page, (int)$total_pages, $_GET, 'Analytics Pagination'); ?>
</div>

<script>
// ... (All existing JavaScript code remains unchanged) ...
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date(); //
    const todayStr = today.toISOString().slice(0, 10); //
    const currentStartDate = '<?php echo $start_date; ?>'; //
    const currentEndDate = '<?php echo $end_date; ?>'; //

    document.querySelectorAll('[data-range]').forEach(button => { //
        button.addEventListener('click', function() { //
            const range = this.dataset.range; //
            let startDateStr, endDateStr; let tempDate = new Date(); //
            switch(range) { //
                case 'today': startDateStr = endDateStr = todayStr; break; //
                case 'week': //
                    const dayOfWeek = tempDate.getDay(); //
                    const firstDayOfWeek = new Date(tempDate.setDate(tempDate.getDate() - dayOfWeek + (dayOfWeek === 0 ? -6 : 1) )); //
                    startDateStr = firstDayOfWeek.toISOString().slice(0, 10); //
                    endDateStr = todayStr; //
                    break;
                case 'month': //
                    startDateStr = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().slice(0, 10); //
                    endDateStr = todayStr; //
                    break;
                case 'last_month': //
                    const lastMonthFirstDay = new Date(today.getFullYear(), today.getMonth() - 1, 1); //
                    const lastMonthLastDay = new Date(today.getFullYear(), today.getMonth(), 0); //
                    startDateStr = lastMonthFirstDay.toISOString().slice(0, 10); //
                    endDateStr = lastMonthLastDay.toISOString().slice(0, 10); //
                    break;
            }
            document.getElementById('start_date').value = startDateStr; //
            document.getElementById('end_date').value = endDateStr; //
            highlightActiveDateButton(this.dataset.range); //
            document.getElementById('filter-form').submit(); //
        });
    });

    function highlightActiveDateButton(activeRange = null) { //
        document.querySelectorAll('.quick-date-pills .btn-action').forEach(btn => { //
            btn.classList.remove('active'); //
            if (activeRange && btn.dataset.range === activeRange) { //
                btn.classList.add('active'); //
            }
        });
    }

    function highlightActiveDateButtonOnLoad() { //
        if (currentStartDate === todayStr && currentEndDate === todayStr) { //
            highlightActiveDateButton('today'); //
        }
         else if (currentStartDate === new Date(today.getFullYear(), today.getMonth(), 1).toISOString().slice(0, 10) && currentEndDate === todayStr) { //
             highlightActiveDateButton('month'); //
         }
        // Add checks for 'week' and 'last_month' if needed
        else {
            highlightActiveDateButton(null); //
        }
    }
    highlightActiveDateButtonOnLoad(); // Call on load


    const allTestsData = <?php echo json_encode($all_tests_by_category); ?>; //
    const currentSubTestId = '<?php echo $sub_test_id; ?>'; //
    const referralSelect = document.getElementById('analytics_referral_type'); //
    const doctorFilter = document.getElementById('analytics_doctor_filter'); //
    const mainTestSelect = document.getElementById('analytics_main_test'); //
    const subTestSelect = document.getElementById('analytics_sub_test'); //

    if (referralSelect && doctorFilter) { //
        referralSelect.addEventListener('change', function() { //
            doctorFilter.style.display = (this.value === 'Doctor') ? 'flex' : 'none'; //
        });
        referralSelect.dispatchEvent(new Event('change')); //
    }

    if (mainTestSelect && subTestSelect) { //
        mainTestSelect.addEventListener('change', function() { //
            subTestSelect.innerHTML = '<option value="all">All Tests</option>'; //
            const selectedCategory = this.value; //
            if (selectedCategory && allTestsData[selectedCategory]) { //
                allTestsData[selectedCategory].forEach(test => { //
                    const option = new Option(test.name, test.id); //
                    if (test.id == currentSubTestId) option.selected = true; //
                    subTestSelect.add(option); //
                });
            }
        });
        mainTestSelect.dispatchEvent(new Event('change')); //
    }

    const deleteModeToggle = document.getElementById('delete-mode-toggle'); //
    const analyticsTable = document.getElementById('analytics-table'); //
    if (deleteModeToggle && analyticsTable) { //
        deleteModeToggle.addEventListener('dblclick', function(event) { //
            event.preventDefault(); //
            if (analyticsTable.classList.contains('show-delete-actions')) { //
                analyticsTable.classList.remove('show-delete-actions'); //
                this.classList.remove('delete-active'); //
                return; //
            }
            const password = prompt("To enter Delete Mode, please enter your password:"); //
            if (!password) { return; } //
            fetch('verify_password.php', { //
                method: 'POST', //
                headers: { 'Content-Type': 'application/json' }, //
                body: JSON.stringify({ password: password }) //
            })
            .then(response => response.json()) //
            .then(data => { //
                if (data.success) { //
                    analyticsTable.classList.add('show-delete-actions'); //
                    deleteModeToggle.classList.add('delete-active'); //
                } else { //
                    alert(data.message || 'Incorrect password.'); //
                }
            })
            .catch(error => { console.error('Error:', error); alert('An error occurred during password verification.'); }); //
        });
    }

    function updateExportLink() { //
        const exportLink = document.getElementById('export-link'); //
        if (exportLink) { //
            const currentParams = new URLSearchParams(window.location.search); //
            currentParams.delete('page'); //
            exportLink.href = 'export_analytics.php?' + currentParams.toString(); //
        }
    }
    updateExportLink(); //

    // Custom entries control (replaces DataTables length control)
    const perPageSelect = document.getElementById('analytics-per-page');
    const applyEntriesBtn = document.getElementById('btn-apply-entries');
    if (perPageSelect && applyEntriesBtn) {
        applyEntriesBtn.addEventListener('click', function() {
            const val = perPageSelect.value;
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', val);
            url.searchParams.delete('page');
            window.location.href = url.toString();
        });
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>