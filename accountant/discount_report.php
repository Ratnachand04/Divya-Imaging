<?php
$page_title = "Discount Report";
$required_role = "accountant";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$referral_type = $_GET['referral_type'] ?? 'all'; // 'center', 'doctor', or 'all'
$selected_doctor_id = $_GET['doctor_id'] ?? '';
$end_date_sql = $end_date . ' 23:59:59';
$rows_per_page_options = [10, 20, 50, 100];
$default_rows_per_page = 20;
$rows_per_page_input = isset($_GET['rows_per_page']) ? (int) $_GET['rows_per_page'] : $default_rows_per_page;
$rows_per_page = in_array($rows_per_page_input, $rows_per_page_options, true) ? $rows_per_page_input : $default_rows_per_page;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$total_records = 0;
$total_pages = 1;
$showing_start = 0;
$showing_end = 0;

// Fetch metrics
$total_discount = 0;
$discount_by_center = 0;
$discount_by_doctor = 0;
$discount_center_count = 0;
$discount_doctor_count = 0;

$metrics_stmt = $conn->prepare("SELECT 
    SUM(CASE WHEN discount > 0 THEN discount ELSE 0 END) as total,
    SUM(CASE WHEN discount_by = 'Center' AND discount > 0 THEN discount ELSE 0 END) as by_center,
    SUM(CASE WHEN discount_by = 'Doctor' AND discount > 0 THEN discount ELSE 0 END) as by_doctor,
    SUM(CASE WHEN discount_by = 'Center' AND discount > 0 THEN 1 ELSE 0 END) as center_count,
    SUM(CASE WHEN discount_by = 'Doctor' AND discount > 0 THEN 1 ELSE 0 END) as doctor_count
    FROM bills WHERE created_at BETWEEN ? AND ?");
$metrics_stmt->bind_param("ss", $start_date, $end_date_sql);
$metrics_stmt->execute();
$metrics_result = $metrics_stmt->get_result();
if ($metrics_row = $metrics_result->fetch_assoc()) {
    $total_discount = $metrics_row['total'] ?? 0;
    $discount_by_center = $metrics_row['by_center'] ?? 0;
    $discount_by_doctor = $metrics_row['by_doctor'] ?? 0;
    $discount_center_count = $metrics_row['center_count'] ?? 0;
    $discount_doctor_count = $metrics_row['doctor_count'] ?? 0;
}
$metrics_stmt->close();

// Fetch doctors list
$doctors_list = [];
$doctor_stmt = $conn->prepare("SELECT DISTINCT rd.id, rd.doctor_name FROM referral_doctors rd ORDER BY rd.doctor_name");
$doctor_stmt->execute();
$doctor_result = $doctor_stmt->get_result();
while ($doc = $doctor_result->fetch_assoc()) {
    $doctors_list[] = $doc;
}
$doctor_stmt->close();
?>

<div class="main-content page-container">
    <div class="discount-header">
        <h1>Discount Report & Analysis</h1>
        <p>Track and analyze all discounts applied to bills across different categories.</p>
    </div>

    <!-- Filter Section -->
    <form id="discount-filter-form" class="filter-form discount-filters" method="GET">
        <div class="filter-row">
            <div class="filter-group">
                <label for="start_date">Start Date</label>
                <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div class="filter-group">
                <label for="end_date">End Date</label>
                <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <div class="filter-group">
                <label for="referral_type">Referral Type</label>
                <select id="referral_type" name="referral_type">
                    <option value="all" <?php echo ($referral_type === 'all') ? 'selected' : ''; ?>>All Types</option>
                    <option value="center" <?php echo ($referral_type === 'center') ? 'selected' : ''; ?>>Center Discount</option>
                    <option value="doctor" <?php echo ($referral_type === 'doctor') ? 'selected' : ''; ?>>Doctor Discount</option>
                </select>
            </div>
            <?php if ($referral_type === 'doctor'): ?>
            <div class="filter-group">
                <label for="doctor_id">Select Doctor</label>
                <select id="doctor_id" name="doctor_id">
                    <option value="">-- All Doctors --</option>
                    <?php foreach ($doctors_list as $doctor): ?>
                    <option value="<?php echo $doctor['id']; ?>" <?php echo ($selected_doctor_id == $doctor['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($doctor['doctor_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="filter-actions">
                <a href="discount_report.php" class="btn-cancel">Reset</a>
                <button type="submit" class="btn-submit">Apply Filter</button>
            </div>
        </div>
        <input type="hidden" name="rows_per_page" value="<?php echo (int) $rows_per_page; ?>">
        <input type="hidden" name="page" value="1">
    </form>

    <!-- Metrics Tiles -->
    <div class="discount-metrics">
        <div class="metric-tile">
            <div class="metric-header">
                <h3>Total Discount</h3>
                <i class="fas fa-tag"></i>
            </div>
            <div class="metric-value">₹ <?php echo number_format($total_discount, 2); ?></div>
            <div class="metric-subtitle">From <?php echo date('d M Y', strtotime($start_date)); ?> to <?php echo date('d M Y', strtotime($end_date)); ?></div>
        </div>

        <div class="metric-tile center-discount">
            <div class="metric-header">
                <h3>Center Discount</h3>
                <i class="fas fa-store"></i>
            </div>
            <div class="metric-value">₹ <?php echo number_format($discount_by_center, 2); ?></div>
            <div class="metric-subtitle"><?php echo $discount_center_count; ?> transaction<?php echo $discount_center_count != 1 ? 's' : ''; ?></div>
        </div>

        <div class="metric-tile doctor-discount">
            <div class="metric-header">
                <h3>Doctor Discount</h3>
                <i class="fas fa-user-md"></i>
            </div>
            <div class="metric-value">₹ <?php echo number_format($discount_by_doctor, 2); ?></div>
            <div class="metric-subtitle"><?php echo $discount_doctor_count; ?> transaction<?php echo $discount_doctor_count != 1 ? 's' : ''; ?></div>
        </div>
    </div>

    <!-- Discount Data Container -->
    <div class="discount-data-container">
        <div class="table-header">
            <h2>Discount Details</h2>
            <p><?php echo ($referral_type === 'all') ? 'All discounts' : ucfirst($referral_type) . ' discounts'; ?><?php echo ($referral_type === 'doctor' && $selected_doctor_id) ? ' - ' . htmlspecialchars($doctors_list[array_search($selected_doctor_id, array_column($doctors_list, 'id'))]['doctor_name']) : ''; ?></p>
        </div>

        <?php
        $base_query_params = [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'referral_type' => $referral_type,
        ];
        if (!empty($selected_doctor_id)) {
            $base_query_params['doctor_id'] = $selected_doctor_id;
        }
        ?>
        <div class="table-controls" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:0.75rem;">
            <form method="GET" action="discount_report.php" class="rows-control-form" style="display:flex;align-items:center;gap:0.5rem;">
                <?php foreach ($base_query_params as $param_key => $param_value): ?>
                <input type="hidden" name="<?php echo htmlspecialchars($param_key); ?>" value="<?php echo htmlspecialchars($param_value); ?>">
                <?php endforeach; ?>
                <input type="hidden" name="page" value="1">
                <label for="rows_per_page_control" style="font-size:0.85rem;color:var(--text-secondary, #666);">Rows per page:</label>
                <select id="rows_per_page_control" name="rows_per_page" onchange="this.form.submit()" style="padding:0.4rem 0.6rem;border:1px solid var(--border-light, #dcdfe6);border-radius:6px;background:var(--bg-primary, #fff);">
                    <?php foreach ($rows_per_page_options as $option): ?>
                    <option value="<?php echo $option; ?>" <?php echo ($rows_per_page === $option) ? 'selected' : ''; ?>><?php echo $option; ?> rows</option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="table-wrapper">
            <table class="discount-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Bill No</th>
                        <th>Patient ID</th>
                        <th>Patient Name</th>
                        <th>Test Name</th>
                        <th>Bill Paid (₹)</th>
                        <th>Discount Given (₹)</th>
                        <th>Discount Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Build query based on filters
                    $where_clauses = ["b.discount > 0", "b.created_at BETWEEN ? AND ?"];
                    $params = [$start_date, $end_date_sql];
                    $param_types = "ss";

                    if ($referral_type === 'center') {
                        $where_clauses[] = "b.discount_by = 'Center'";
                    } elseif ($referral_type === 'doctor') {
                        $where_clauses[] = "b.discount_by = 'Doctor'";
                        if ($selected_doctor_id) {
                            $where_clauses[] = "b.referral_doctor_id = ?";
                            $params[] = $selected_doctor_id;
                            $param_types .= "i";
                        }
                    }

                    $where_clause = implode(" AND ", $where_clauses);

                    $count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM bills b JOIN patients p ON b.patient_id = p.id WHERE $where_clause");
                    if ($count_stmt === false) {
                        die('Failed to prepare discount count statement.');
                    }
                    $count_stmt->bind_param($param_types, ...$params);
                    $count_stmt->execute();
                    $count_result = $count_stmt->get_result();
                    $total_records = (int) (($count_result->fetch_assoc()['total']) ?? 0);
                    $count_stmt->close();

                    $total_pages = $total_records > 0 ? (int) ceil($total_records / $rows_per_page) : 1;
                    if ($page > $total_pages) {
                        $page = $total_pages;
                    }
                    $offset = ($page - 1) * $rows_per_page;
                    $showing_start = $total_records > 0 ? $offset + 1 : 0;
                    $showing_end = min($total_records, $offset + $rows_per_page);

                    $data_params = $params;
                    $data_param_types = $param_types . 'ii';
                    $data_params[] = $rows_per_page;
                    $data_params[] = $offset;

                    $discount_stmt = $conn->prepare("SELECT b.id, b.created_at, b.patient_id, b.net_amount, b.discount, b.discount_by, p.uid as patient_uid, p.name as patient_name FROM bills b JOIN patients p ON b.patient_id = p.id WHERE $where_clause ORDER BY b.created_at DESC LIMIT ? OFFSET ?");
                    
                    $discount_stmt->bind_param($data_param_types, ...$data_params);
                    $discount_stmt->execute();
                    $discount_result = $discount_stmt->get_result();

                    if ($discount_result->num_rows > 0):
                        while ($bill = $discount_result->fetch_assoc()):
                            $bill_paid = $bill['net_amount'] + $bill['discount'];
                            // Get test names for this bill
                            $test_stmt = $conn->prepare("SELECT GROUP_CONCAT(DISTINCT COALESCE(NULLIF(t.sub_test_name, ''), NULLIF(t.main_test_name, ''), 'Unnamed Test') SEPARATOR ', ') AS test_names FROM bill_items bi LEFT JOIN tests t ON bi.test_id = t.id WHERE bi.bill_id = ?");
                            $test_stmt->bind_param("i", $bill['id']);
                            $test_stmt->execute();
                            $test_result = $test_stmt->get_result();
                            $test_row = $test_result->fetch_assoc();
                            $test_names = $test_row['test_names'] ?? 'N/A';
                            $test_stmt->close();
                    ?>
                    <tr>
                        <td><?php echo date('d-m-Y', strtotime($bill['created_at'])); ?></td>
                        <td><a href="../templates/print_bill.php?bill_id=<?php echo $bill['id']; ?>" target="_blank" class="bill-link">#<?php echo $bill['id']; ?></a></td>
                        <td><span style="font-size:0.82rem;color:#666;"><?php echo htmlspecialchars($bill['patient_uid'] ?? ''); ?></span></td>
                        <td><?php echo htmlspecialchars($bill['patient_name']); ?></td>
                        <td><span class="test-name"><?php echo htmlspecialchars($test_names); ?></span></td>
                        <td class="amount">₹ <?php echo number_format($bill_paid, 2); ?></td>
                        <td class="discount-amount">₹ <?php echo number_format($bill['discount'], 2); ?></td>
                        <td><span class="discount-badge <?php echo strtolower($bill['discount_by']); ?>"><?php echo htmlspecialchars($bill['discount_by']); ?></span></td>
                    </tr>
                    <?php endwhile;
                    else: ?>
                    <tr>
                        <td colspan="8" class="no-data">No discount records found for the selected criteria.</td>
                    </tr>
                    <?php endif; $discount_stmt->close(); ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_records > 0): ?>
        <?php $pagination_query_params = $base_query_params; $pagination_query_params['rows_per_page'] = $rows_per_page; ?>
        <div class="table-pagination" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-top:1rem;">
            <div class="pagination-info" style="font-size:0.9rem;color:var(--text-secondary, #555);">
                Showing <?php echo $showing_start; ?>-<?php echo $showing_end; ?> of <?php echo number_format($total_records); ?> record<?php echo ($total_records === 1) ? '' : 's'; ?>
            </div>
            <?php if ($total_pages > 1): ?>
            <?php
            $window = 2;
            $start_loop = max(1, $page - $window);
            $end_loop = min($total_pages, $page + $window);
            $link_base_style = 'padding:0.35rem 0.7rem;border:1px solid var(--border-light, #dcdfe6);border-radius:6px;';
            ?>
            <div class="pagination-nav" style="display:flex;align-items:center;gap:0.35rem;flex-wrap:wrap;">
                <?php if ($page > 1): ?>
                <a class="page-link" href="<?php echo htmlspecialchars('discount_report.php?' . http_build_query(array_merge($pagination_query_params, ['page' => $page - 1]))); ?>" style="<?php echo $link_base_style; ?>">Prev</a>
                <?php else: ?>
                <span class="page-link disabled" style="<?php echo $link_base_style; ?>color:#aaa;">Prev</span>
                <?php endif; ?>

                <?php if ($start_loop > 1): ?>
                <a class="page-link" href="<?php echo htmlspecialchars('discount_report.php?' . http_build_query(array_merge($pagination_query_params, ['page' => 1]))); ?>" style="<?php echo $link_base_style; ?>">1</a>
                <?php if ($start_loop > 2): ?><span class="page-ellipsis" style="padding:0.35rem 0.4rem;color:#888;">…</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start_loop; $i <= $end_loop; $i++): ?>
                <?php
                $page_url = 'discount_report.php?' . http_build_query(array_merge($pagination_query_params, ['page' => $i]));
                $is_active = ($i === $page);
                $active_style = $is_active ? 'background:var(--accent-color, #2f5bea);color:#fff;border-color:var(--accent-color, #2f5bea);' : '';
                ?>
                <a class="page-link<?php echo $is_active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($page_url); ?>" style="<?php echo $link_base_style . $active_style; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>

                <?php if ($end_loop < $total_pages): ?>
                    <?php if ($end_loop < $total_pages - 1): ?><span class="page-ellipsis" style="padding:0.35rem 0.4rem;color:#888;">…</span><?php endif; ?>
                    <a class="page-link" href="<?php echo htmlspecialchars('discount_report.php?' . http_build_query(array_merge($pagination_query_params, ['page' => $total_pages]))); ?>" style="<?php echo $link_base_style; ?>"><?php echo $total_pages; ?></a>
                <?php endif; ?>

                <?php if ($page < $total_pages): ?>
                <a class="page-link" href="<?php echo htmlspecialchars('discount_report.php?' . http_build_query(array_merge($pagination_query_params, ['page' => $page + 1]))); ?>" style="<?php echo $link_base_style; ?>">Next</a>
                <?php else: ?>
                <span class="page-link disabled" style="<?php echo $link_base_style; ?>color:#aaa;">Next</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const referralTypeSelect = document.getElementById('referral_type');
    const filterForm = document.getElementById('discount-filter-form');

    // Show/hide doctor selector based on referral type
    referralTypeSelect.addEventListener('change', function() {
        if (this.value === 'doctor') {
            // If changing to doctor and no doctor selector exists, reload
            if (!document.getElementById('doctor_id')) {
                filterForm.submit();
            }
        } else {
            // If changing away from doctor, reload to hide doctor selector
            if (document.getElementById('doctor_id')) {
                filterForm.submit();
            }
        }
    });

    // Enable smooth scroll
    if (typeof window.enableSmoothScroll === 'function') {
        window.enableSmoothScroll({
            speed: 0.92,
            ease: 0.12,
            progressIndicator: false,
            enableOnTouch: false
        });
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>