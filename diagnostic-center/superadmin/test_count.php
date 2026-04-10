<?php
$page_title = "Test Analysis";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

// --- Handle Filters with Session Persistence ---
$filter_key = 'test_count_filters';
if (isset($_GET['reset'])) {
    unset($_SESSION[$filter_key]);
    header("Location: test_count.php");
    exit();
}

if (isset($_GET['start_date'])) {
    $_SESSION[$filter_key]['start_date'] = $_GET['start_date'];
    $_SESSION[$filter_key]['end_date'] = $_GET['end_date'];
}

$start_date = $_SESSION[$filter_key]['start_date'] ?? (isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-01-01'));
$end_date = $_SESSION[$filter_key]['end_date'] ?? (isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'));

// Fetch test counts with price and Date Filter
// We use a UNION to include:
// 1. Defined tests (from 'tests' table) - showing 0 if not performed
// 2. Undefined/Orphaned tests (from 'bill_items' table) - showing counts for IDs not in 'tests' table

$sql = "
    SELECT 
        t.id,
        t.main_test_name, 
        t.sub_test_name, 
        t.price, 
        COUNT(b.id) as performed_count
    FROM tests t 
    LEFT JOIN bill_items bi ON t.id = bi.test_id AND bi.item_status = 0
    LEFT JOIN bills b ON bi.bill_id = b.id 
        AND b.bill_status != 'Void'
        AND DATE(b.created_at) BETWEEN ? AND ?
    GROUP BY t.id 

    UNION ALL

    SELECT 
        bi.test_id as id,
        'Uncategorized (Deleted/Missing Tests)' as main_test_name, 
        CONCAT('Unknown Test (ID: ', bi.test_id, ')') as sub_test_name, 
        0 as price, 
        COUNT(b.id) as performed_count
    FROM bill_items bi
    JOIN bills b ON bi.bill_id = b.id 
        AND b.bill_status != 'Void'
        AND DATE(b.created_at) BETWEEN ? AND ?
    LEFT JOIN tests t ON bi.test_id = t.id
    WHERE bi.item_status = 0 AND t.id IS NULL
    GROUP BY bi.test_id
    
    ORDER BY main_test_name ASC, performed_count DESC
";

$stmt = $conn->prepare($sql);
// We need to bind parameters twice: ss for first query, ss for second query -> ssss
$stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

$categories = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $cat = $row['main_test_name'];
        if (!isset($categories[$cat])) {
            $categories[$cat] = [
                'total_performed' => 0,
                'total_revenue' => 0,
                'sub_tests' => []
            ];
        }
        
        $revenue = $row['performed_count'] * $row['price'];
        $row['revenue'] = $revenue;
        
        $categories[$cat]['total_performed'] += $row['performed_count'];
        $categories[$cat]['total_revenue'] += $revenue;
        $categories[$cat]['sub_tests'][] = $row;
    }
}
?>

