<?php
$page_title = "Test Details";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

$test_id = isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-01-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

if (!$test_id) {
    echo "<div class='page-container'><div class='error-banner'>Invalid Test ID.</div></div>";
    require_once '../includes/footer.php';
    exit;
}

// Fetch Test Info
$stmt = $conn->prepare("SELECT main_test_name, sub_test_name, price FROM tests WHERE id = ?");
$stmt->bind_param("i", $test_id);
$stmt->execute();
$test_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$test_info) {
    echo "<div class='page-container'><div class='error-banner'>Test not found.</div></div>";
    require_once '../includes/footer.php';
    exit;
}

// Fetch Patients who took this test with Date Filter
$sql = "SELECT 
            b.id as bill_id,
            b.created_at,
            p.uid as patient_uid,
            p.name as patient_name,
            p.age,
            p.sex,
            p.mobile_number,
            b.referral_type,
            rd.doctor_name,
            b.payment_status,
            t.price as standard_price,
            bi.discount_amount
        FROM bill_items bi
        JOIN bills b ON bi.bill_id = b.id
        JOIN patients p ON b.patient_id = p.id
        JOIN tests t ON bi.test_id = t.id
        LEFT JOIN referral_doctors rd ON b.referral_doctor_id = rd.id
        WHERE bi.test_id = ? 
        AND bi.item_status = 0 
        AND b.bill_status != 'Void'
        AND DATE(b.created_at) BETWEEN ? AND ?
        ORDER BY b.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $test_id, $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();
?>

<div class="main-content page-container">
    <div class="dashboard-header">
        <div>
            <h1><?php echo htmlspecialchars($test_info['sub_test_name']); ?></h1>
            <p class="text-muted">Category: <?php echo htmlspecialchars($test_info['main_test_name']); ?> | Standard Price: ₹<?php echo number_format($test_info['price'], 2); ?></p>
        </div>
        <a href="test_count.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to List</a>
    </div>

    <!-- Date Filter -->
    <form method="GET" class="filter-section" style="background: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 15px; align-items: flex-end; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
        <input type="hidden" name="test_id" value="<?php echo $test_id; ?>">
        <div class="form-group" style="margin-bottom: 0;">
            <label style="font-size: 0.9rem; font-weight: 600; color: #4a5568;">From Date</label>
            <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="form-control" style="padding: 8px;">
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label style="font-size: 0.9rem; font-weight: 600; color: #4a5568;">To Date</label>
            <input type="date" name="end_date" value="<?php echo $end_date; ?>" class="form-control" style="padding: 8px;">
        </div>
        <button type="submit" class="btn-action" style="padding: 8px 20px; height: 38px;">Filter</button>
    </form>

    <div class="table-container">
        <table id="testDetailsTable" class="display" style="width: 100%;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Bill ID</th>
                    <th>Patient ID</th>
                    <th>Patient Name</th>
                    <th>Age/Gender</th>
                    <th>Referred By</th>
                    <th>Price Charged</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($row['created_at'])); ?></td>
                            <td>#<?php echo $row['bill_id']; ?></td>
                            <td><span style="font-size:0.82rem;color:#666;"><?php echo htmlspecialchars($row['patient_uid'] ?? ''); ?></span></td>
                            <td>
                                <?php echo htmlspecialchars($row['patient_name']); ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($row['mobile_number']); ?></small>
                            </td>
                            <td><?php echo $row['age'] . ' / ' . $row['sex']; ?></td>
                            <td>
                                <?php 
                                if ($row['referral_type'] === 'Doctor') {
                                    echo htmlspecialchars($row['doctor_name']);
                                } else {
                                    echo htmlspecialchars($row['referral_type']);
                                }
                                ?>
                            </td>
                            <td>₹<?php echo number_format($row['standard_price'] - $row['discount_amount'], 2); ?></td>
                            <td>
                                <?php 
                                $statusClass = 'status-pending';
                                if ($row['payment_status'] === 'Paid') $statusClass = 'status-paid';
                                elseif ($row['payment_status'] === 'Half Paid') $statusClass = 'status-partial';
                                ?>
                                <span class="<?php echo $statusClass; ?>"><?php echo $row['payment_status']; ?></span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    $(document).ready(function() {
        $.fn.dataTable.ext.errMode = 'none';
        $('#testDetailsTable').DataTable({
            "paging": true,
            "ordering": true,
            "info": true,
            "searching": true,
            "pageLength": 20,
            "lengthMenu": [10, 20, 50, 100],
            "language": {
                "search": "Search records:",
                "lengthMenu": "Show _MENU_ entries",
                "emptyTable": "No records found for this period."
            }
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>
