<?php
$page_title = "Preview Bill";
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

if (!isset($_GET['bill_id']) || !is_numeric($_GET['bill_id'])) {
    header("Location: generate_bill.php");
    exit();
}

$bill_id = (int)$_GET['bill_id'];

$stmt = $conn->prepare(
    "SELECT b.*, p.name as patient_name, p.age, p.sex, p.address, p.city, p.mobile_number, p.emergency_contact_person, u.username as receptionist_username, rd.doctor_name as referral_doctor_name, b.referral_source_other
     FROM bills b
     JOIN patients p ON b.patient_id = p.id
     JOIN users u ON b.receptionist_id = u.id
     LEFT JOIN referral_doctors rd ON b.referral_doctor_id = rd.id
     WHERE b.id = ?"
);
$stmt->bind_param("i", $bill_id);
$stmt->execute();
$bill_result = $stmt->get_result();

if ($bill_result->num_rows === 0) {
    die("Error: The bill you are trying to preview could not be found.");
}
$bill = $bill_result->fetch_assoc();
$stmt->close();

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

require_once '../includes/header.php';
?>

<div class="page-container">
    <h1>Bill Preview</h1>
    <p>Please review the bill details below. If everything is correct, proceed to print.</p>
    
    <div class="preview-area">
        <div class="patient-details-header">
            <strong>Patient:</strong> <?php echo htmlspecialchars($bill['patient_name']); ?> | 
            <strong>Age/Gender:</strong> <?php echo $bill['age']; ?>/<?php echo $bill['sex']; ?> | 
            <strong>Bill No:</strong> <?php echo $bill['id']; ?>
        </div>
        <div class="patient-address-details">
            <strong>Address:</strong> <?php echo htmlspecialchars($bill['address']); ?>, <?php echo htmlspecialchars($bill['city']); ?> | 
            <strong>Mobile:</strong> <?php echo htmlspecialchars($bill['mobile_number']); ?> |
            <strong>Emergency Contact:</strong> <?php echo htmlspecialchars($bill['emergency_contact_person'] ?: '-'); ?>
        </div>
        <table class="preview-table">
            <thead>
                <tr>
                    <th>Test Name</th>
                    <th class="text-right">Price</th>
                </tr>
            </thead>
            <tbody>
                <?php while($item = $items_result->fetch_assoc()): ?>
                <?php
                    $raw_name = trim(preg_replace('/\s+/', ' ', $item['main_test_name'] . ' ' . $item['sub_test_name']));
                    $base_label = 'Test ' . $raw_name;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($base_label); ?></td>
                    <td class="text-right"><?php echo number_format($item['price'], 2); ?></td>
                </tr>
                <?php if ((float)$item['screening_amount'] > 0): ?>
                <tr>
                    <td><?php echo htmlspecialchars($raw_name . ' Screening'); ?></td>
                    <td class="text-right"><?php echo number_format($item['screening_amount'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <?php endwhile; ?>
            </tbody>
            <tfoot>
                <tr><td class="text-right">Gross Amount:</td><td class="text-right"><?php echo number_format($bill['gross_amount'], 2); ?></td></tr>
                <tr><td class="text-right">Discount:</td><td class="text-right">- <?php echo number_format($bill['discount'], 2); ?></td></tr>
                <tr><td class="text-right"><strong>Net Amount:</strong></td><td class="text-right"><strong><?php echo number_format($bill['net_amount'], 2); ?></strong></td></tr>
            </tfoot>
        </table>
    </div>

    <div class="action-buttons">
        <a href="../templates/print_bill.php?bill_id=<?php echo $bill_id; ?>" target="_blank" class="btn-submit">Confirm & Print</a>
        <a href="edit_bill.php?bill_id=<?php echo $bill_id; ?>" class="btn-edit">Edit Bill</a>
        <a href="dashboard.php" class="btn-cancel">Cancel</a>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>