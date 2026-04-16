<?php
$page_title = "Create Report from Template";
$required_role = "writer";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$test_id = isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0;
if (!$test_id) {
    die("Error: No test selected.");
}

// Fetch the details of the selected test template
$stmt_test = $conn->prepare("SELECT main_test_name, sub_test_name, price FROM tests WHERE id = ?");
$stmt_test->bind_param("i", $test_id);
$stmt_test->execute();
$test_details = $stmt_test->get_result()->fetch_assoc();
$stmt_test->close();
if (!$test_details) {
    die("Error: Selected test not found.");
}

$error_message = '';

// Handle the form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn->begin_transaction();
    try {
        $patient_identifier_column = get_patient_identifier_insert_column($conn);

        // 1. Create the new patient
        $patient_name = trim($_POST['patient_name'] ?? '');
        $patient_age = isset($_POST['patient_age']) ? (int)$_POST['patient_age'] : 0;
        $patient_sex = trim($_POST['patient_sex'] ?? '');
        $patient_mobile = trim($_POST['patient_mobile'] ?? '0000000000'); // Default dummy if not provided but strictly required by DB

        if (empty($patient_name) || $patient_age <= 0 || empty($patient_sex)) {
             throw new Exception("Please fill in all required patient fields.");
        }
        
        $unknown_addr = 'Unknown';
        $unknown_city = 'Unknown';
        $patient_id = 0;
        $patient_insert_sql = "INSERT INTO patients ({$patient_identifier_column}, name, age, sex, mobile_number, address, city) VALUES (?, ?, ?, ?, ?, ?, ?)";
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $patient_identifier = generate_next_patient_identifier($conn);
            $stmt_patient = $conn->prepare($patient_insert_sql);
            if (!$stmt_patient) {
                throw new Exception("Patient preparation failed: " . $conn->error);
            }

            $stmt_patient->bind_param("ssissss", $patient_identifier, $patient_name, $patient_age, $patient_sex, $patient_mobile, $unknown_addr, $unknown_city);
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

        if ($patient_id <= 0) {
            throw new Exception("Patient insertion failed: unable to generate unique registration ID.");
        }

        // 2. Create a minimal bill record for this report
        $receptionist_id = $_SESSION['user_id'];
        $test_price = $test_details['price'];
        
        // Define default values for likely required fields
        $referral_type = 'Self';
        $discount = 0.00;
        $discount_by = 'Center';
        $payment_mode = 'Cash';
        $amount_paid = 0.00;
        $balance_amount = $test_price;

        $stmt_bill = $conn->prepare("INSERT INTO bills (patient_id, receptionist_id, referral_type, referral_doctor_id, referral_source_other, gross_amount, discount, discount_by, net_amount, amount_paid, balance_amount, payment_mode, payment_status) VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, 'Due')");
        $stmt_bill->bind_param("iisddsddds", $patient_id, $receptionist_id, $referral_type, $test_price, $discount, $discount_by, $test_price, $amount_paid, $balance_amount, $payment_mode);
        $stmt_bill->execute();
        $bill_id = $stmt_bill->insert_id;
        $stmt_bill->close();

        // 3. Create the pending bill_item, which is what the writer needs
        $stmt_item = $conn->prepare("INSERT INTO bill_items (bill_id, test_id, discount_amount, item_status) VALUES (?, ?, 0.00, 0)");
        $stmt_item->bind_param("ii", $bill_id, $test_id);
        $stmt_item->execute();
        $item_id = $stmt_item->insert_id;
        $stmt_item->close();

        $conn->commit();

        // 4. Redirect directly to the fill_report page with the new item ID
        header("Location: fill_report.php?item_id=" . $item_id);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Failed to create report. Error: " . $e->getMessage();
    }
}

require_once '../includes/header.php';
?>

<div class="form-container" style="max-width: 600px;">
    <h1>Create Report for: <?php echo htmlspecialchars($test_details['sub_test_name']); ?></h1>
    <p>Please enter the patient's details below to proceed directly to the report editor.</p>

    <?php if ($error_message): ?>
        <div class="error-banner"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <form action="create_report_from_template.php?test_id=<?php echo $test_id; ?>" method="POST">
        <fieldset>
            <legend>Patient Information</legend>
             <div class="form-group">
                <label for="patient_name">Patient Name</label>
                <input type="text" id="patient_name" name="patient_name" required>
            </div>
            <div class="form-row">
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
                <label for="patient_mobile">Mobile Number</label>
                <input type="text" id="patient_mobile" name="patient_mobile" pattern="\d{10}" title="10 digit mobile number" required placeholder="10-digit mobile #">
            </div>
        </fieldset>
        
        <button type="submit" class="btn-submit">Proceed to Report Editor</button>
        <a href="write_reports.php" class="btn-cancel">Cancel</a>
    </form>
</div>

<?php require_once '../includes/footer.php'; ?>