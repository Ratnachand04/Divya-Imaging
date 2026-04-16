<?php
// --- PDF Library Autoloader ---
require_once '../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$page_title = "Generate Bill";

$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensureBillItemScreeningsTable($conn);
ensureBillItemDiscountColumn($conn);
ensure_bill_payment_split_columns($conn);
ensure_package_management_schema($conn);

// #############################################################################
// ### PDF SAVE PATH (relative to this file's directory) ###
// #############################################################################
// The 'YEAR/MONTH/DAY' subfolders will be auto-created inside this path.
// Docker: mapped to a persistent volume via docker-compose.yml
$pdf_save_path = '../saved_bills';

/**
 * Ensures the auxiliary table for storing per-item screening charges exists.
 */
function ensureBillItemScreeningsTable(mysqli $conn) {
    $createSql = "CREATE TABLE IF NOT EXISTS bill_item_screenings (\n        id INT(11) NOT NULL AUTO_INCREMENT,\n        bill_item_id INT(11) NOT NULL,\n        screening_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n        PRIMARY KEY (id),\n        UNIQUE KEY uniq_bill_item (bill_item_id),\n        CONSTRAINT fk_bill_item_screenings_item FOREIGN KEY (bill_item_id) REFERENCES bill_items(id) ON DELETE CASCADE\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    if (!$conn->query($createSql)) {
        error_log('Failed to ensure bill_item_screenings table: ' . $conn->error);
    }
}

/**
 * Adds the per-item discount column to bill_items when missing.
 */
function ensureBillItemDiscountColumn(mysqli $conn) {
    $columnCheck = $conn->query("SHOW COLUMNS FROM bill_items LIKE 'discount_amount'");
    if ($columnCheck && $columnCheck->num_rows === 0) {
        $alterSql = "ALTER TABLE bill_items ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER test_id";
        if (!$conn->query($alterSql)) {
            error_log('Failed to add discount_amount column to bill_items: ' . $conn->error);
        }
    }
    if ($columnCheck instanceof mysqli_result) {
        $columnCheck->free();
    }
}

/**
 * Generates and saves the bill PDF to a structured directory.
 */
