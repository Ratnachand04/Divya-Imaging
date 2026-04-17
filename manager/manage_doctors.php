<?php
$page_title = "Manage Doctors";
$required_role = "manager";
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$referral_doctors_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd') : '`referral_doctors` rd';
$doctor_test_payables_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'doctor_test_payables', 'dtp') : '`doctor_test_payables` dtp';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';

$feedback = '';

// --- Handle Add/Edit Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        $doctor_id = isset($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : 0;
        $doctor_name = trim($_POST['doctor_name']);
        $hospital_name = trim($_POST['hospital_name']);
        $phone_number = trim($_POST['phone_number']);
        $email = trim($_POST['email']);
    $address = trim($_POST['address']);
        $city = trim($_POST['city']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $payables = $_POST['payables'] ?? [];
        // --- REMOVED: default_payable_amount variable ---

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format provided.");
        }

        if ($doctor_id) { // UPDATE LOGIC
            // --- UPDATED: Removed default_payable_amount ---
            $stmt = $conn->prepare("UPDATE referral_doctors SET doctor_name=?, hospital_name=?, phone_number=?, email=?, address=?, city=?, is_active=? WHERE id=?");
            // --- UPDATED: Removed 'd' from bind_param types ---
            $stmt->bind_param("ssssssii", $doctor_name, $hospital_name, $phone_number, $email, $address, $city, $is_active, $doctor_id);
        } else { // ADD NEW LOGIC
            // --- UPDATED: Removed default_payable_amount ---
            $stmt = $conn->prepare("INSERT INTO referral_doctors (doctor_name, hospital_name, phone_number, email, address, city, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
            // --- UPDATED: Removed 'd' from bind_param types ---
            $stmt->bind_param("ssssssi", $doctor_name, $hospital_name, $phone_number, $email, $address, $city, $is_active);
        }
        $stmt->execute();
        if (!$doctor_id) $doctor_id = $stmt->insert_id;
        $stmt->close();

        // Handle Per-Test Payables (Unchanged logic, uses test's default as fallback implicitly)
        $stmt_del = $conn->prepare("DELETE FROM doctor_test_payables WHERE doctor_id = ?");
        $stmt_del->bind_param("i", $doctor_id);
        $stmt_del->execute();
        $stmt_del->close();
        $stmt_ins = $conn->prepare("INSERT INTO doctor_test_payables (doctor_id, test_id, payable_amount) VALUES (?, ?, ?)");
        foreach ($payables as $test_id => $payable) {
            if (trim($payable) !== '' && is_numeric($payable)) {
                $stmt_ins->bind_param("iid", $doctor_id, (int)$test_id, (float)$payable);
                $stmt_ins->execute();
            }
        }
        $stmt_ins->close();
        $conn->commit();
        $feedback = "<div class='success-banner'>Doctor saved successfully!</div>";
    } catch (Exception $e) {
        $conn->rollback();
        $feedback = "<div class='error-banner'>An error occurred: " . $e->getMessage() . "</div>";
    }
}

// --- Fetch data for the page ---
$edit_doctor = null;
$existing_payables = [];
$is_editing = isset($_GET['edit']);

if ($is_editing) {
    $edit_id = (int)$_GET['edit'];
    // --- UPDATED: Removed default_payable_amount from SELECT ---
    $edit_stmt = $conn->prepare("SELECT rd.id, rd.doctor_name, rd.hospital_name, rd.phone_number, rd.email, rd.address, rd.city, rd.is_active FROM {$referral_doctors_source} WHERE rd.id = ?");
    $edit_stmt->bind_param("i", $edit_id);
    $edit_stmt->execute();
    $edit_doctor = $edit_stmt->get_result()->fetch_assoc();
    $edit_stmt->close();

    // Fetch existing specific payables for this doctor
    $payable_res = $conn->query("SELECT dtp.test_id, dtp.payable_amount FROM {$doctor_test_payables_source} WHERE dtp.doctor_id = $edit_id");
    while($row = $payable_res->fetch_assoc()) {
        $existing_payables[$row['test_id']] = $row['payable_amount'];
    }
}

