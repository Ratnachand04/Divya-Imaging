<?php
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$receptionist_id = $_SESSION['user_id'];
$output = '';

$sql = "SELECT
            b.id,
            p.name as patient_name,
            b.net_amount,
            b.created_at,
            b.payment_status,
            b.payment_mode,
            b.referral_type,
            rd.doctor_name as ref_physician_name
        FROM bills b
        JOIN patients p ON b.patient_id = p.id
        LEFT JOIN referral_doctors rd ON b.referral_doctor_id = rd.id
        WHERE b.receptionist_id = ? AND b.bill_status != 'Void'";

if (!empty($search_term)) {
    $search_query = "%" . $search_term . "%";
    $sql .= " AND (b.id LIKE ? OR p.name LIKE ?) ORDER BY b.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $receptionist_id, $search_query, $search_query);
} else {
    $limit = 15;
    $sql .= " ORDER BY b.id DESC LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $receptionist_id, $limit);
}

$stmt->execute();
$result = $stmt->get_result();

// Compute base URL once for link generation
$base_url = rtrim(str_replace(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '', str_replace('\\', '/', dirname(__DIR__))), '/');

if ($result->num_rows > 0) {
    while ($bill = $result->fetch_assoc()) {
        $created_time = new DateTime($bill['created_at']);
        $current_time = new DateTime();
        $interval = $current_time->diff($created_time);
        $hours_diff = $interval->h + ($interval->days * 24);
        
        $edit_button = ($hours_diff < 12)
            ? '<a href="edit_bill.php?bill_id='.$bill['id'].'" class="btn-action btn-edit">Edit</a>'
            : '<a href="#" class="btn-action btn-disabled" title="Editing is only allowed within 12 hours of creation.">Edit</a>';
        
        $update_payment_button = '';
        if ($bill['payment_status'] == 'Due' || $bill['payment_status'] == 'Half Paid') {
            $update_payment_button = '<a href="update_payment.php?bill_id='.$bill['id'].'" class="btn-action btn-update">Update Payment</a>';
        }

        $ref_physician_display = ($bill['referral_type'] == 'Doctor' && !empty($bill['ref_physician_name']))
            ? htmlspecialchars($bill['ref_physician_name'])
            : htmlspecialchars($bill['referral_type']);

        $output .= '<tr>';
        $output .= '<td>' . $bill['id'] . '</td>';
        $output .= '<td>' . htmlspecialchars($bill['patient_name']) . '</td>';
        $output .= '<td>' . date('d-m-Y', strtotime($bill['created_at'])) . '</td>';
        $output .= '<td>' . $ref_physician_display . '</td>';
        $output .= '<td>' . number_format($bill['net_amount'], 2) . '</td>';
        $output .= '<td>' . htmlspecialchars($bill['payment_mode']) . '</td>';
        $output .= '<td><span class="status-'.strtolower(str_replace(' ', '-', $bill['payment_status'])).'">' . $bill['payment_status'] . '</span></td>';
        $output .= '<td class="actions-cell"><a href="' . $base_url . '/templates/print_bill.php?bill_id='.$bill['id'].'" class="btn-action btn-view" target="_blank">View</a> ' . $edit_button . ' ' . $update_payment_button . '</td>';
        $output .= '</tr>';
    }
} else {
    $output = '<tr><td colspan="8" style="text-align:center;">No results found.</td></tr>';
}

$stmt->close();
echo $output;
?>