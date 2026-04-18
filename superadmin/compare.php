<?php
$page_title = "Compare Doctors";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

// Fetch all doctors for dropdown
$doctors = [];
$sql = "SELECT id, doctor_name as name FROM referral_doctors ORDER BY doctor_name";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $doctors[] = $row;
    }
}

$doctor1_stats = null;
$doctor2_stats = null;
$doctor1_id = isset($_GET['doctor1']) ? (int)$_GET['doctor1'] : 0;
$doctor2_id = isset($_GET['doctor2']) ? (int)$_GET['doctor2'] : 0;

if ($doctor1_id && $doctor2_id) {
    function getDoctorStats($conn, $doctor_id) {
        $stats = [];
        
        // Total Patients (Bills)
        $sql = "SELECT COUNT(id) as total_bills, SUM(net_amount) as total_revenue FROM bills WHERE referral_doctor_id = ? AND bill_status != 'Void'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $doctor_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stats['total_bills'] = $res['total_bills'];
        $stats['total_revenue'] = $res['total_revenue'];
        
        // Total Tests
        $sql = "SELECT COUNT(bi.id) as total_tests FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE b.referral_doctor_id = ? AND b.bill_status != 'Void' AND bi.item_status = 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $doctor_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stats['total_tests'] = $res['total_tests'];

        // Get Name
        $sql = "SELECT doctor_name as name FROM referral_doctors WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $doctor_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stats['name'] = $res['name'];

        return $stats;
    }

    $doctor1_stats = getDoctorStats($conn, $doctor1_id);
    $doctor2_stats = getDoctorStats($conn, $doctor2_id);
}
?>


<div class="page-container">
    <div class="dashboard-header">
        <div>
            <h1>Compare Doctors</h1>
            <p class="text-muted">Head-to-head performance comparison.</p>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-2"></i> Back</a>
    </div>

    <form method="GET" action="compare.php" class="filter-form compact-filters">
        <div class="filter-group">
            <div class="form-group flex-grow-1">
                <label for="doctor1">Doctor 1</label>
                <select name="doctor1" id="doctor1" required>
                    <option value="">Select Doctor</option>
                    <?php foreach ($doctors as $doc): ?>
                        <option value="<?php echo $doc['id']; ?>" <?php echo ($doctor1_id == $doc['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($doc['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group flex-grow-1">
                <label for="doctor2">Doctor 2</label>
                <select name="doctor2" id="doctor2" required>
                    <option value="">Select Doctor</option>
                    <?php foreach ($doctors as $doc): ?>
                        <option value="<?php echo $doc['id']; ?>" <?php echo ($doctor2_id == $doc['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($doc['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-submit">Compare</button>
        </div>
    </form>

    <?php if ($doctor1_stats && $doctor2_stats): ?>
        <div class="row mt-4 g-4">
            <!-- Doctor 1 -->
            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm text-center">
                    <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
                        <div class="avatar-circle mx-auto mb-3 bg-pink-soft text-pink d-flex align-items-center justify-content-center" style="width: 64px; height: 64px; border-radius: 50%; font-size: 1.5rem;">
                            <i class="fas fa-user-md"></i>
                        </div>
                        <h3 class="card-title text-pink mb-1"><?php echo htmlspecialchars($doctor1_stats['name']); ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="py-2 border-bottom d-flex justify-content-between">
                            <span class="text-muted">Total Bills</span>
                            <span class="fw-bold"><?php echo number_format($doctor1_stats['total_bills']); ?></span>
                        </div>
                        <div class="py-2 border-bottom d-flex justify-content-between">
                            <span class="text-muted">Total Tests</span>
                            <span class="fw-bold"><?php echo number_format($doctor1_stats['total_tests']); ?></span>
                        </div>
                        <div class="py-2 d-flex justify-content-between">
                            <span class="text-muted">Total Revenue</span>
                            <span class="fw-bold fs-5 text-dark">₹<?php echo number_format($doctor1_stats['total_revenue'], 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Doctor 2 -->
            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm text-center">
                    <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
                        <div class="avatar-circle mx-auto mb-3 bg-blue-soft text-blue d-flex align-items-center justify-content-center" style="width: 64px; height: 64px; border-radius: 50%; font-size: 1.5rem;">
                             <i class="fas fa-user-md"></i>
                        </div>
                        <h3 class="card-title text-blue mb-1"><?php echo htmlspecialchars($doctor2_stats['name']); ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="py-2 border-bottom d-flex justify-content-between">
                            <span class="text-muted">Total Bills</span>
                            <span class="fw-bold"><?php echo number_format($doctor2_stats['total_bills']); ?></span>
                        </div>
                        <div class="py-2 border-bottom d-flex justify-content-between">
                            <span class="text-muted">Total Tests</span>
                            <span class="fw-bold"><?php echo number_format($doctor2_stats['total_tests']); ?></span>
                        </div>
                        <div class="py-2 d-flex justify-content-between">
                            <span class="text-muted">Total Revenue</span>
                            <span class="fw-bold fs-5 text-dark">₹<?php echo number_format($doctor2_stats['total_revenue'], 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