// Fetch tests including their default payable amount (needed for placeholder)
$tests_result = $conn->query("SELECT t.id, t.main_test_name, t.sub_test_name, t.price, t.default_payable_amount FROM {$tests_source} ORDER BY t.main_test_name, t.sub_test_name");
$tests = $tests_result->fetch_all(MYSQLI_ASSOC);
$test_categories = [];
foreach ($tests as $test) { $test_categories[$test['main_test_name']] = true; } // Collect unique categories
$test_categories = array_keys($test_categories);
sort($test_categories);

// --- Logic for Doctor List Filter and Search ---
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
// --- UPDATED: Removed default_payable_amount from SELECT ---
$doctors_query = "SELECT rd.id, rd.doctor_name, rd.phone_number, rd.email, rd.is_active FROM {$referral_doctors_source}";
$where_clauses = [];
$params = [];
$types = '';
if ($filter_status !== 'all') {
    $where_clauses[] = "rd.is_active = ?";
    $params[] = ($filter_status === 'active') ? 1 : 0;
    $types .= 'i';
}
if (!empty($search_term)) {
    $where_clauses[] = "(rd.doctor_name LIKE ? OR rd.phone_number LIKE ? OR rd.email LIKE ?)";
    $like_term = "%{$search_term}%";
    $params[] = $like_term; $params[] = $like_term; $params[] = $like_term;
    $types .= 'sss';
}
if (!empty($where_clauses)) {
    $doctors_query .= " WHERE " . implode(' AND ', $where_clauses);
}
$doctors_query .= " ORDER BY rd.doctor_name ASC";
$stmt_doctors = $conn->prepare($doctors_query);
if (!empty($params)) {
    $stmt_doctors->bind_param($types, ...$params);
}
$stmt_doctors->execute();
$doctors = $stmt_doctors->get_result();

require_once '../includes/header.php';
?>



