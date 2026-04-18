<?php
// This is a dedicated endpoint for AJAX requests
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$receptionist_id = $_SESSION['user_id'];
$output = '';

if (!empty($search_term)) {
    $search_query = "%" . $search_term . "%";
    $stmt = $conn->prepare(
        "SELECT b.id, p.uid as patient_uid, p.name as patient_name, b.net_amount, b.created_at, b.payment_status
         FROM bills b JOIN patients p ON b.patient_id = p.id
         WHERE b.receptionist_id = ? AND (b.id LIKE ? OR p.uid LIKE ? OR p.name LIKE ?)
         ORDER BY b.id DESC"
    );
    $stmt->bind_param("isss", $receptionist_id, $search_query, $search_query, $search_query);
} else {
    // If search is empty, return the first page of results (or handle as needed)
    $limit = 15;
    $stmt = $conn->prepare(
        "SELECT b.id, p.uid as patient_uid, p.name as patient_name, b.net_amount, b.created_at, b.payment_status
         FROM bills b JOIN patients p ON b.patient_id = p.id
         WHERE b.receptionist_id = ? ORDER BY b.id DESC LIMIT ?"
    );
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

        $output .= '<tr>';
        $output .= '<td>' . $bill['id'] . '</td>';
        $output .= '<td><span style="font-size:0.82rem;color:#666;">' . htmlspecialchars($bill['patient_uid'] ?? '') . '</span></td>';
        $output .= '<td>' . htmlspecialchars($bill['patient_name']) . '</td>';
        $output .= '<td>' . date('d-m-Y', strtotime($bill['created_at'])) . '</td>';
        $output .= '<td>' . number_format($bill['net_amount'], 2) . '</td>';
        $output .= '<td><span class="status-'.strtolower($bill['payment_status']).'">' . $bill['payment_status'] . '</span></td>';
        $output .= '<td><a href="' . $base_url . '/templates/print_bill.php?bill_id='.$bill['id'].'" class="btn-action btn-view" target="_blank">View</a> ' . $edit_button . '</td>';
        $output .= '</tr>';
    }
} else {
    $output = '<tr><td colspan="7" style="text-align:center;">No results found.</td></tr>';
}

$stmt->close();
echo $output;
?>
