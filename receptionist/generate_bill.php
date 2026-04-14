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
        "SELECT bi.id AS bill_item_id, t.main_test_name, t.sub_test_name, t.price, COALESCE(bis.screening_amount, 0) AS screening_amount
         FROM bill_items bi
         JOIN tests t ON bi.test_id = t.id
         LEFT JOIN bill_item_screenings bis ON bis.bill_item_id = bi.id
         WHERE bi.bill_id = ? AND bi.item_status = 0"
    );
    $items_stmt->bind_param("i", $bill_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    $items_html = '';
    while($item = $items_result->fetch_assoc()) {
        $raw_name = trim(preg_replace('/\s+/', ' ', $item['main_test_name'] . ' ' . $item['sub_test_name']));
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
        $patient_name = trim($_POST['patient_name'] ?? '');
        $patient_age = isset($_POST['patient_age']) ? (int)$_POST['patient_age'] : 0;
        $patient_sex = trim($_POST['patient_sex'] ?? '');
        $patient_address = trim($_POST['patient_address'] ?? '');
        $patient_city = trim($_POST['patient_city'] ?? '');
        $patient_mobile = trim($_POST['patient_mobile'] ?? '');

        if ($patient_name === '' || $patient_age < 0 || $patient_sex === '' || $patient_mobile === '') {
            throw new Exception("Patient name, age, gender, and mobile number are required.");
        }
        if (!preg_match('/^\d{10}$/', $patient_mobile)) {
            throw new Exception("Mobile number must be exactly 10 digits.");
        }

        // Check if an existing patient was selected
        $existing_patient_id = isset($_POST['existing_patient_id']) ? (int)$_POST['existing_patient_id'] : 0;
        $patient_id = 0;

        if ($existing_patient_id > 0) {
            // Use the existing patient
            $patient_id = $existing_patient_id;
        } else {
            // New patient - use the submitted uid or generate one
            $submitted_uid = trim($_POST['patient_uid'] ?? '');
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $uid = ($attempt === 0 && $submitted_uid !== '') ? $submitted_uid : generate_patient_uid($conn);
                $stmt_patient = $conn->prepare("INSERT INTO patients (uid, name, age, sex, address, city, mobile_number) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt_patient) {
                    throw new Exception("Patient preparation failed: " . $conn->error);
                }
                $stmt_patient->bind_param("ssissss", $uid, $patient_name, $patient_age, $patient_sex, $patient_address, $patient_city, $patient_mobile);

                if ($stmt_patient->execute()) {
                    $patient_id = $stmt_patient->insert_id;
                    $stmt_patient->close();
                    break;
                }

                $insert_error_no = $stmt_patient->errno;
                $insert_error = $stmt_patient->error;
                $stmt_patient->close();

                if ($insert_error_no !== 1062) {
                    throw new Exception("Patient insertion failed: " . $insert_error);
                }
            }
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

        $selected_tests_raw = json_decode($_POST['selected_tests_json'] ?? '', true);
        if (!is_array($selected_tests_raw) || empty($selected_tests_raw)) {
            throw new Exception("No tests were selected.");
        }

        $structured_tests = [];
        foreach ($selected_tests_raw as $entry) {
            if (!is_array($entry)) {
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

        if (empty($structured_tests)) {
            throw new Exception("No valid tests were selected.");
        }

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
        $test_price_map = [];
        while ($row = $price_result->fetch_assoc()) {
            $test_price_map[(int)$row['id']] = (float)$row['price'];
        }
        $stmt_prices->close();

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

        $gross_amount = round($gross_amount, 2);
        $total_discount = round(min($total_discount, $gross_amount), 2);
        $net_amount = round($gross_amount - $total_discount, 2);

        $allowed_discount_sources = ['Center', 'Doctor'];
        $discount_by_input = trim($_POST['discount_by'] ?? '');
        if (!in_array($discount_by_input, $allowed_discount_sources, true)) {
            throw new Exception("Please select who provided the discount.");
        }
        $discount_by = $discount_by_input;
        $payment_mode_allowed = ['Cash', 'Card', 'UPI', 'Other'];
        $payment_mode = trim($_POST['payment_mode'] ?? 'Cash');
        if (!in_array($payment_mode, $payment_mode_allowed, true)) {
            $payment_mode = 'Cash';
        }

        $payment_status = trim($_POST['payment_status'] ?? 'Paid');
        if (!in_array($payment_status, ['Paid', 'Due', 'Half Paid'], true)) {
            $payment_status = 'Paid';
        }

        $amount_paid = 0.00;
        $balance_amount = $net_amount;

        if ($payment_status === 'Half Paid') {
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

        $stmt_bill = $conn->prepare("INSERT INTO bills (patient_id, receptionist_id, referral_type, referral_doctor_id, referral_source_other, gross_amount, discount, discount_by, net_amount, amount_paid, balance_amount, payment_mode, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt_bill) {
            throw new Exception("Failed to prepare bill insert statement: " . $conn->error);
        }
        $stmt_bill->bind_param("iisisddsdddss", $patient_id, $receptionist_id, $referral_type, $referral_doctor_id, $referral_source_other, $gross_amount, $total_discount, $discount_by, $net_amount, $amount_paid, $balance_amount, $payment_mode, $payment_status);
        $stmt_bill->execute();
        $bill_id = $stmt_bill->insert_id;
        $stmt_bill->close();

        $stmt_items = $conn->prepare("INSERT INTO bill_items (bill_id, test_id, discount_amount) VALUES (?, ?, ?)");
        if (!$stmt_items) {
            throw new Exception("Failed to prepare bill items statement: " . $conn->error);
        }
        $stmt_items->bind_param("iid", $bill_id_param, $test_id_param, $discount_amount_param);

        $stmt_screening = $conn->prepare("INSERT INTO bill_item_screenings (bill_item_id, screening_amount) VALUES (?, ?)");
        if (!$stmt_screening) {
            throw new Exception("Failed to prepare screening items statement: " . $conn->error);
        }
        $stmt_screening->bind_param("id", $bill_item_id_param, $screening_amount_param);

        foreach ($structured_tests as $test_entry) {
            $bill_id_param = $bill_id;
            $test_id_param = $test_entry['id'];
            $discount_amount_param = $test_entry['discount'];
            $stmt_items->execute();
            $bill_item_id_param = $stmt_items->insert_id;

            $screening_amount_param = $test_entry['screening'];
            if ($bill_item_id_param && $screening_amount_param > 0) {
                $stmt_screening->execute();
            }
        }

        $stmt_items->close();
        $stmt_screening->close();

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

require_once '../includes/header.php';
?>

<div class="form-container">
    <h1>Generate New Patient Bill</h1>
    <?php if ($error_message): ?><div class="error-banner"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <form action="generate_bill.php" method="POST" id="bill-form">
        <input type="hidden" id="existing_patient_id" name="existing_patient_id" value="0">
        <fieldset>
            <legend>Patient Information</legend>
            <div class="form-row" style="align-items:flex-end; margin-bottom:15px;">
                <div class="form-group" style="flex:2;">
                    <label for="patient_uid">Patient UID</label>
                    <input type="text" id="patient_uid" name="patient_uid" placeholder="e.g. DC20260001" style="text-transform:uppercase;">
                </div>
                <div class="form-group" style="flex:0 0 auto;">
                    <button type="button" id="btn-check-uid" class="btn-submit" style="margin-top:0;">Check</button>
                </div>
                <div class="form-group" style="flex:0 0 auto;">
                    <button type="button" id="btn-generate-uid" class="btn-submit" style="margin-top:0; background:#28a745;">Generate New</button>
                </div>
            </div>
            <div id="uid-status" style="margin-bottom:10px; padding:8px; border-radius:4px; display:none;"></div>
             <div class="form-row">
                <div class="form-group">
                    <label for="patient_name">Patient Name</label>
                    <input type="text" id="patient_name" name="patient_name" required>
                </div>
                <div class="form-group">
                    <label for="patient_age">Age</label>
                    <input type="number" id="patient_age" name="patient_age" required min="0">
                </div>
                 <div class="form-group">
                    <label for="patient_sex">Gender</label>
                    <select id="patient_sex" name="patient_sex" required>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="patient_address">Address</label>
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
            </div>
            <div id="selected-tests">
                <h4>Selected Tests</h4>
                <ul id="selected-tests-list"></ul>
            </div>
        </fieldset>

        <fieldset class="discount-details">
            <legend>Discount Details</legend>
            <div class="form-row">
                <div class="form-group">
                    <label for="discount_by">Discount By</label>
                        <select id="discount_by" name="discount_by" required>
                            <option value="">Select Discount Type</option>
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
                        <option value="Cash">Cash</option><option value="Card">Card</option><option value="UPI">UPI</option><option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="payment_status">Payment Status</label>
                    <select id="payment_status" name="payment_status" required>
                        <option value="Paid" selected>Paid</option>
                        <option value="Due">Due</option>
                        <option value="Half Paid">Half Paid</option>
                    </select>
                </div>
            </div>
            <div class="form-row" id="half-paid-details" style="display: none; background-color: #f0f8ff; padding: 15px; border-radius: 5px; margin-top: 10px;">
                <div class="form-group">
                    <label for="amount_paid">Amount Paid Now</label>
                    <input type="number" id="amount_paid" name="amount_paid" step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label for="balance_amount">Balance Amount</label>
                    <input type="text" id="balance_amount" name="balance_amount" readonly>
                </div>
            </div>
        </fieldset>
        
        <input type="hidden" name="selected_tests_json" id="selected_tests_json" required>
        <button type="submit" class="btn-submit">Generate Bill</button>
    </form>
</div>
<script>
    const testsData = <?php echo json_encode($tests_by_category, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE) ?: '{}'; ?>;

    // --- UID Check / Generate logic ---
    (function() {
        const uidInput = document.getElementById('patient_uid');
        const btnCheck = document.getElementById('btn-check-uid');
        const btnGenerate = document.getElementById('btn-generate-uid');
        const statusDiv = document.getElementById('uid-status');
        const hiddenPatientId = document.getElementById('existing_patient_id');

        if (!uidInput || !btnCheck || !btnGenerate || !statusDiv || !hiddenPatientId) {
            return;
        }

        function parseJsonResponse(response) {
            if (!response.ok) {
                throw new Error('Request failed with status ' + response.status + '.');
            }
            return response.text().then(function(body) {
                try {
                    return JSON.parse(body);
                } catch (e) {
                    throw new Error('Server returned an invalid response.');
                }
            });
        }

        function showStatus(msg, isSuccess) {
            statusDiv.style.display = 'block';
            statusDiv.textContent = msg;
            statusDiv.style.background = isSuccess ? '#d4edda' : '#f8d7da';
            statusDiv.style.color = isSuccess ? '#155724' : '#721c24';
        }

        function clearPatientFields() {
            document.getElementById('patient_name').value = '';
            document.getElementById('patient_age').value = '';
            document.getElementById('patient_sex').value = 'Male';
            document.getElementById('patient_address').value = '';
            document.getElementById('patient_city').value = '';
            document.getElementById('patient_mobile').value = '';
            hiddenPatientId.value = '0';
            setPatientFieldsReadonly(false);
        }

        function setPatientFieldsReadonly(isReadonly) {
            ['patient_name', 'patient_age', 'patient_sex', 'patient_address', 'patient_city', 'patient_mobile'].forEach(function(id) {
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

        btnCheck.addEventListener('click', function() {
            var uid = uidInput.value.trim().toUpperCase();
            if (uid === '') { showStatus('Please enter a UID to check.', false); return; }
            statusDiv.style.display = 'block';
            statusDiv.textContent = 'Checking...';
            statusDiv.style.background = '#fff3cd';
            statusDiv.style.color = '#856404';

            fetch('../api/check_patient_uid.php?uid=' + encodeURIComponent(uid))
                .then(parseJsonResponse)
                .then(function(data) {
                    if (data.success) {
                        var p = data.patient;
                        document.getElementById('patient_name').value = p.name || '';
                        document.getElementById('patient_age').value = p.age || '';
                        document.getElementById('patient_sex').value = p.sex || 'Male';
                        document.getElementById('patient_address').value = p.address || '';
                        document.getElementById('patient_city').value = p.city || '';
                        document.getElementById('patient_mobile').value = p.mobile_number || '';
                        hiddenPatientId.value = p.id;
                        uidInput.value = p.uid;
                        setPatientFieldsReadonly(true);
                        showStatus('Patient found! Info auto-filled. (Existing patient will be reused)', true);
                    } else {
                        clearPatientFields();
                        showStatus(data.message + ' You can fill in the details for a new patient or click "Generate New".', false);
                    }
                })
                .catch(function(error) {
                    showStatus(error && error.message ? error.message : 'Network error. Please try again.', false);
                });
        });

        btnGenerate.addEventListener('click', function() {
            statusDiv.style.display = 'block';
            statusDiv.textContent = 'Generating...';
            statusDiv.style.background = '#fff3cd';
            statusDiv.style.color = '#856404';

            fetch('../api/generate_patient_uid.php')
                .then(parseJsonResponse)
                .then(function(data) {
                    if (data.success) {
                        uidInput.value = data.uid;
                        clearPatientFields();
                        showStatus('New UID generated: ' + data.uid + '. Please fill in the patient details below.', true);
                    } else {
                        showStatus('Error generating UID: ' + data.message, false);
                    }
                })
                .catch(function(error) {
                    showStatus(error && error.message ? error.message : 'Network error. Please try again.', false);
                });
        });
    })();
</script>
<?php require_once '../includes/footer.php'; ?>