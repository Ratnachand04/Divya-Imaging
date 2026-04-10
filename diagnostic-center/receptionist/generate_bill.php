<?php
// --- PDF Library Autoloader ---
require_once '../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$page_title = "Generate Bill";

$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/patient_registration_helper.php';

ensureBillItemScreeningsTable($conn);
ensureBillItemDiscountColumn($conn);
ensure_patient_registration_schema($conn);

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
        "SELECT b.*, p.name as patient_name, p.age, p.sex, p.mobile_number, p.emergency_contact_person, rd.doctor_name as referral_doctor_name
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
    $emergency_contact = !empty($bill['emergency_contact_person']) ? htmlspecialchars($bill['emergency_contact_person']) : '-';

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
            <tr><td><strong>Patient Name:</strong> {$bill['patient_name']}</td><td><strong>Mobile No:</strong> {$bill['mobile_number']}</td></tr>
            <tr><td><strong>Age & Gender:</strong> {$bill['age']} / {$bill['sex']}</td><td></td></tr>
            <tr><td><strong>Emergency Contact:</strong> {$emergency_contact}</td><td></td></tr>
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
        $name_regex = '/^[A-Za-z][A-Za-z .\'\-]{1,99}$/';
        $city_regex = '/^[A-Za-z][A-Za-z .\'\-]{1,99}$/';
        $phone_regex = '/^\d{10}$/';

        $is_new_patient = isset($_POST['is_new_patient']) && $_POST['is_new_patient'] === '1';
        $patient_unique_id = normalize_patient_unique_id($_POST['patient_unique_id'] ?? '');
        $patient_name = trim($_POST['patient_name'] ?? '');
        $patient_age = isset($_POST['patient_age']) ? (int)$_POST['patient_age'] : 0;
        $patient_sex = trim($_POST['patient_sex'] ?? '');
        $patient_address = trim($_POST['patient_address'] ?? '');
        $patient_city = trim($_POST['patient_city'] ?? '');
        $patient_mobile = trim($_POST['patient_mobile'] ?? '');
        $emergency_contact_person = trim($_POST['emergency_contact_person'] ?? '');

        if ($is_new_patient) {
            if ($patient_name === '' || $patient_age < 0 || $patient_sex === '' || $patient_mobile === '' || $emergency_contact_person === '') {
                throw new Exception("For new patient, name, age, gender, mobile number, and emergency contact are required.");
            }
            if (!preg_match($name_regex, $patient_name)) {
                throw new Exception("Patient name can contain only letters, spaces, dot, apostrophe, and hyphen.");
            }
            if (!preg_match($phone_regex, $patient_mobile)) {
                throw new Exception("Mobile number must be exactly 10 digits.");
            }
            if ($patient_city !== '' && !preg_match($city_regex, $patient_city)) {
                throw new Exception("City can contain only letters, spaces, dot, apostrophe, and hyphen.");
            }
            if (!preg_match($name_regex, $emergency_contact_person)) {
                throw new Exception("Emergency contact can contain only letters, spaces, dot, apostrophe, and hyphen.");
            }

            $lock_ok = 0;
            $lock_result = $conn->query("SELECT GET_LOCK('dc_patient_unique_id_lock', 10) AS lock_status");
            if ($lock_result) {
                $lock_row = $lock_result->fetch_assoc();
                $lock_ok = isset($lock_row['lock_status']) ? (int)$lock_row['lock_status'] : 0;
                $lock_result->close();
            }

            if ($lock_ok !== 1) {
                throw new Exception('Could not generate patient ID at this time. Please try again.');
            }

            $patient_unique_id = generate_next_patient_unique_id($conn);
            $stmt_patient = $conn->prepare("INSERT INTO patients (patient_unique_id, name, age, sex, address, city, mobile_number, emergency_contact_person) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt_patient) {
                throw new Exception("Failed to prepare patient insert: " . $conn->error);
            }
            $stmt_patient->bind_param("ssisssss", $patient_unique_id, $patient_name, $patient_age, $patient_sex, $patient_address, $patient_city, $patient_mobile, $emergency_contact_person);
            $stmt_patient->execute();
            $patient_id = $stmt_patient->insert_id;
            $stmt_patient->close();
            $conn->query("DO RELEASE_LOCK('dc_patient_unique_id_lock')");
        } else {
            if ($patient_unique_id === '') {
                throw new Exception("Enter patient unique ID for existing patient.");
            }

            if (!is_valid_patient_unique_id($patient_unique_id)) {
                throw new Exception("Patient ID must be in DCYYYYNNNN format.");
            }

            $stmt_existing = $conn->prepare("SELECT id, name, age, sex, address, city, mobile_number, emergency_contact_person FROM patients WHERE patient_unique_id = ? AND (is_archived = 0 OR is_archived IS NULL) LIMIT 1");
            if (!$stmt_existing) {
                throw new Exception("Failed to prepare patient lookup: " . $conn->error);
            }
            $stmt_existing->bind_param("s", $patient_unique_id);
            $stmt_existing->execute();
            $existing_patient = $stmt_existing->get_result()->fetch_assoc();
            $stmt_existing->close();

            if (!$existing_patient) {
                throw new Exception("Patient not found for unique ID {$patient_unique_id}.");
            }

            $patient_id = (int)$existing_patient['id'];
            $patient_name = $existing_patient['name'];
            $patient_age = (int)$existing_patient['age'];
            $patient_sex = $existing_patient['sex'];
            $patient_address = (string)($existing_patient['address'] ?? '');
            $patient_city = (string)($existing_patient['city'] ?? '');
            $patient_mobile = (string)($existing_patient['mobile_number'] ?? '');
            $emergency_contact_person = (string)($existing_patient['emergency_contact_person'] ?? '');
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
        $conn->query("DO RELEASE_LOCK('dc_patient_unique_id_lock')");
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
        <fieldset>
            <legend>Patient Information</legend>
            <div class="form-row patient-mode-row">
                <div class="form-group patient-checkbox-group">
                    <label for="is_new_patient" class="patient-switch-label">
                        <input type="checkbox" id="is_new_patient" name="is_new_patient" value="1">
                        New Patient
                    </label>
                </div>
                <div class="form-group generated-id-wrap" id="new-patient-id-wrap" style="display:none;">
                    <label class="generated-id-label">Auto-Generated Patient ID</label>
                    <div class="patient-id-badge" id="generated-patient-id">Loading...</div>
                </div>
            </div>

            <div class="form-row existing-patient-row" id="existing-patient-row">
                <div class="form-group">
                    <label for="patient_unique_id">Existing Patient Unique ID</label>
                    <input type="text" id="patient_unique_id" name="patient_unique_id" placeholder="DC20250001" maxlength="12">
                    <small class="patient-id-help">When New Patient is unchecked, enter ID and details will be fetched automatically.</small>
                </div>
                <div class="form-group patient-fetch-group">
                    <button type="button" id="fetch-patient-btn" class="btn-action btn-view">Fetch</button>
                </div>
            </div>

            <div id="patient-lookup-message" class="error-banner" style="display:none;"></div>

             <div class="form-row">
                <div class="form-group">
                    <label for="patient_name">Patient Name</label>
                    <input type="text" id="patient_name" name="patient_name" required oninput="this.value=this.value.replace(/[^A-Za-z .'-]/g,'').replace(/\s{2,}/g,' ')" onkeypress="if(!/[A-Za-z .'-]/.test(event.key)){event.preventDefault();}">
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
                    <input type="text" id="patient_city" name="patient_city" pattern="[A-Za-z][A-Za-z .'-]{1,99}" title="City should contain letters only (plus space, dot, apostrophe, hyphen)" oninput="this.value=this.value.replace(/[^A-Za-z .'-]/g,'').replace(/\s{2,}/g,' ')" onkeypress="if(!/[A-Za-z .'-]/.test(event.key)){event.preventDefault();}">
                </div>
                <div class="form-group">
                    <label for="patient_mobile">Mobile Number</label>
                        <input type="tel" id="patient_mobile" name="patient_mobile" required pattern="\d{10}" maxlength="10" minlength="10" inputmode="numeric" placeholder="10-digit mobile number" title="Enter exactly 10 digits" oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)" onkeypress="if(!/[0-9]/.test(event.key)){event.preventDefault();}">
                </div>
                <div class="form-group">
                    <label for="emergency_contact_person">Emergency Contact</label>
                    <input type="text" id="emergency_contact_person" name="emergency_contact_person" placeholder="Emergency contact person" pattern="[A-Za-z][A-Za-z .'-]{1,99}" title="Emergency contact should contain letters only (plus space, dot, apostrophe, hyphen)" oninput="this.value=this.value.replace(/[^A-Za-z .'-]/g,'').replace(/\s{2,}/g,' ')" onkeypress="if(!/[A-Za-z .'-]/.test(event.key)){event.preventDefault();}">
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
    const testsData = <?php echo json_encode($tests_by_category); ?>;

    (function () {
        const uniqueIdInput = document.getElementById('patient_unique_id');
        const fetchBtn = document.getElementById('fetch-patient-btn');
        const isNewPatientCheckbox = document.getElementById('is_new_patient');
        const messageBox = document.getElementById('patient-lookup-message');
        const existingPatientRow = document.getElementById('existing-patient-row');
        const newPatientIdWrap = document.getElementById('new-patient-id-wrap');
        const generatedPatientId = document.getElementById('generated-patient-id');
        const patientFields = ['patient_name', 'patient_age', 'patient_sex', 'patient_address', 'patient_city', 'patient_mobile', 'emergency_contact_person']
            .map(id => document.getElementById(id))
            .filter(Boolean);

        function setPatientFieldsReadonly(isReadonly) {
            patientFields.forEach((field) => {
                field.readOnly = isReadonly;
                if (field.tagName === 'SELECT') {
                    field.disabled = isReadonly;
                }
            });
        }

        function clearPatientFields() {
            patientFields.forEach((field) => {
                if (field.tagName === 'SELECT') {
                    field.selectedIndex = 0;
                } else {
                    field.value = '';
                }
            });
        }

        function showMessage(text, isError) {
            if (!messageBox) return;
            messageBox.style.display = text ? 'block' : 'none';
            messageBox.textContent = text || '';
            messageBox.className = isError ? 'error-banner' : 'success-banner';
        }

        function normalizePatientIdInput(value) {
            const raw = (value || '').trim().toUpperCase();
            if (!raw) return '';
            return raw.startsWith('DC') ? raw : ('DC' + raw);
        }

        function isValidPatientIdFormat(value) {
            return /^DC\d{8}$/.test(value || '');
        }

        async function fetchNextPatientId() {
            if (!generatedPatientId) return;

            generatedPatientId.textContent = 'Loading...';
            try {
                const response = await fetch('ajax_next_patient_id.php');
                const data = await response.json();
                if (!response.ok || !data.success || !data.patient_unique_id) {
                    throw new Error(data.message || 'Failed to generate patient ID.');
                }
                generatedPatientId.textContent = data.patient_unique_id;
            } catch (err) {
                generatedPatientId.textContent = 'Not available';
                showMessage(err.message || 'Could not load new patient ID.', true);
            }
        }

        function applyPatientMode() {
            const isNew = !!(isNewPatientCheckbox && isNewPatientCheckbox.checked);
            const emergencyContact = document.getElementById('emergency_contact_person');

            if (uniqueIdInput) {
                uniqueIdInput.disabled = isNew;
                uniqueIdInput.required = !isNew;
            }
            if (fetchBtn) {
                fetchBtn.disabled = isNew;
            }
            if (existingPatientRow) {
                existingPatientRow.style.display = isNew ? 'none' : 'flex';
            }
            if (newPatientIdWrap) {
                newPatientIdWrap.style.display = isNew ? 'block' : 'none';
            }

            if (isNew) {
                clearPatientFields();
                setPatientFieldsReadonly(false);
                if (emergencyContact) {
                    emergencyContact.required = true;
                }
                showMessage('', false);
                fetchNextPatientId();
            } else {
                setPatientFieldsReadonly(true);
                if (emergencyContact) {
                    emergencyContact.required = false;
                }
                showMessage('Use patient unique ID and click Fetch.', false);
            }
        }

        async function fetchPatient() {
            const patientId = normalizePatientIdInput(uniqueIdInput ? uniqueIdInput.value : '');
            if (!patientId) {
                showMessage('Please enter patient unique ID first.', true);
                return;
            }

            if (uniqueIdInput) {
                uniqueIdInput.value = patientId;
            }

            if (!isValidPatientIdFormat(patientId)) {
                showMessage('Patient ID must be in DCYYYYNNNN format.', true);
                return;
            }

            try {
                const response = await fetch(`ajax_patient_lookup.php?patient_unique_id=${encodeURIComponent(patientId)}`);
                const data = await response.json();

                if (!response.ok || !data.success || !data.patient) {
                    throw new Error(data.message || 'Patient not found.');
                }

                const p = data.patient;
                const patientName = document.getElementById('patient_name');
                const patientAge = document.getElementById('patient_age');
                const patientSex = document.getElementById('patient_sex');
                const patientAddress = document.getElementById('patient_address');
                const patientCity = document.getElementById('patient_city');
                const patientMobile = document.getElementById('patient_mobile');
                const emergencyContact = document.getElementById('emergency_contact_person');

                if (patientName) patientName.value = p.name || '';
                if (patientAge) patientAge.value = p.age || '';
                if (patientSex) patientSex.value = p.sex || 'Male';
                if (patientAddress) patientAddress.value = p.address || '';
                if (patientCity) patientCity.value = p.city || '';
                if (patientMobile) patientMobile.value = p.mobile_number || '';
                if (emergencyContact) emergencyContact.value = p.emergency_contact_person || '';

                showMessage(`Loaded patient: ${p.name} (${p.patient_unique_id})`, false);
            } catch (err) {
                clearPatientFields();
                showMessage(err.message || 'Failed to fetch patient details.', true);
            }
        }

        if (isNewPatientCheckbox) {
            isNewPatientCheckbox.addEventListener('change', applyPatientMode);
        }
        if (fetchBtn) {
            fetchBtn.addEventListener('click', fetchPatient);
        }
        if (uniqueIdInput) {
            uniqueIdInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !(isNewPatientCheckbox && isNewPatientCheckbox.checked)) {
                    event.preventDefault();
                    fetchPatient();
                }
            });
            uniqueIdInput.addEventListener('blur', function () {
                if (!(isNewPatientCheckbox && isNewPatientCheckbox.checked) && uniqueIdInput.value.trim() !== '') {
                    uniqueIdInput.value = normalizePatientIdInput(uniqueIdInput.value);
                    fetchPatient();
                }
            });
        }

        applyPatientMode();
    })();
</script>
<?php require_once '../includes/footer.php'; ?>