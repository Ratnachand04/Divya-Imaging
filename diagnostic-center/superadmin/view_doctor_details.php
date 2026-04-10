<?php
$page_title = "Doctor Details";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

$doctor_id = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-01-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

if (!$doctor_id) {
    echo "<div class='page-container'><div class='error-banner'>Invalid Doctor ID.</div></div>";
    require_once '../includes/footer.php';
    exit;
}

// Fetch Doctor Info
$stmt = $conn->prepare("SELECT doctor_name, hospital_name, phone_number FROM referral_doctors WHERE id = ?");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$doctor_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doctor_info) {
    echo "<div class='page-container'><div class='error-banner'>Doctor not found.</div></div>";
    require_once '../includes/footer.php';
    exit;
}

// Fetch Patients & Payables with Date Filter
// We need to sum up payables for each bill
$sql = "SELECT 
            b.id as bill_id,
            b.created_at,
            p.name as patient_name,
            p.mobile_number,
            b.net_amount as bill_amount,
            b.payment_status,
            SUM(COALESCE(dtp.payable_amount, 0)) as total_payable
        FROM bills b
        JOIN patients p ON b.patient_id = p.id
        JOIN bill_items bi ON b.id = bi.bill_id AND bi.item_status = 0
        LEFT JOIN doctor_test_payables dtp ON b.referral_doctor_id = dtp.doctor_id AND bi.test_id = dtp.test_id
        WHERE b.referral_doctor_id = ? 
        AND b.bill_status != 'Void'
        AND DATE(b.created_at) BETWEEN ? AND ?
        GROUP BY b.id
        ORDER BY b.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $doctor_id, $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

// Calculate Totals
$total_revenue = 0;
$total_commission = 0;
$bills_data = [];
while ($row = $result->fetch_assoc()) {
    $total_revenue += $row['bill_amount'];
    $total_commission += $row['total_payable'];
    $bills_data[] = $row;
}
?>

<div class="main-content page-container">
    <div class="dashboard-header">
        <div>
            <h1><?php echo htmlspecialchars($doctor_info['doctor_name']); ?></h1>
            <p class="text-muted">
                <?php echo htmlspecialchars($doctor_info['hospital_name'] ?? ''); ?> 
                <?php echo $doctor_info['phone_number'] ? ' | ' . htmlspecialchars($doctor_info['phone_number']) : ''; ?>
            </p>
        </div>
        <a href="doctor_wise_count.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn-back"><i class="fas fa-arrow-left"></i> Back to List</a>
    </div>

    <!-- Date Filter -->
    <form method="GET" class="filter-section doctor-details-filter">
        <input type="hidden" name="doctor_id" value="<?php echo $doctor_id; ?>">
        <div class="form-group">
            <label>From Date</label>
            <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>To Date</label>
            <input type="date" name="end_date" value="<?php echo $end_date; ?>" class="form-control">
        </div>
        <button type="submit" class="btn-action">Filter</button>
    </form>

    <!-- Summary Cards -->
    <div class="doctor-summary-grid">
        <div class="doctor-summary-card">
            <h3>Total Patients</h3>
            <p><?php echo count($bills_data); ?></p>
        </div>
        <div class="doctor-summary-card">
            <h3>Total Revenue Generated</h3>
            <p class="is-positive">₹<?php echo number_format($total_revenue, 2); ?></p>
        </div>
        <div class="doctor-summary-card">
            <h3>Total Payable (Professional Charges)</h3>
            <p class="is-negative">₹<?php echo number_format($total_commission, 2); ?></p>
        </div>
    </div>

    <div class="table-container">
        <div class="table-scroll">
        <table id="doctorDetailsTable" class="display" style="width: 100%;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Bill ID</th>
                    <th>Patient Name</th>
                    <th>Mobile</th>
                    <th>Bill Amount</th>
                    <th>Payable Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($bills_data)): ?>
                    <?php foreach($bills_data as $row): ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($row['created_at'])); ?></td>
                            <td>#<?php echo $row['bill_id']; ?></td>
                            <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['mobile_number']); ?></td>
                            <td>₹<?php echo number_format($row['bill_amount'], 2); ?></td>
                            <td style="font-weight: bold; color: #e53e3e;">₹<?php echo number_format($row['total_payable'], 2); ?></td>
                            <td>
                                <?php 
                                $statusClass = 'status-pending';
                                if ($row['payment_status'] === 'Paid') $statusClass = 'status-paid';
                                elseif ($row['payment_status'] === 'Half Paid') $statusClass = 'status-partial';
                                ?>
                                <span class="<?php echo $statusClass; ?>"><?php echo $row['payment_status']; ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        $.fn.dataTable.ext.errMode = 'none';
        $('#doctorDetailsTable').DataTable({
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