<div class="page-container">
    <div class="dashboard-header">
        <div>
            <h1>Detailed Test Analysis</h1>
            <p class="text-muted">Breakdown of tests performed and revenue generated.</p>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-2"></i> Back</a>
    </div>

    <!-- Date Filter -->
    <form method="GET" class="filter-form compact-filters">
        <div class="quick-filters">
            <button type="button" class="btn-quick-date" data-range="today">Today</button>
            <button type="button" class="btn-quick-date" data-range="yesterday">Yesterday</button>
            <button type="button" class="btn-quick-date" data-range="this_week">Last 7 Days</button>
            <button type="button" class="btn-quick-date" data-range="this_month">This Month</button>
            <button type="button" class="btn-quick-date" data-range="last_month">Last Month</button>
            <button type="button" class="btn-quick-date" data-range="this_year">This Year</button>
        </div>
        <div class="filter-group">
            <div class="form-group flex-grow-1">
                <label for="start_date">From Date</label>
                <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div class="form-group flex-grow-1">
                <label for="end_date">To Date</label>
                <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-submit">Filter</button>
            <a href="?reset=1" class="btn-reset">Reset</a>
        </div>
    </form>

    <div class="filter-info-bar">
        <i class="fas fa-calendar-alt"></i>
        <span>Showing analysis from <strong><?php echo date('d M Y', strtotime($start_date)); ?></strong> to <strong><?php echo date('d M Y', strtotime($end_date)); ?></strong></span>
    </div>

    <div class="analysis-container mt-4">
        <div class="search-box mb-3">
            <input type="text" id="testSearch" class="form-control" placeholder="Search for Main Category or Sub-test name...">
        </div>

        <div id="categoriesList">
            <?php foreach ($categories as $catName => $data): ?>
            <div class="category-group card mb-3 border-0 shadow-sm" data-name="<?php echo strtolower($catName); ?>">
                <div class="category-header card-header bg-white d-flex flex-wrap align-items-center justify-content-between p-3" onclick="toggleCategory(this)" style="cursor: pointer;">
                    <div class="cat-title d-flex align-items-center gap-2 fw-bold text-dark mb-2 mb-md-0">
                        <i class="fas fa-folder text-primary"></i>
                        <?php echo htmlspecialchars($catName); ?>
                    </div>
                    <div class="cat-stats text-muted small d-flex align-items-center flex-wrap gap-2">
                        <span>Total Tests: <strong class="text-dark"><?php echo number_format($data['total_performed']); ?></strong></span>
                        <span class="d-none d-md-inline text-light">|</span>
                        <span>Revenue: <strong class="text-dark">₹<?php echo number_format($data['total_revenue'], 2); ?></strong></span>
                        <i class="fas fa-chevron-down chevron ms-2"></i>
                    </div>
                </div>
                <div class="sub-test-table-container p-0" style="display: none;">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 sub-test-table">
                            <thead class="bg-light">
                                <tr>
                                    <th>Sub Test Name</th>
                                    <th>Standard Price</th>
                                    <th>Times Performed</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data['sub_tests'] as $test): ?>
                                <tr data-subname="<?php echo strtolower($test['sub_test_name']); ?>" onclick="window.location.href='view_test_details.php?test_id=<?php echo $test['id']; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>'" style="cursor: pointer;">
                                    <td><?php echo htmlspecialchars($test['sub_test_name']); ?> <i class="fas fa-external-link-alt small text-muted ms-1"></i></td>
                                    <td>₹<?php echo number_format($test['price'], 2); ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?php echo number_format($test['performed_count']); ?></span>
                                    </td>
                                    <td class="fw-bold">₹<?php echo number_format($test['revenue'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
    function toggleCategory(header) {
        // Toggle active class
        header.classList.toggle('active');
        
        // Toggle content visibility
        const content = header.nextElementSibling;
        if (content.style.display === "block") {
            content.style.display = "none";
        } else {
            content.style.display = "block";
        }
    }

    document.getElementById('testSearch').addEventListener('keyup', function() {
        const searchText = this.value.toLowerCase();
        const groups = document.querySelectorAll('.category-group');

        groups.forEach(group => {
            const mainName = group.getAttribute('data-name');
            const subRows = group.querySelectorAll('tbody tr');
            let hasVisibleSub = false;

            // Check sub-tests
            subRows.forEach(row => {
                const subName = row.getAttribute('data-subname');
                if (subName.includes(searchText)) {
                    row.style.display = '';
                    hasVisibleSub = true;
                } else {
                    row.style.display = 'none';
                }
            });

            // Logic: Show group if Main Name matches OR if any Sub-test matches
            if (mainName.includes(searchText) || hasVisibleSub) {
                group.style.display = '';
                
                // If searching and found match in sub-tests, auto-expand
                if (searchText.length > 0 && hasVisibleSub) {
                    const header = group.querySelector('.category-header');
                    const content = group.querySelector('.sub-test-table-container');
                    if (!header.classList.contains('active')) {
                        header.classList.add('active');
                        content.style.display = 'block';
                    }
                }
            } else {
                group.style.display = 'none';
            }
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>
