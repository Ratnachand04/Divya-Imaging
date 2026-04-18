<?php
$page_title = "Manage Professional Charges";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$feedback = '';

// Check if doctor_id is provided
if (!isset($_GET['doctor_id'])) {
    header("Location: manage_doctors.php");
    exit;
}

$doctor_id = (int)$_GET['doctor_id'];

// Fetch doctor details
$stmt = $conn->prepare("SELECT * FROM referral_doctors WHERE id = ?");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$doctor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doctor) {
    header("Location: manage_doctors.php");
    exit;
}

// Handle save commissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['commissions'])) {
    $commissions = $_POST['commissions']; // array: test_id => commission

    // Delete existing commissions for this doctor
    $stmt = $conn->prepare("DELETE FROM doctor_test_commissions WHERE doctor_id = ?");
    $stmt->bind_param("i", $doctor_id);
    $stmt->execute();
    $stmt->close();

    // Insert new commissions
    $stmt = $conn->prepare("INSERT INTO doctor_test_commissions (doctor_id, test_id, commission_percentage) VALUES (?, ?, ?)");
    foreach ($commissions as $test_id => $commission) {
        $test_id = (int)$test_id;
        $commission = trim($commission);
        if ($commission === '') {
            // Skip empty: means use default commission
            continue;
        }
        $commission_val = floatval($commission);
        if ($commission_val >= 0) {
            $stmt->bind_param("iid", $doctor_id, $test_id, $commission_val);
            $stmt->execute();
        }
    }
    $stmt->close();

    $feedback = "<div class='success-banner'>✅ Professional Charges saved successfully!</div>";
}

// Fetch all tests
$tests = $conn->query("SELECT * FROM tests ORDER BY main_test_name, sub_test_name");

// Fetch existing commissions for this doctor
$existing_commissions = [];
$stmt = $conn->prepare("SELECT test_id, commission_percentage FROM doctor_test_commissions WHERE doctor_id = ?");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $existing_commissions[$row['test_id']] = $row['commission_percentage'];
}
$stmt->close();

/**
 * Fetch the base commission percentage for a doctor.
 *
 * @param mysqli $conn The database connection.
 * @param int $doctor_id The ID of the doctor.
 * @return float The base commission percentage.
 */
function getDoctorBaseCommission($conn, $doctor_id) {
    $stmt = $conn->prepare("SELECT commission_percentage FROM referral_doctors WHERE id = ?");
    $stmt->bind_param("i", $doctor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $commission = $result->fetch_assoc()['commission_percentage'] ?? 0.0;
    $stmt->close();
    return (float)$commission;
}

require_once '../includes/header.php';
?>
    <div class="page-container">
        <div class="dashboard-header">
             <h1>Manage Professional Charges for <?php echo htmlspecialchars($doctor['doctor_name']); ?></h1>
             <p>Set specific professional charge percentages for individual tests. Leave blank to use default.</p>
        </div>
        <?php echo $feedback; ?>

        <form action="manage_doctor_commissions.php?doctor_id=<?php echo $doctor_id; ?>" method="POST">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Main Category</th>
                        <th>Test Name</th>
                        <th>Default Rate (<?php echo number_format($doctor['commission_percentage'], 2); ?>%)</th>
                        <th>Doctor's Rate (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($test = $tests->fetch_assoc()): 
                        $commission_val = $existing_commissions[$test['id']] ?? '';
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($test['main_test_name']); ?></td>
                        <td><?php echo htmlspecialchars($test['sub_test_name']); ?></td>
                        <td><?php echo number_format($doctor['commission_percentage'], 2); ?>%</td>
                        <td>
                            <input type="number" name="commissions[<?php echo $test['id']; ?>]" step="0.01" min="0" max="100" 
                                value="<?php echo htmlspecialchars($commission_val); ?>" placeholder="Leave blank for default">
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>

            <button type="submit" class="btn-submit">Save Charges</button>
            <a href="manage_doctors.php" class="btn-cancel">Back to Doctors</a>
        </form>
    </div>
<?php require_once '../includes/footer.php'; ?>
