<?php
$page_title = "Doctor Referrals";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

$doctor_id = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;

// Default to this month, or use passed params
$defaultStartDate = date('Y-m-01');
$defaultEndDate = date('Y-m-d');

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $defaultStartDate;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : $defaultEndDate;

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
            <h1>Referrals: <?php echo htmlspecialchars($doctor_info['doctor_name']); ?></h1>
            <p class="text-muted">
                <?php echo htmlspecialchars($doctor_info['hospital_name'] ?? ''); ?> 
                <?php echo $doctor_info['phone_number'] ? ' | ' . htmlspecialchars($doctor_info['phone_number']) : ''; ?>
            </p>
        </div>
        <a href="view_doctors.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Doctors</a>
    </div>

    <!-- Date Filter -->
    <form method="GET" class="filter-section" style="background: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 15px; align-items: flex-end; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
        <input type="hidden" name="doctor_id" value="<?php echo $doctor_id; ?>">
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

    <!-- Summary Cards -->
    <div class="summary-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <div class="card" style="background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <h3 style="margin: 0; color: #718096; font-size: 0.9rem;">Total Referrals</h3>
            <p style="margin: 10px 0 0; font-size: 1.8rem; font-weight: 700; color: #2d3748;"><?php echo count($bills_data); ?></p>
        </div>
        <div class="card" style="background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <h3 style="margin: 0; color: #718096; font-size: 0.9rem;">Total Revenue Generated</h3>
            <p style="margin: 10px 0 0; font-size: 1.8rem; font-weight: 700; color: #38a169;">₹<?php echo number_format($total_revenue, 2); ?></p>
        </div>
        <div class="card" style="background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <h3 style="margin: 0; color: #718096; font-size: 0.9rem;">Total Payable (Professional Charges)</h3>
            <p style="margin: 10px 0 0; font-size: 1.8rem; font-weight: 700; color: #e53e3e;">₹<?php echo number_format($total_commission, 2); ?></p>
        </div>
    </div>

    <div class="table-container">
        <table id="doctorReferralsTable" class="display" style="width: 100%;">
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

<script>
    $(document).ready(function() {
        $.fn.dataTable.ext.errMode = 'none';
        $('#doctorReferralsTable').DataTable({
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