function generateAndSaveBillPdf($bill_id, $conn, $patient_name, $base_save_path) {
    // 1. --- Fetch all necessary bill data ---
    $stmt = $conn->prepare(
        "SELECT b.*, p.uid as patient_uid, p.name as patient_name, p.age, p.sex, p.mobile_number, rd.doctor_name as referral_doctor_name
         FROM bills b
         JOIN patients p ON b.patient_id = p.id
         LEFT JOIN referral_doctors rd ON b.referral_doctor_id = rd.id
         WHERE b.id = ?"
    );
    $stmt->bind_param("i", $bill_id);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bill) { return false; }

    ensureBillItemScreeningsTable($conn);


    $items_stmt = $conn->prepare(
        "SELECT bi.id AS bill_item_id,
                COALESCE(bi.item_type, 'test') AS item_type,
                bi.package_id,
                COALESCE(NULLIF(bi.package_name, ''), tp.package_name) AS package_name,
                t.main_test_name,
                t.sub_test_name,
                t.price,
                COALESCE(bis.screening_amount, 0) AS screening_amount
         FROM bill_items bi
         LEFT JOIN tests t ON bi.test_id = t.id
         LEFT JOIN test_packages tp ON tp.id = bi.package_id
         LEFT JOIN bill_item_screenings bis ON bis.bill_item_id = bi.id
         WHERE bi.bill_id = ?
           AND bi.item_status = 0
           AND (COALESCE(bi.item_type, 'test') = 'package' OR bi.package_id IS NULL)
         ORDER BY bi.id ASC"
    );
    $items_stmt->bind_param("i", $bill_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();

    $package_breakdown_map = [];
    $package_items_stmt = $conn->prepare(
        "SELECT bill_item_id, test_name, package_test_price
         FROM bill_package_items
         WHERE bill_id = ?
         ORDER BY id ASC"
    );
    if ($package_items_stmt) {
        $package_items_stmt->bind_param('i', $bill_id);
        $package_items_stmt->execute();
        $package_items_result = $package_items_stmt->get_result();
        while ($pkg_item = $package_items_result->fetch_assoc()) {
            $bill_item_id = (int)($pkg_item['bill_item_id'] ?? 0);
            if (!isset($package_breakdown_map[$bill_item_id])) {
                $package_breakdown_map[$bill_item_id] = [];
            }
            $package_breakdown_map[$bill_item_id][] = $pkg_item;
        }
        $package_items_stmt->close();
    }

    $items_html = '';
    while($item = $items_result->fetch_assoc()) {
        $item_type = (string)($item['item_type'] ?? 'test');
        $bill_item_id = (int)($item['bill_item_id'] ?? 0);

        if ($item_type === 'package') {
            $package_name = trim((string)($item['package_name'] ?? ''));
            if ($package_name === '') {
                $package_name = 'Package';
            }
            $package_tests = $package_breakdown_map[$bill_item_id] ?? [];
            $package_total = 0.0;
            foreach ($package_tests as $package_test) {
                $package_total += (float)($package_test['package_test_price'] ?? 0);
            }
            $package_total = round($package_total, 2);

            $package_label = htmlspecialchars($package_name . ' (PACKAGE)');
            $items_html .= "<tr><td>{$package_label}</td><td style='text-align:right;'>" . number_format($package_total, 2) . "</td></tr>";

            foreach ($package_tests as $package_test) {
                $test_name = trim((string)($package_test['test_name'] ?? 'Included Test'));
                if ($test_name === '') {
                    $test_name = 'Included Test';
                }
                $test_label = htmlspecialchars('- ' . $test_name);
                $test_price = number_format((float)($package_test['package_test_price'] ?? 0), 2);
                $items_html .= "<tr><td>{$test_label}</td><td style='text-align:right;'>{$test_price}</td></tr>";
            }

            continue;
        }

        $raw_name = trim(preg_replace('/\s+/', ' ', $item['main_test_name'] . ' ' . $item['sub_test_name']));
        if ($raw_name === '') {
            $raw_name = 'Unnamed Test';
        }
        $test_label = htmlspecialchars('Test ' . $raw_name);
        $base_price = number_format($item['price'], 2);
        $items_html .= "<tr><td>{$test_label}</td><td style='text-align:right;'>{$base_price}</td></tr>";

        $screening_amount = (float)$item['screening_amount'];
        if ($screening_amount > 0) {
            $screen_label = htmlspecialchars($raw_name . ' Screening');
            $screen_price = number_format($screening_amount, 2);
            $items_html .= "<tr><td>{$screen_label}</td><td style='text-align:right;'>{$screen_price}</td></tr>";
        }
    }
    $items_stmt->close();
    
    // 2. --- Prepare the HTML content for the PDF ---
    $bill_date = date('d-m-Y', strtotime($bill['created_at']));
    $ref_physician = ($bill['referral_doctor_name']) ? htmlspecialchars($bill['referral_doctor_name']) : 'Self';

    $html = <<<HTML
    <style>
        @page { margin: 15mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; margin: 0; }
        .container { width: 100%; margin: 0 auto; }
        .header { text-align: center; border: 1px solid #000; padding: 10px; margin-bottom: 18px; }
        .header h1 { margin: 0; font-size: 20px; }
        .details-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .details-table td { padding: 4px; vertical-align: top; }
        .items-table { width: 100%; border-collapse: collapse; }
        .items-table th, .items-table td { border: 1px solid #000; padding: 6px 8px; }
        .items-table th { background-color: #f2f2f2; }
        .totals { width: 240px; margin: 18px 0 0 auto; border-collapse: collapse; }
        .totals td { padding: 6px 4px; text-align: right; }
        .totals tr td:first-child { text-align: left; }
    </style>
    <div class="container">
        <div class="header"><h1>DIVYA IMAGING CENTER</h1></div> <h3 style="text-align:center; margin-bottom:20px;">BILL RECEIPT</h3>
        <table class="details-table">
            <tr><td><strong>BILL NO:</strong> {$bill['id']}</td><td><strong>BILL DATE:</strong> {$bill_date}</td></tr>
            <tr><td><strong>UID:</strong> {$bill['patient_uid']}</td><td></td></tr>
            <tr><td><strong>Patient Name:</strong> {$bill['patient_name']}</td><td><strong>Mobile No:</strong> {$bill['mobile_number']}</td></tr>
            <tr><td><strong>Age & Gender:</strong> {$bill['age']} / {$bill['sex']}</td><td></td></tr>
            <tr><td><strong>Ref. Physician:</strong> {$ref_physician}</td><td></td></tr>
        </table>
        <table class="items-table">
            <thead><tr><th>Investigation Name</th><th style='text-align:right;'>Amount</th></tr></thead>
            <tbody>{$items_html}</tbody>
        </table>
        <table class="totals">
            <tr><td>Sub Total:</td><td>{$bill['gross_amount']}</td></tr>
            <tr><td>Disc Amt:</td><td>{$bill['discount']}</td></tr>
            <tr><td><strong>TOTAL:</strong></td><td><strong>{$bill['net_amount']}</strong></td></tr>
        </table>
        <div style="clear: both;"></div>
    </div>
    HTML;

    // 3. --- Generate the PDF ---
    $options = new Options(); $options->set('isHtml5ParserEnabled', true);
    $dompdf = new Dompdf($options); $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait'); $dompdf->render();

    // 4. --- Create directory structure and save the file ---
    $year = date('Y'); $month = date('m_F'); $day = date('d');
    $directory = rtrim($base_save_path, '/') . "/{$year}/{$month}/{$day}";
    if (!is_dir($directory)) { mkdir($directory, 0775, true); }
    $safe_patient_name = preg_replace('/[^A-Za-z0-9\-]/', '_', $patient_name);
    $filename = "{$bill['id']}_{$safe_patient_name}.pdf";
    $file_path = "{$directory}/{$filename}";
    file_put_contents($file_path, $dompdf->output());
    return true;
}


$error_message = '';
$success_message = '';

// --- FORM SUBMISSION LOGIC ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn->begin_transaction();
    try {
        ensure_patient_registration_schema($conn);

        $is_new_patient = isset($_POST['is_new_patient']) && $_POST['is_new_patient'] === '1';
        $submitted_uid = strtoupper(trim($_POST['patient_uid'] ?? ''));
        $patient_name = trim($_POST['patient_name'] ?? '');
        $patient_age = isset($_POST['patient_age']) ? (int)$_POST['patient_age'] : 0;
        $patient_sex = trim($_POST['patient_sex'] ?? '');
        $patient_address = trim($_POST['patient_address'] ?? '');
        $patient_city = trim($_POST['patient_city'] ?? '');
        $patient_mobile = trim($_POST['patient_mobile'] ?? '');
        $patient_id = 0;

        if ($is_new_patient) {
            if ($submitted_uid === '' || !preg_match('/^DC\d{8}$/', $submitted_uid)) {
                throw new Exception("Please generate a valid patient ID after entering patient details.");
            }
            if ($patient_name === '' || $patient_age < 0 || $patient_sex === '' || $patient_mobile === '') {
                throw new Exception("Patient name, age, gender, and mobile number are required for new patient registration.");
            }
            if (!preg_match('/^\d{10}$/', $patient_mobile)) {
                throw new Exception("Mobile number must be exactly 10 digits.");
            }

            $uid = $submitted_uid;
            $stmt_patient = $conn->prepare("INSERT INTO patients (uid, name, age, sex, address, city, mobile_number) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt_patient) {
                throw new Exception("Patient preparation failed: " . $conn->error);
            }
            $stmt_patient->bind_param("ssissss", $uid, $patient_name, $patient_age, $patient_sex, $patient_address, $patient_city, $patient_mobile);
            if ($stmt_patient->execute()) {
                $patient_id = $stmt_patient->insert_id;
            } else {
                $insert_error_no = $stmt_patient->errno;
                $insert_error = $stmt_patient->error;
                $stmt_patient->close();
                if ($insert_error_no === 1062) {
                    throw new Exception("Generated patient ID already exists. Please click Generate New again.");
                }
                throw new Exception("Patient insertion failed: " . $insert_error);
            }
            $stmt_patient->close();
        } else {
            if ($submitted_uid === '') {
                throw new Exception("Patient ID is required for existing patient.");
            }
            if (!preg_match('/^DC\d{8}$/', $submitted_uid)) {
                throw new Exception("Patient ID must be in DCYYYYNNNN format.");
            }

            $stmt_existing = $conn->prepare("SELECT id, uid, name, age, sex, address, city, mobile_number FROM patients WHERE uid = ? LIMIT 1");
            if (!$stmt_existing) {
                throw new Exception("Failed to prepare patient lookup: " . $conn->error);
            }
            $stmt_existing->bind_param("s", $submitted_uid);
            $stmt_existing->execute();
            $existing_patient = $stmt_existing->get_result()->fetch_assoc();
            $stmt_existing->close();

            if (!$existing_patient) {
                throw new Exception("Patient not found for ID {$submitted_uid}.");
            }

            $patient_id = (int)$existing_patient['id'];
            $patient_name = (string)$existing_patient['name'];
            $patient_age = (int)$existing_patient['age'];
            $patient_sex = (string)$existing_patient['sex'];
            $patient_address = (string)($existing_patient['address'] ?? '');
            $patient_city = (string)($existing_patient['city'] ?? '');
            $patient_mobile = (string)($existing_patient['mobile_number'] ?? '');
        }

        if ($patient_id <= 0) {
            throw new Exception("Patient insertion failed: unable to generate unique UID.");
        }

        $receptionist_id = $_SESSION['user_id'];
        $referral_type = trim($_POST['referral_type'] ?? 'Self');
        $allowed_referral_types = ['Self', 'Doctor', 'Other'];
        if (!in_array($referral_type, $allowed_referral_types, true)) {
            $referral_type = 'Self';
        }

        $referral_doctor_id = null;
        $referral_source_other = null;

        if ($referral_type === 'Doctor') {
            if (!empty($_POST['referral_doctor_id']) && $_POST['referral_doctor_id'] === 'other') {
                $new_doctor_name = trim($_POST['other_doctor_name'] ?? '');
                if ($new_doctor_name !== '') {
                    $stmt_doc_check = $conn->prepare("SELECT id FROM referral_doctors WHERE doctor_name = ?");
                    $stmt_doc_check->bind_param("s", $new_doctor_name);
                    $stmt_doc_check->execute();
                    $doc_result = $stmt_doc_check->get_result();
                    if ($doc_result->num_rows > 0) {
                        $referral_doctor_id = $doc_result->fetch_assoc()['id'];
                    } else {
                        $stmt_doc_insert = $conn->prepare("INSERT INTO referral_doctors (doctor_name, is_active) VALUES (?, 1)");
                        $stmt_doc_insert->bind_param("s", $new_doctor_name);
                        $stmt_doc_insert->execute();
                        $referral_doctor_id = $stmt_doc_insert->insert_id;
                        $stmt_doc_insert->close();
                    }
                    $stmt_doc_check->close();
                }
            } elseif (!empty($_POST['referral_doctor_id'])) {
                $referral_doctor_id = (int)$_POST['referral_doctor_id'];
            }
        } elseif ($referral_type === 'Other') {
            $referral_source_other = trim($_POST['referral_source_other_select'] ?? '');
        }

        $selected_items_raw = json_decode($_POST['selected_tests_json'] ?? '', true);
        if (!is_array($selected_items_raw) || empty($selected_items_raw)) {
            throw new Exception("No tests or packages were selected.");
        }

        $structured_tests = [];
        $structured_packages = [];
        $package_seen = [];
        foreach ($selected_items_raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $item_type = strtolower(trim((string)($entry['item_type'] ?? 'test')));
            if ($item_type === 'package') {
                $package_id = isset($entry['id']) ? (int)$entry['id'] : 0;
                if ($package_id <= 0 || isset($package_seen[$package_id])) {
                    continue;
                }
                $package_seen[$package_id] = true;
                $structured_packages[] = [
                    'id' => $package_id
                ];
                continue;
            }

            $test_id = isset($entry['id']) ? (int)$entry['id'] : 0;
            if ($test_id <= 0) {
                continue;
            }

            $screening_amount = isset($entry['screening']) ? max(0, round((float)$entry['screening'], 2)) : 0.00;
            $discount_amount = isset($entry['discount']) ? max(0, round((float)$entry['discount'], 2)) : 0.00;
            $structured_tests[] = [
                'id' => $test_id,
                'screening' => $screening_amount,
                'discount' => $discount_amount
            ];
        }

        if (empty($structured_tests) && empty($structured_packages)) {
            throw new Exception("No valid tests or packages were selected.");
        }

        $test_price_map = [];
        if (!empty($structured_tests)) {
            $test_ids = array_column($structured_tests, 'id');
            $placeholders = implode(',', array_fill(0, count($test_ids), '?'));
            $stmt_prices = $conn->prepare("SELECT id, price FROM tests WHERE id IN ($placeholders)");
            if (!$stmt_prices) {
                throw new Exception("Failed to prepare test price lookup: " . $conn->error);
            }
            $types_str = str_repeat('i', count($test_ids));
            $stmt_prices->bind_param($types_str, ...$test_ids);
            $stmt_prices->execute();
            $price_result = $stmt_prices->get_result();
            while ($row = $price_result->fetch_assoc()) {
                $test_price_map[(int)$row['id']] = (float)$row['price'];
            }
            $stmt_prices->close();
        }

        $package_map = [];
        $package_tests_map = [];
        if (!empty($structured_packages)) {
            $package_ids = array_column($structured_packages, 'id');
            $pkg_placeholders = implode(',', array_fill(0, count($package_ids), '?'));
            $pkg_types = str_repeat('i', count($package_ids));

            $stmt_packages = $conn->prepare("SELECT id, package_code, package_name, total_base_price, package_price, discount_amount, status FROM test_packages WHERE id IN ($pkg_placeholders)");
            if (!$stmt_packages) {
                throw new Exception('Failed to prepare package lookup: ' . $conn->error);
            }
            $stmt_packages->bind_param($pkg_types, ...$package_ids);
            $stmt_packages->execute();
            $package_result = $stmt_packages->get_result();
            while ($pkg = $package_result->fetch_assoc()) {
                $package_map[(int)$pkg['id']] = $pkg;
            }
            $stmt_packages->close();

            $stmt_package_tests = $conn->prepare(
                "SELECT pt.package_id, pt.test_id, pt.base_test_price, pt.package_test_price, t.main_test_name, t.sub_test_name
                 FROM package_tests pt
                 JOIN tests t ON t.id = pt.test_id
                 WHERE pt.package_id IN ($pkg_placeholders)
                 ORDER BY pt.package_id ASC, pt.display_order ASC, pt.id ASC"
            );
            if (!$stmt_package_tests) {
                throw new Exception('Failed to prepare package tests lookup: ' . $conn->error);
            }
            $stmt_package_tests->bind_param($pkg_types, ...$package_ids);
            $stmt_package_tests->execute();
            $pkg_tests_result = $stmt_package_tests->get_result();
            while ($pkg_test = $pkg_tests_result->fetch_assoc()) {
                $pid = (int)$pkg_test['package_id'];
                if (!isset($package_tests_map[$pid])) {
                    $package_tests_map[$pid] = [];
                }
                $package_tests_map[$pid][] = $pkg_test;
            }
            $stmt_package_tests->close();
        }

        $gross_amount = 0.0;
        $total_discount = 0.0;

        foreach ($structured_tests as &$test_entry) {
            $test_id = $test_entry['id'];
            if (!isset($test_price_map[$test_id])) {
                throw new Exception("One or more selected tests are invalid. Please refresh and try again.");
            }

            $base_price = $test_price_map[$test_id];
            $item_gross = $base_price + $test_entry['screening'];
            if ($test_entry['discount'] > $item_gross) {
                $test_entry['discount'] = $item_gross;
            }
            $gross_amount += $item_gross;
            $total_discount += $test_entry['discount'];
        }
        unset($test_entry);

        foreach ($structured_packages as &$package_entry) {
            $package_id = (int)$package_entry['id'];
            if (!isset($package_map[$package_id])) {
                throw new Exception('One or more selected packages are invalid. Please refresh and try again.');
            }

            $package_row = $package_map[$package_id];
            if ((string)($package_row['status'] ?? 'inactive') !== 'active') {
                throw new Exception('Selected package is currently inactive. Please select an active package.');
            }

            $package_tests = $package_tests_map[$package_id] ?? [];
            if (empty($package_tests)) {
                throw new Exception('Selected package has no tests configured. Please update package first.');
            }

            $base_total = 0.0;
            $sum_package_test_price = 0.0;
            $normalized_tests = [];
            foreach ($package_tests as $pkg_test_row) {
                $base_price = round((float)$pkg_test_row['base_test_price'], 2);
                $package_test_price = round((float)$pkg_test_row['package_test_price'], 2);
                if ($package_test_price < 0) {
                    $package_test_price = 0.0;
                }

                $main_test_name = trim((string)($pkg_test_row['main_test_name'] ?? ''));
                $sub_test_name = trim((string)($pkg_test_row['sub_test_name'] ?? ''));
                $test_name = $sub_test_name !== '' ? ($main_test_name . ' - ' . $sub_test_name) : $main_test_name;
                if ($test_name === '') {
                    $test_name = 'Unnamed Test';
                }

                $normalized_tests[] = [
                    'test_id' => (int)$pkg_test_row['test_id'],
                    'test_name' => $test_name,
                    'base_test_price' => $base_price,
                    'package_test_price' => $package_test_price
                ];

                $base_total += $base_price;
                $sum_package_test_price += $package_test_price;
            }

            $base_total = round($base_total, 2);
            $sum_package_test_price = round($sum_package_test_price, 2);

            $package_price = round((float)($package_row['package_price'] ?? 0), 2);
            if ($package_price < 0) {
                $package_price = 0.0;
            }

            $price_delta = round($package_price - $sum_package_test_price, 2);
            if (!empty($normalized_tests) && abs($price_delta) > 0.01) {
                $last_index = count($normalized_tests) - 1;
                $adjusted = round($normalized_tests[$last_index]['package_test_price'] + $price_delta, 2);
                if ($adjusted < 0) {
                    $adjusted = 0.0;
                }
                $normalized_tests[$last_index]['package_test_price'] = $adjusted;
            }

            $billable_gross = $base_total;
            $package_discount = 0.0;
            if ($package_price <= $base_total) {
                $package_discount = round($base_total - $package_price, 2);
            } else {
                $billable_gross = $package_price;
            }

            $gross_amount += $billable_gross;
            $total_discount += $package_discount;

            foreach ($normalized_tests as &$normalized_test) {
                $component_discount = round(max($normalized_test['base_test_price'] - $normalized_test['package_test_price'], 0), 2);
                $normalized_test['component_discount'] = $component_discount;
            }
            unset($normalized_test);

            $package_entry['package_name'] = (string)$package_row['package_name'];
            $package_entry['package_code'] = (string)$package_row['package_code'];
            $package_entry['base_total'] = $base_total;
            $package_entry['package_price'] = $package_price;
            $package_entry['package_discount'] = $package_discount;
            $package_entry['tests'] = $normalized_tests;
        }
        unset($package_entry);

        $gross_amount = round($gross_amount, 2);
        $total_discount = round(min($total_discount, $gross_amount), 2);
        $net_amount = round($gross_amount - $total_discount, 2);

        $allowed_discount_sources = ['Center', 'Doctor'];
        $discount_by_input = trim($_POST['discount_by'] ?? '');
        if ($total_discount > 0) {
            if (!in_array($discount_by_input, $allowed_discount_sources, true)) {
                throw new Exception("Please select who provided the discount.");
            }
            $discount_by = $discount_by_input;
        } else {
            $discount_by = in_array($discount_by_input, $allowed_discount_sources, true) ? $discount_by_input : 'Center';
        }

        $payment_mode_allowed = get_supported_payment_modes();
        $payment_mode = sanitize_payment_mode_input($_POST['payment_mode'] ?? 'Cash');
        if (!in_array($payment_mode, $payment_mode_allowed, true)) {
            $payment_mode = 'Cash';
        }

        $payment_status = trim($_POST['payment_status'] ?? 'Paid');
        if (!in_array($payment_status, ['Paid', 'Due', 'Partial Paid'], true)) {
            $payment_status = 'Paid';
        }

        $amount_paid = 0.00;
        $balance_amount = $net_amount;

        if ($payment_status === 'Partial Paid') {
            $amount_paid = isset($_POST['amount_paid']) ? max(0, (float)$_POST['amount_paid']) : 0.00;
            if ($amount_paid <= 0) {
                $payment_status = 'Due';
                $amount_paid = 0.00;
                $balance_amount = $net_amount;
            } elseif ($amount_paid >= $net_amount) {
                $payment_status = 'Paid';
                $amount_paid = $net_amount;
                $balance_amount = 0.00;
            } else {
                $balance_amount = round($net_amount - $amount_paid, 2);
            }
        } elseif ($payment_status === 'Paid') {
            $amount_paid = $net_amount;
            $balance_amount = 0.00;
        } else {
            $payment_status = 'Due';
            $amount_paid = 0.00;
            $balance_amount = $net_amount;
        }

        $split_amounts = build_payment_split_from_input($_POST, $payment_mode, $amount_paid);
        $cash_amount = $split_amounts['cash_amount'];
        $card_amount = $split_amounts['card_amount'];
        $upi_amount = $split_amounts['upi_amount'];
        $other_amount = $split_amounts['other_amount'];

        $stmt_bill = $conn->prepare("INSERT INTO bills (patient_id, receptionist_id, referral_type, referral_doctor_id, referral_source_other, gross_amount, discount, discount_by, net_amount, amount_paid, balance_amount, payment_mode, cash_amount, card_amount, upi_amount, other_amount, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt_bill) {
            throw new Exception("Failed to prepare bill insert statement: " . $conn->error);
        }
        $stmt_bill->bind_param("iisisddsdddsdddds", $patient_id, $receptionist_id, $referral_type, $referral_doctor_id, $referral_source_other, $gross_amount, $total_discount, $discount_by, $net_amount, $amount_paid, $balance_amount, $payment_mode, $cash_amount, $card_amount, $upi_amount, $other_amount, $payment_status);
        $stmt_bill->execute();
        $bill_id = $stmt_bill->insert_id;
        $stmt_bill->close();

        $stmt_item_test = $conn->prepare("INSERT INTO bill_items (bill_id, test_id, is_package, item_type, discount_amount) VALUES (?, ?, 0, 'test', ?)");
        if (!$stmt_item_test) {
            throw new Exception("Failed to prepare bill test items statement: " . $conn->error);
        }
        $stmt_item_test->bind_param("iid", $bill_id_param, $test_id_param, $discount_amount_param);

        $stmt_item_package_component = $conn->prepare("INSERT INTO bill_items (bill_id, test_id, package_id, is_package, item_type, package_name, package_discount, discount_amount) VALUES (?, ?, ?, 0, 'test', ?, 0.00, ?)");
        if (!$stmt_item_package_component) {
            throw new Exception("Failed to prepare package component bill item statement: " . $conn->error);
        }
        $stmt_item_package_component->bind_param("iiisd", $bill_id_param, $test_id_param, $package_id_param, $package_name_param, $discount_amount_param);

        $stmt_item_package = $conn->prepare("INSERT INTO bill_items (bill_id, test_id, package_id, is_package, item_type, package_name, package_discount, discount_amount) VALUES (?, NULL, ?, 1, 'package', ?, ?, ?)");
        if (!$stmt_item_package) {
            throw new Exception("Failed to prepare package bill item statement: " . $conn->error);
        }
        $stmt_item_package->bind_param("iisdd", $bill_id_param, $package_id_param, $package_name_param, $package_discount_param, $discount_amount_param);

        $stmt_screening = $conn->prepare("INSERT INTO bill_item_screenings (bill_item_id, screening_amount) VALUES (?, ?)");
        if (!$stmt_screening) {
            throw new Exception("Failed to prepare screening items statement: " . $conn->error);
        }
        $stmt_screening->bind_param("id", $bill_item_id_param, $screening_amount_param);

        $stmt_bill_package_items = $conn->prepare("INSERT INTO bill_package_items (bill_id, bill_item_id, package_id, test_id, test_name, base_test_price, package_test_price) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt_bill_package_items) {
            throw new Exception("Failed to prepare bill package breakdown statement: " . $conn->error);
        }
        $stmt_bill_package_items->bind_param("iiiisdd", $bill_id_param, $bill_item_id_param, $package_id_param, $test_id_param, $package_test_name_param, $base_test_price_param, $package_test_price_param);

        foreach ($structured_tests as $test_entry) {
            $bill_id_param = $bill_id;
            $test_id_param = (int)$test_entry['id'];
            $discount_amount_param = (float)$test_entry['discount'];
            $stmt_item_test->execute();
            $bill_item_id_param = (int)$stmt_item_test->insert_id;

            $screening_amount_param = (float)$test_entry['screening'];
            if ($bill_item_id_param > 0 && $screening_amount_param > 0) {
                $stmt_screening->execute();
            }
        }

        foreach ($structured_packages as $package_entry) {
            $bill_id_param = $bill_id;
            $package_id_param = (int)$package_entry['id'];
            $package_name_param = (string)$package_entry['package_name'];
            $package_discount_param = (float)$package_entry['package_discount'];
            $discount_amount_param = (float)$package_entry['package_discount'];

            $stmt_item_package->execute();
            $package_bill_item_id = (int)$stmt_item_package->insert_id;
            if ($package_bill_item_id <= 0) {
                throw new Exception('Failed to insert package bill item.');
            }

            foreach (($package_entry['tests'] ?? []) as $pkg_test) {
                $bill_id_param = $bill_id;
                $test_id_param = (int)$pkg_test['test_id'];
                $package_id_param = (int)$package_entry['id'];
                $package_name_param = (string)$package_entry['package_name'];
                $discount_amount_param = (float)($pkg_test['component_discount'] ?? 0);
                $stmt_item_package_component->execute();

                $bill_item_id_param = $package_bill_item_id;
                $package_test_name_param = (string)$pkg_test['test_name'];
                $base_test_price_param = (float)$pkg_test['base_test_price'];
                $package_test_price_param = (float)$pkg_test['package_test_price'];
                $stmt_bill_package_items->execute();
            }
        }

        $stmt_item_test->close();
        $stmt_item_package_component->close();
        $stmt_item_package->close();
        $stmt_screening->close();
        $stmt_bill_package_items->close();

        $conn->commit();

        try {
            generateAndSaveBillPdf($bill_id, $conn, $patient_name, $pdf_save_path);
        } catch (Exception $pdf_e) {
            error_log("PDF generation failed for bill #{$bill_id}: " . $pdf_e->getMessage());
        }

        require_once '../includes/functions.php';
        log_system_action($conn, 'BILL_CREATED', $bill_id, "Generated bill for patient '{$patient_name}' with Net Amount: {$net_amount}.");
        header("Location: preview_bill.php?bill_id=" . $bill_id);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Failed to create bill. Error: " . $e->getMessage();
    }
}

// --- DATA FETCHING FOR FORM ---
$doctors_result = $conn->query("SELECT id, doctor_name FROM referral_doctors WHERE is_active = 1 ORDER BY doctor_name ASC");
$tests_result = $conn->query("SELECT id, main_test_name, sub_test_name, price FROM tests ORDER BY main_test_name, sub_test_name ASC");
$tests_by_category = [];
while ($test = $tests_result->fetch_assoc()) {
    $tests_by_category[$test['main_test_name']][] = $test;
}

$packages_map = [];
$packages_heads_result = $conn->query("SELECT id, package_code, package_name, total_base_price, package_price, discount_amount, discount_percent FROM test_packages WHERE status = 'active' ORDER BY package_name ASC");
if ($packages_heads_result instanceof mysqli_result) {
    while ($pkg = $packages_heads_result->fetch_assoc()) {
        $pid = (int)$pkg['id'];
        $packages_map[$pid] = [
            'id' => $pid,
            'package_code' => (string)$pkg['package_code'],
            'package_name' => (string)$pkg['package_name'],
            'total_base_price' => round((float)$pkg['total_base_price'], 2),
            'package_price' => round((float)$pkg['package_price'], 2),
            'discount_amount' => round((float)$pkg['discount_amount'], 2),
            'discount_percent' => round((float)$pkg['discount_percent'], 2),
            'tests' => []
        ];
    }
    $packages_heads_result->free();
}

if (!empty($packages_map)) {
    $package_ids = array_keys($packages_map);
    $pkg_placeholders = implode(',', array_fill(0, count($package_ids), '?'));
    $pkg_types = str_repeat('i', count($package_ids));
    $package_tests_stmt = $conn->prepare(
        "SELECT pt.package_id, pt.test_id, pt.base_test_price, pt.package_test_price, t.main_test_name, t.sub_test_name
         FROM package_tests pt
         JOIN tests t ON t.id = pt.test_id
         WHERE pt.package_id IN ($pkg_placeholders)
         ORDER BY pt.package_id ASC, pt.display_order ASC, pt.id ASC"
    );
    if ($package_tests_stmt) {
        $package_tests_stmt->bind_param($pkg_types, ...$package_ids);
        $package_tests_stmt->execute();
        $package_tests_result = $package_tests_stmt->get_result();
        while ($pkg_test = $package_tests_result->fetch_assoc()) {
            $pid = (int)$pkg_test['package_id'];
            if (!isset($packages_map[$pid])) {
                continue;
            }
            $main_test_name = trim((string)($pkg_test['main_test_name'] ?? ''));
            $sub_test_name = trim((string)($pkg_test['sub_test_name'] ?? ''));
            $test_label = $sub_test_name !== '' ? ($main_test_name . ' - ' . $sub_test_name) : $main_test_name;
            if ($test_label === '') {
                $test_label = 'Unnamed Test';
            }
            $packages_map[$pid]['tests'][] = [
                'test_id' => (int)$pkg_test['test_id'],
                'test_name' => $test_label,
                'base_test_price' => round((float)$pkg_test['base_test_price'], 2),
                'package_test_price' => round((float)$pkg_test['package_test_price'], 2)
            ];
        }
        $package_tests_stmt->close();
    }
}

require_once '../includes/header.php';
?>

<div class="form-container">
    <h1>Generate New Patient Bill</h1>
    <?php if ($error_message): ?><div class="error-banner"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <form action="generate_bill.php" method="POST" id="bill-form">
        <input type="hidden" id="is_new_patient" name="is_new_patient" value="0">
        <input type="hidden" id="patient_uid" name="patient_uid" value="">
        <fieldset class="patient-fieldset">
            <legend>Patient Information</legend>

            <div class="patient-info-card">
                <div class="patient-card-head">
                    <div>
                        <h3 class="patient-card-title">Patient Mode</h3>
                        <p class="patient-card-subtitle">Choose existing patient for quick fetch or register a new patient.</p>
                    </div>
                    <div class="patient-mode-switch" role="radiogroup" aria-label="Patient mode">
                        <label class="mode-pill" for="patient_mode_existing">
                            <input type="radio" name="patient_mode" id="patient_mode_existing" value="existing" checked>
                            <span>Existing Patient</span>
                        </label>
                        <label class="mode-pill" for="patient_mode_new">
                            <input type="radio" name="patient_mode" id="patient_mode_new" value="new">
                            <span>New Patient</span>
                        </label>
                    </div>
                </div>

                <div id="patient-id-top-slot"></div>

                <div class="form-row patient-details-row">
                    <div class="form-group patient-name-col">
                    <label for="patient_name">Patient Name</label>
                    <input type="text" id="patient_name" name="patient_name" required>
                </div>
                <div class="form-group patient-age-col">
                    <label for="patient_age">Age</label>
                    <input type="number" id="patient_age" name="patient_age" required min="0">
                </div>
                 <div class="form-group patient-gender-col">
                    <label for="patient_sex">Gender</label>
                    <select id="patient_sex" name="patient_sex" required>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>
                <div class="form-group">
                    <label for="patient_address">Address (Optional)</label>
                    <textarea id="patient_address" name="patient_address" rows="2"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="patient_city">City</label>
                        <input type="text" id="patient_city" name="patient_city">
                    </div>
                    <div class="form-group">
                        <label for="patient_mobile">Mobile Number</label>
                            <input type="tel" id="patient_mobile" name="patient_mobile" required pattern="\d{10}" maxlength="10" minlength="10" inputmode="numeric" placeholder="10-digit mobile number">
                    </div>
                </div>

                <div id="patient-id-bottom-slot"></div>

                <div id="patient-id-block">
                    <div class="form-row patient-id-row">
                        <div class="form-group patient-id-group">
                            <label for="patient_uid_suffix">Patient ID</label>
                            <div class="uid-input-wrap">
                                <span class="uid-prefix">DC</span>
                                <input type="text" id="patient_uid_suffix" placeholder="YYYYNNNN" maxlength="8" pattern="\d{8}" inputmode="numeric">
                            </div>
                        </div>
                        <div class="form-group patient-id-action-group" id="generate-id-group" style="display:none;">
                            <button type="button" id="btn-generate-uid" class="btn-submit btn-generate-id">Generate New UID</button>
                        </div>
                    </div>

                    <small class="uid-hint">Format: DCYYYYNNNN (example: DC20260001)</small>

                    <div id="uid-status" class="uid-status" style="display:none;"></div>
                </div>
            </div>
        </fieldset>

       <fieldset>
            <legend>Referral Information</legend>
            <div class="form-row">
                <div class="form-group">
                    <label for="referral_type">Referral Type</label>
                    <select id="referral_type" name="referral_type" required>
                        <option value="Self">Self</option>
                        <option value="Doctor">Doctor</option>
                        <option value="Other">Other Source</option>
                    </select>
                </div>
                <div class="form-group" id="doctor-select-group" style="display:none;">
                    <label for="referral_doctor_id">Referring Doctor</label>
                    <select id="referral_doctor_id" name="referral_doctor_id" style="width: 100%;">
                        <option value="">Select Doctor</option>
                        <?php while($doctor = $doctors_result->fetch_assoc()): ?>
                            <option value="<?php echo $doctor['id']; ?>"><?php echo htmlspecialchars($doctor['doctor_name']); ?></option>
                        <?php endwhile; ?>
                        <option value="other">Other...</option>
                    </select>
                </div>
                 <div class="form-group" id="other-doctor-name-group" style="display:none;">
                    <label for="other_doctor_name">Specify Doctor Name</label>
                    <input type="text" id="other_doctor_name" name="other_doctor_name" placeholder="Enter doctor's full name">
                </div>
                <div class="form-group" id="other-source-group" style="display:none;">
                    <label for="referral_source_other_select">Source</label>
                    <select id="referral_source_other_select" name="referral_source_other_select">
                        <option value="">Select Source</option>
                        <option value="Friend">Friend/Family</option>
                        <option value="Newspaper">Newspaper Ad</option>
                        <option value="TV Ad">TV Ad</option>
                        <option value="Social Media">Social Media</option>
                        <option value="Walk-in">Walk-in</option>
                    </select>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Tests Selection</legend>
            <div class="form-row">
                <div class="form-group">
                    <label for="main-test-select">1. Select Test Category</label>
                    <select id="main-test-select">
                        <option value="">-- Select Category --</option>
                        <?php foreach (array_keys($tests_by_category) as $category): ?>
                            <option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="sub-test-select">2. Select Specific Test</label>
                    <select id="sub-test-select" disabled>
                        <option value="">-- Select Category First --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="package-select">3. Select Package</label>
                    <select id="package-select">
                        <option value="">-- Select Package --</option>
                        <?php foreach ($packages_map as $package): ?>
                            <option value="<?php echo (int)$package['id']; ?>">
                                <?php echo htmlspecialchars($package['package_name'] . ' [' . $package['package_code'] . '] - Rs ' . number_format((float)$package['package_price'], 2)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div id="selected-tests">
                <h4>Selected Tests</h4>
                <ul id="selected-tests-list"></ul>
            </div>
            <div id="selected-packages" style="margin-top:1rem; display:none;" aria-hidden="true">
                <h4>Selected Packages</h4>
                <ul id="selected-packages-list"></ul>
            </div>
        </fieldset>

        <fieldset class="discount-details">
            <legend>Discount Details</legend>
            <div class="form-row">
                <div class="form-group">
                    <label for="discount_by">Discount By</label>
                        <select id="discount_by" name="discount_by">
                            <option value="">Select discount </option>
                            <option value="Center">Center</option>
                            <option value="Doctor">Doctor</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="discount">Discount Amount</label>
                    <input type="number" id="discount" name="discount" value="0.00" step="0.01" min="0" readonly>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Billing Details</legend>
            <div class="form-row">
                <div class="form-group"><label for="gross_amount">Gross Amount</label><input type="text" id="gross_amount" name="gross_amount" value="0.00" readonly required></div>
                <div class="form-group"><label for="net_amount">Net Amount</label><input type="text" id="net_amount" name="net_amount" value="0.00" readonly required></div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="payment_mode">Payment Mode</label>
                    <select id="payment_mode" name="payment_mode" required>
                        <option value="Cash">Cash</option>
                        <option value="Card">Card</option>
                        <option value="UPI">UPI</option>
                        <option value="Cash + Card">Cash + Card</option>
                        <option value="UPI + Cash">UPI + Cash</option>
                        <option value="Card + UPI">Card + UPI</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="payment_status">Payment Status</label>
                    <select id="payment_status" name="payment_status" required>
                        <option value="Paid" selected>Paid</option>
                        <option value="Due">Due</option>
                        <option value="Partial Paid">Partial Paid</option>
                    </select>
                </div>
            </div>
            <div class="form-row" id="partial-paid-details" style="display: none; background-color: #f0f8ff; padding: 15px; border-radius: 5px; margin-top: 10px;">
                <div class="form-group">
                    <label for="amount_paid">Amount Paid Now</label>
                    <input type="number" id="amount_paid" name="amount_paid" step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label for="balance_amount">Balance Amount</label>
                    <input type="text" id="balance_amount" name="balance_amount" readonly>
                </div>
            </div>
            <div class="form-row split-payment-details" id="split-payment-details" style="display: none;">
                <div class="form-group" id="split-cash-group" style="display: none;">
                    <label for="split_cash_amount">Cash Amount</label>
                    <input type="number" id="split_cash_amount" name="split_cash_amount" step="0.01" min="0" inputmode="decimal" placeholder="0.00">
                </div>
                <div class="form-group" id="split-card-group" style="display: none;">
                    <label for="split_card_amount">Card Amount</label>
                    <input type="number" id="split_card_amount" name="split_card_amount" step="0.01" min="0" inputmode="decimal" placeholder="0.00">
                </div>
                <div class="form-group" id="split-upi-group" style="display: none;">
                    <label for="split_upi_amount">UPI Amount</label>
                    <input type="number" id="split_upi_amount" name="split_upi_amount" step="0.01" min="0" inputmode="decimal" placeholder="0.00">
                </div>
            </div>
            <div class="split-payment-note" id="split-payment-note" style="display: none;">
                Split Total: <strong id="split-total-display">₹0.00</strong> (Required: <strong id="split-required-display">₹0.00</strong>)
            </div>
        </fieldset>
        
        <input type="hidden" name="selected_tests_json" id="selected_tests_json" required>
        <button type="submit" class="btn-submit">Generate Bill</button>
    </form>
</div>
<script>
    const testsData = <?php echo json_encode($tests_by_category); ?>;
    const packagesData = <?php echo json_encode($packages_map); ?>;

    // --- UID Check / Generate logic ---
    (function() {
        const uidSuffixInput = document.getElementById('patient_uid_suffix');
        const fullUidInput = document.getElementById('patient_uid');
        const isNewPatientInput = document.getElementById('is_new_patient');
        const existingPatientRadio = document.getElementById('patient_mode_existing');
        const newPatientRadio = document.getElementById('patient_mode_new');
        const btnGenerate = document.getElementById('btn-generate-uid');
        const statusDiv = document.getElementById('uid-status');
        const generateIdGroup = document.getElementById('generate-id-group');
        const patientIdBlock = document.getElementById('patient-id-block');
        const patientIdTopSlot = document.getElementById('patient-id-top-slot');
        const patientIdBottomSlot = document.getElementById('patient-id-bottom-slot');
        const billForm = document.getElementById('bill-form');
        const workflowFieldsets = Array.from(document.querySelectorAll('#bill-form fieldset')).filter(function(fs) {
            return !fs.classList.contains('patient-fieldset');
        });
        var lastAutoFetchedUid = '';
        var newUidGenerated = false;

        const patientFieldIds = ['patient_name', 'patient_age', 'patient_sex', 'patient_address', 'patient_city', 'patient_mobile'];

        function setWorkflowLocked(isLocked) {
            workflowFieldsets.forEach(function(fs) {
                fs.style.opacity = isLocked ? '0.55' : '1';
                fs.style.pointerEvents = isLocked ? 'none' : 'auto';
                var controls = fs.querySelectorAll('input, select, textarea, button');
                controls.forEach(function(el) {
                    el.disabled = isLocked;
                });
            });
        }

        function getFullUidFromInput() {
            var suffix = (uidSuffixInput.value || '').replace(/\D/g, '');
            uidSuffixInput.value = suffix;
            if (suffix.length === 0) return '';
            return 'DC' + suffix;
        }

        function syncUidHiddenInput() {
            fullUidInput.value = getFullUidFromInput();
        }

        function showStatus(msg, isSuccess) {
            statusDiv.style.display = 'block';
            statusDiv.textContent = msg;
            statusDiv.style.background = isSuccess ? '#d4edda' : '#f8d7da';
            statusDiv.style.color = isSuccess ? '#155724' : '#721c24';
        }

        function valueOrEmpty(value) {
            return value === null || value === undefined ? '' : value;
        }

        function clearPatientFields() {
            document.getElementById('patient_name').value = '';
            document.getElementById('patient_age').value = '';
            document.getElementById('patient_sex').value = 'Male';
            document.getElementById('patient_address').value = '';
            document.getElementById('patient_city').value = '';
            document.getElementById('patient_mobile').value = '';
        }

        function setPatientFieldsReadonly(isReadonly) {
            patientFieldIds.forEach(function(id) {
                var el = document.getElementById(id);
                if (el) {
                    if (el.tagName === 'SELECT') {
                        el.disabled = isReadonly;
                    } else {
                        el.readOnly = isReadonly;
                    }
                }
            });
        }

        function setPatientFieldsRequired(isRequired) {
            ['patient_name', 'patient_age', 'patient_sex', 'patient_mobile'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.required = isRequired;
            });
        }

        function areNewPatientFieldsValid() {
            var name = (document.getElementById('patient_name').value || '').trim();
            var age = (document.getElementById('patient_age').value || '').trim();
            var sex = (document.getElementById('patient_sex').value || '').trim();
            var mobile = (document.getElementById('patient_mobile').value || '').trim();
            if (name === '' || age === '' || sex === '') {
                return false;
            }
            if (!/^\d+$/.test(age) || parseInt(age, 10) < 0) {
                return false;
            }
            if (!/^\d{10}$/.test(mobile)) {
                return false;
            }
            return true;
        }

        function refreshGenerateUidButton() {
            if (!newPatientRadio.checked) {
                btnGenerate.disabled = true;
                return;
            }

            if (newUidGenerated) {
                btnGenerate.disabled = true;
                btnGenerate.textContent = 'UID Generated';
                return;
            }

            var valid = areNewPatientFieldsValid();
            btnGenerate.disabled = !valid;
            btnGenerate.textContent = 'Generate New UID';

            if (!valid && statusDiv.style.display !== 'block') {
                statusDiv.style.display = 'none';
            }
        }

        function requestNewUid(autoTriggered) {
            statusDiv.style.display = 'block';
            statusDiv.textContent = autoTriggered ? 'Preparing new patient ID...' : 'Generating...';
            statusDiv.style.background = '#fff3cd';
            statusDiv.style.color = '#856404';

            fetch('../api/generate_patient_uid.php')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        uidSuffixInput.value = (data.uid || '').replace(/^DC/, '');
                        syncUidHiddenInput();
                        setPatientFieldsReadonly(true);
                        newUidGenerated = true;
                        setWorkflowLocked(false);
                        refreshGenerateUidButton();
                        showStatus('New patient ID generated: ' + data.uid, true);
                    } else {
                        showStatus('Error generating UID: ' + data.message, false);
                    }
                })
                .catch(function() {
                    showStatus('Network error. Please try again.', false);
                });
        }

        function fetchPatientByUid(uid) {
            statusDiv.style.display = 'block';
            statusDiv.textContent = 'Looking up patient...';
            statusDiv.style.background = '#fff3cd';
            statusDiv.style.color = '#856404';

            fetch('../api/check_patient_uid.php?uid=' + encodeURIComponent(uid))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        var p = data.patient;
                        setPatientFieldsReadonly(false);
                        document.getElementById('patient_name').value = valueOrEmpty(p.name);
                        document.getElementById('patient_age').value = valueOrEmpty(p.age);
                        document.getElementById('patient_sex').value = p.sex || 'Male';
                        document.getElementById('patient_address').value = valueOrEmpty(p.address);
                        document.getElementById('patient_city').value = valueOrEmpty(p.city);
                        document.getElementById('patient_mobile').value = valueOrEmpty(p.mobile_number || p.mobile);
                        uidSuffixInput.value = valueOrEmpty(p.uid || uid).replace(/^DC/, '');
                        syncUidHiddenInput();
                        setPatientFieldsReadonly(true);
                        showStatus('Patient found: ' + valueOrEmpty(p.name), true);
                    } else {
                        clearPatientFields();
                        setPatientFieldsReadonly(false);
                        showStatus(data.message || 'No patient found with this ID.', false);
                    }
                })
                .catch(function() {
                    showStatus('Network error. Please try again.', false);
                });
        }

        function togglePatientMode() {
            var isNew = newPatientRadio.checked;
            isNewPatientInput.value = isNew ? '1' : '0';
            generateIdGroup.style.display = isNew ? 'block' : 'none';
            statusDiv.style.display = 'none';

            if (isNew) {
                patientIdBottomSlot.appendChild(patientIdBlock);
            } else {
                patientIdTopSlot.appendChild(patientIdBlock);
            }

            uidSuffixInput.value = '';
            syncUidHiddenInput();
            newUidGenerated = false;
            btnGenerate.textContent = 'Generate New UID';

            if (isNew) {
                clearPatientFields();
                setPatientFieldsReadonly(false);
                setPatientFieldsRequired(true);
                setWorkflowLocked(true);
            } else {
                clearPatientFields();
                setPatientFieldsReadonly(false);
                setPatientFieldsRequired(false);
                lastAutoFetchedUid = '';
                setWorkflowLocked(false);
            }
            refreshGenerateUidButton();
        }

        uidSuffixInput.addEventListener('input', function() {
            uidSuffixInput.value = uidSuffixInput.value.replace(/\D/g, '').slice(0, 8);
            syncUidHiddenInput();

            if (!existingPatientRadio.checked) return;

            var uid = getFullUidFromInput();

            // Clear status and fields if input is incomplete
            if (!/^DC\d{8}$/.test(uid)) {
                statusDiv.style.display = 'none';
                clearPatientFields();
                setPatientFieldsReadonly(false);
                lastAutoFetchedUid = '';
                return;
            }

            if (uid === lastAutoFetchedUid) return;

            lastAutoFetchedUid = uid;
            fetchPatientByUid(uid);
        });

        uidSuffixInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && existingPatientRadio.checked) {
                e.preventDefault();
                var uid = getFullUidFromInput();
                if (/^DC\d{8}$/.test(uid)) {
                    lastAutoFetchedUid = uid;
                    fetchPatientByUid(uid);
                }
            }
        });

        existingPatientRadio.addEventListener('change', togglePatientMode);
        newPatientRadio.addEventListener('change', togglePatientMode);

        patientFieldIds.forEach(function(id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('input', refreshGenerateUidButton);
            el.addEventListener('change', refreshGenerateUidButton);
        });

        btnGenerate.addEventListener('click', function() {
            if (!areNewPatientFieldsValid()) {
                showStatus('Please fill all required patient details correctly before generating UID.', false);
                refreshGenerateUidButton();
                return;
            }
            requestNewUid(false);
        });

        billForm.addEventListener('submit', function(e) {
            var uid = getFullUidFromInput();
            fullUidInput.value = uid;

            if (uid.length !== 10 || !/^DC\d{8}$/.test(uid)) {
                e.preventDefault();
                showStatus('Patient ID must be in DCYYYYNNNN format.', false);
                return;
            }

            if (newPatientRadio.checked) {
                if (!areNewPatientFieldsValid()) {
                    e.preventDefault();
                    showStatus('Please complete all required patient details before generating UID.', false);
                    refreshGenerateUidButton();
                    return;
                }
                if (!newUidGenerated) {
                    e.preventDefault();
                    showStatus('Please generate a new UID to continue.', false);
                    return;
                }

                // New-patient fields are locked after UID generation; unlock before submit so select values are posted.
                setPatientFieldsReadonly(false);
            }

            if (!newPatientRadio.checked) {
                setPatientFieldsReadonly(false);
            }
        });

        togglePatientMode();
    })();
</script>

<?php require_once '../includes/footer.php'; ?>