<div class="page-container">
    <div class="dashboard-header">
        <h1>Manage Referring Doctors</h1>
        <button id="toggle-form-btn" class="btn-submit"><?php echo $is_editing ? 'Close Form' : 'Add New Doctor'; ?></button>
    </div>
    
    <?php echo $feedback; ?>

    <div class="management-form form-container-collapsible <?php echo $is_editing ? 'visible' : ''; ?>" id="add-edit-form-container">
        <h3><?php echo $edit_doctor ? 'Edit Doctor Details' : 'Add New Doctor'; ?></h3>
        <form action="manage_doctors.php<?php echo $is_editing ? '?edit='.$edit_doctor['id'] : ''; ?>" method="POST">
            <input type="hidden" name="doctor_id" value="<?php echo $edit_doctor['id'] ?? 0; ?>">
            
            <fieldset>
                <legend>Doctor Information</legend>
                <div class="form-row">
                    <div class="form-group"><label>Doctor Name</label><input type="text" name="doctor_name" required value="<?php echo htmlspecialchars($edit_doctor['doctor_name'] ?? ''); ?>"></div>
                    <div class="form-group"><label>Hospital Name</label><input type="text" name="hospital_name" value="<?php echo htmlspecialchars($edit_doctor['hospital_name'] ?? ''); ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Phone Number</label><input type="text" name="phone_number" value="<?php echo htmlspecialchars($edit_doctor['phone_number'] ?? ''); ?>"></div>
                    <div class="form-group"><label>Email ID</label><input type="email" name="email" value="<?php echo htmlspecialchars($edit_doctor['email'] ?? ''); ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Address</label><input type="text" name="address" value="<?php echo htmlspecialchars($edit_doctor['address'] ?? ''); ?>"></div>
                    <div class="form-group"><label>City/Town</label><input type="text" name="city" value="<?php echo htmlspecialchars($edit_doctor['city'] ?? ''); ?>"></div>
                </div>
                 <div class="form-row">
                    <div class="form-group doctor-active-group">
                        <label for="is_active_toggle" class="doctor-active-label">Active</label>
                        <label class="doctor-active-toggle" for="is_active_toggle">
                            <input type="checkbox" id="is_active_toggle" name="is_active" value="1" <?php echo (isset($edit_doctor) && $edit_doctor['is_active']) || !isset($edit_doctor) ? 'checked' : ''; ?>>
                            <span>Enabled</span>
                        </label>
                    </div>
                 </div>
            </fieldset>

            <fieldset>
                <legend>Per-Test Payable Amount (₹)</legend>
                <p>Enter a specific payable amount for each test. If left blank, the **test's default** payable amount will be used.</p>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="test_category_filter">Filter by Category</label>
                        <select id="test_category_filter" style="color: #000 !important;">
                            <option value="all">Show All Categories</option>
                            <?php foreach($test_categories as $category): ?>
                            <option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="test_search_input">Search by Test Name</label>
                        <input type="text" id="test_search_input" placeholder="e.g., brain, knee...">
                    </div>
                </div>

                <div class="scrollable-test-list">
                    <table class="data-table" id="payables-table">
                        <thead><tr><th>Test Name & Price</th><th>Payable Amount (₹)</th></tr></thead>
                        <tbody>
                            <?php foreach ($tests as $test): ?>
                            <tr data-category="<?php echo htmlspecialchars($test['main_test_name']); ?>">
                                <td>
                                    <?php echo htmlspecialchars($test['main_test_name'] . ' - ' . $test['sub_test_name']); ?>
                                    <span style="color: var(--text-secondary-color, #6c757d); font-size: 0.9em;">
                                        (₹<?php echo number_format($test['price'], 2); ?>)
                                    </span>
                                </td>
                                <td>
                                    <input type="number" name="payables[<?php echo $test['id']; ?>]" step="0.01" min="0" value="<?php echo htmlspecialchars($existing_payables[$test['id']] ?? ''); ?>" placeholder="Default: <?php echo number_format($test['default_payable_amount'] ?? 0.00, 2); ?>">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </fieldset>

            <button type="submit" class="btn-submit"><?php echo $edit_doctor ? 'Update Doctor' : 'Save Doctor'; ?></button>
            <a href="manage_doctors.php" class="btn-cancel">Cancel</a>
        </form>
    </div>

    <div class="table-container">
        <h3>Existing Doctors</h3>
        
        <form action="manage_doctors.php" method="GET" class="filter-form compact-filters">
            <div class="filter-group">
                <label for="status_filter">Filter by Status</label>
                <select name="status" id="status_filter">
                    <option value="all" <?php if($filter_status == 'all') echo 'selected'; ?>>All Status</option>
                    <option value="active" <?php if($filter_status == 'active') echo 'selected'; ?>>Active</option>
                    <option value="inactive" <?php if($filter_status == 'inactive') echo 'selected'; ?>>Inactive</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="search_filter">Search by Name, Phone, or Email</label>
                <input type="text" name="search" id="search_filter" placeholder="Search..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-submit">Filter / Search</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if ($doctors->num_rows > 0): while ($row = $doctors->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['doctor_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['phone_number']); ?></td>
                        <td><?php echo htmlspecialchars($row['email']); ?></td>
                        <td><?php echo $row['is_active'] ? '<span class="status-paid">Active</span>' : '<span class="status-pending">Inactive</span>'; ?></td>
                        <td>
                            <a href="?edit=<?php echo $row['id']; ?>" class="btn-action btn-edit">Edit</a>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- JavaScript for the test list filter ---
    const categoryFilter = document.getElementById('test_category_filter');
    const searchInput = document.getElementById('test_search_input');
    const payablesTable = document.getElementById('payables-table');
    const testRows = payablesTable.querySelectorAll('tbody tr');

    function filterTests() {
        const selectedCategory = categoryFilter.value;
        const searchTerm = searchInput.value.toLowerCase();

        testRows.forEach(function(row) {
            const rowCategory = row.dataset.category;
            const rowText = row.querySelector('td').textContent.toLowerCase();
            const categoryMatch = (selectedCategory === 'all' || rowCategory === selectedCategory);
            const searchMatch = rowText.includes(searchTerm);

            if (categoryMatch && searchMatch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
    categoryFilter.addEventListener('change', filterTests);
    searchInput.addEventListener('input', filterTests);

    // --- JavaScript for the collapsible form ---
    const toggleBtn = document.getElementById('toggle-form-btn');
    const formContainer = document.getElementById('add-edit-form-container');
    const isEditing = <?php echo json_encode($is_editing); ?>;

    toggleBtn.addEventListener('click', function() {
        const isVisible = formContainer.classList.contains('visible');
        
        if (isEditing) {
            // If we are in edit mode, this button should always act as a cancel button
            window.location.href = 'manage_doctors.php';
        } else {
            // If in "add new" mode, it toggles the form
            formContainer.classList.toggle('visible');
            this.textContent = formContainer.classList.contains('visible') ? 'Cancel' : 'Add New Doctor';
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>