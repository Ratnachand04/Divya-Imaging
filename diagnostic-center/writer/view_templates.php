<?php
$page_title = "View Report Templates";
$required_role = "writer"; 
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$selected_category = isset($_GET['category']) && $_GET['category'] !== 'all' ? $_GET['category'] : 'all';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

$categories_stmt = $conn->query("SELECT DISTINCT main_test_name FROM tests WHERE document IS NOT NULL AND document != '' ORDER BY main_test_name ASC");
$categories = $categories_stmt->fetch_all(MYSQLI_ASSOC);

$query = "SELECT t.id, t.main_test_name, t.sub_test_name, t.document 
          FROM tests t
          WHERE t.document IS NOT NULL AND t.document != ''";

$params = [];
$types = '';

if ($selected_category !== 'all') {
    $query .= " AND t.main_test_name = ?";
    $params[] = $selected_category;
    $types .= 's';
}
if (!empty($search_term)) {
    $query .= " AND t.sub_test_name LIKE ?";
    $params[] = "%" . $search_term . "%";
    $types .= 's';
}
$query .= " ORDER BY t.main_test_name, t.sub_test_name";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$templates = $stmt->get_result();
$stmt->close();

require_once '../includes/header.php';
?>
<div class="dashboard-container">
    <h1>Available Report Templates</h1>
    <p>Search, filter, and select a template to start a new patient report.</p>

    <div class="filter-form">
        <form action="view_templates.php" method="GET" class="template-filter-form">
            <div class="form-group">
                <label for="category_filter">Filter by Category:</label>
                <select name="category" id="category_filter" onchange="this.form.submit()">
                    <option value="all">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo htmlspecialchars($category['main_test_name']); ?>" <?php if ($selected_category == $category['main_test_name']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($category['main_test_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="search">Search by Test Name:</label>
                <input type="text" name="search" id="search" placeholder="Enter test name..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <button type="submit" class="btn-submit">Search</button>
        </form>
    </div>

    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Test Name</th>
                    <th>Template File</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($templates->num_rows > 0): ?>
                    <?php while($template = $templates->fetch_assoc()): ?>
                        <?php
                            // UPDATED: Link points to the new download script for zipping
                            $downloadPath = 'download_template.php?file=' . urlencode(ltrim(str_replace('../', '', $template['document']), '/'));
                            $usePath = 'create_report_from_template.php?test_id=' . $template['id'];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($template['main_test_name']); ?></td>
                            <td><?php echo htmlspecialchars($template['sub_test_name']); ?></td>
                            <td><?php echo htmlspecialchars(basename($template['document'])); ?></td>
                            <td>
                                <a href="<?php echo $usePath; ?>" class="btn-action btn-primary">Use Template</a>
                                <a href="<?php echo $downloadPath; ?>" class="btn-action btn-view">Download</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="text-center">No report templates found matching your criteria.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>