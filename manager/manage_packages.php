<?php
$page_title = 'Manage Packages';
$required_role = ['manager', 'superadmin'];

require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_package_management_schema($conn);

$feedback = '';
$feedback_class = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    $conn->begin_transaction();
    try {
        if ($action === 'save_package') {
            $package_id = isset($_POST['package_id']) ? (int)$_POST['package_id'] : 0;
            $package_name = trim((string)($_POST['package_name'] ?? ''));
            $package_code = strtoupper(trim((string)($_POST['package_code'] ?? '')));
            $description = trim((string)($_POST['description'] ?? ''));
            $status = trim((string)($_POST['status'] ?? 'active'));
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }

            if ($package_name === '') {
                throw new Exception('Package name is required.');
            }

            if ($package_code === '') {
                $package_code = generate_package_code($conn, $package_name);
            }

            if (!preg_match('/^[A-Z0-9_-]{3,50}$/', $package_code)) {
                throw new Exception('Package code must be 3-50 chars using letters, numbers, _ or -.');
            }

            $tests_json = (string)($_POST['tests_json'] ?? '');
            $tests_payload = json_decode($tests_json, true);
            if (!is_array($tests_payload) || empty($tests_payload)) {
                throw new Exception('Please add at least one test to the package.');
            }

            $package_rows = [];
            $existing_test_ids = [];
            $existing_seen = [];
            $custom_seen = [];

            foreach ($tests_payload as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $test_id = isset($row['test_id']) ? (int)$row['test_id'] : 0;
                $custom_test_name = trim((string)($row['custom_test_name'] ?? ''));
                $test_category = trim((string)($row['test_category'] ?? ''));
                $package_test_price = normalize_currency_amount($row['package_test_price'] ?? 0);

                if ($test_category === '') {
                    throw new Exception('Please select a test category for every package row.');
                }

                if ($package_test_price < 0) {
                    throw new Exception('Package test price cannot be negative.');
                }

                if ($test_id > 0) {
                    if (isset($existing_seen[$test_id])) {
                        throw new Exception('Duplicate tests are not allowed in one package.');
                    }
                    $existing_seen[$test_id] = true;
                    $existing_test_ids[] = $test_id;

                    $package_rows[] = [
                        'row_type' => 'existing',
                        'test_id' => $test_id,
                        'custom_test_name' => '',
                        'test_category' => $test_category,
                        'package_test_price' => $package_test_price,
                        'display_order' => (int)$idx + 1
                    ];
                    continue;
                }

                if ($custom_test_name === '') {
                    throw new Exception('Select an existing test or enter a custom test name for every row.');
                }

                if ($package_test_price <= 0) {
                    throw new Exception('Custom test price must be greater than zero.');
                }

                $custom_key = strtolower($test_category . '::' . preg_replace('/\s+/', ' ', $custom_test_name));
                if (isset($custom_seen[$custom_key])) {
                    throw new Exception('Duplicate custom tests are not allowed in one package.');
                }
                $custom_seen[$custom_key] = true;

                $package_rows[] = [
                    'row_type' => 'custom',
                    'test_id' => 0,
                    'custom_test_name' => $custom_test_name,
                    'test_category' => $test_category,
                    'package_test_price' => $package_test_price,
                    'display_order' => (int)$idx + 1
                ];
            }

            if (empty($package_rows)) {
                throw new Exception('Please add at least one valid test to the package.');
            }

            $base_price_map = [];
            if (!empty($existing_test_ids)) {
                $existing_test_ids = array_values(array_unique($existing_test_ids));
                $placeholders = implode(',', array_fill(0, count($existing_test_ids), '?'));
                $types = str_repeat('i', count($existing_test_ids));

                $tests_stmt = $conn->prepare("SELECT id, price FROM tests WHERE id IN ($placeholders)");
                if (!$tests_stmt) {
                    throw new Exception('Failed to validate tests: ' . $conn->error);
                }
                $tests_stmt->bind_param($types, ...$existing_test_ids);
                $tests_stmt->execute();
                $tests_result = $tests_stmt->get_result();
                while ($test_row = $tests_result->fetch_assoc()) {
                    $base_price_map[(int)$test_row['id']] = normalize_currency_amount($test_row['price']);
                }
                $tests_stmt->close();

                foreach ($existing_test_ids as $existing_test_id) {
                    if (!array_key_exists($existing_test_id, $base_price_map)) {
                        throw new Exception('One or more selected tests were not found. Add new test first.');
                    }
                }
            }

            $total_base_price = 0.00;
            $total_package_price = 0.00;
            foreach ($package_rows as &$meta) {
                if ($meta['row_type'] === 'existing') {
                    $base_price = $base_price_map[$meta['test_id']];
                } else {
                    // Custom package rows use entered price as base to avoid artificial discount drift.
                    $base_price = normalize_currency_amount($meta['package_test_price']);
                }

                $meta['base_test_price'] = $base_price;
                $meta['package_test_price'] = normalize_currency_amount($meta['package_test_price']);
                if ($meta['row_type'] === 'existing' && $meta['package_test_price'] > $base_price) {
                    throw new Exception('Package-specific price cannot exceed base price for existing tests.');
                }
                $total_base_price += $base_price;
                $total_package_price += $meta['package_test_price'];
            }
            unset($meta);

            $total_base_price = round($total_base_price, 2);
            $total_package_price = round($total_package_price, 2);
            $discount_amount = round(max($total_base_price - $total_package_price, 0), 2);
            $discount_percent = $total_base_price > 0 ? round(($discount_amount / $total_base_price) * 100, 2) : 0.00;

            if ($package_id > 0) {
                $dup_stmt = $conn->prepare('SELECT id FROM test_packages WHERE package_code = ? AND id != ? LIMIT 1');
                if (!$dup_stmt) {
                    throw new Exception('Failed to validate package code: ' . $conn->error);
                }
                $dup_stmt->bind_param('si', $package_code, $package_id);
                $dup_stmt->execute();
                $duplicate = $dup_stmt->get_result()->fetch_assoc();
                $dup_stmt->close();
                if ($duplicate) {
                    throw new Exception('Package code already exists. Use another code.');
                }

                $update_stmt = $conn->prepare('UPDATE test_packages SET package_code = ?, package_name = ?, description = ?, total_base_price = ?, package_price = ?, discount_amount = ?, discount_percent = ?, status = ? WHERE id = ?');
                if (!$update_stmt) {
                    throw new Exception('Failed to update package: ' . $conn->error);
                }
                $update_stmt->bind_param('sssddddsi', $package_code, $package_name, $description, $total_base_price, $total_package_price, $discount_amount, $discount_percent, $status, $package_id);
                if (!$update_stmt->execute()) {
                    $err = $update_stmt->error;
                    $update_stmt->close();
                    throw new Exception('Unable to update package: ' . $err);
                }
                $update_stmt->close();

                $del_stmt = $conn->prepare('DELETE FROM package_tests WHERE package_id = ?');
                if (!$del_stmt) {
                    throw new Exception('Failed to reset package tests: ' . $conn->error);
                }
                $del_stmt->bind_param('i', $package_id);
                $del_stmt->execute();
                $del_stmt->close();
            } else {
                $dup_stmt = $conn->prepare('SELECT id FROM test_packages WHERE package_code = ? LIMIT 1');
                if (!$dup_stmt) {
                    throw new Exception('Failed to validate package code: ' . $conn->error);
                }
                $dup_stmt->bind_param('s', $package_code);
                $dup_stmt->execute();
                $duplicate = $dup_stmt->get_result()->fetch_assoc();
                $dup_stmt->close();
                if ($duplicate) {
                    throw new Exception('Package code already exists. Use another code.');
                }

                $created_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                $insert_stmt = $conn->prepare('INSERT INTO test_packages (package_code, package_name, description, total_base_price, package_price, discount_amount, discount_percent, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                if (!$insert_stmt) {
                    throw new Exception('Failed to create package: ' . $conn->error);
                }
                $insert_stmt->bind_param('sssddddsi', $package_code, $package_name, $description, $total_base_price, $total_package_price, $discount_amount, $discount_percent, $status, $created_by);
                if (!$insert_stmt->execute()) {
                    $err = $insert_stmt->error;
                    $insert_stmt->close();
                    throw new Exception('Unable to create package: ' . $err);
                }
                $package_id = (int)$insert_stmt->insert_id;
                $insert_stmt->close();
            }

            $pt_stmt_existing = $conn->prepare('INSERT INTO package_tests (package_id, test_id, custom_test_name, is_custom, test_category, base_test_price, package_test_price, display_order) VALUES (?, ?, NULL, 0, ?, ?, ?, ?)');
            if (!$pt_stmt_existing) {
                throw new Exception('Failed to save package tests: ' . $conn->error);
            }

            $pt_stmt_custom = $conn->prepare('INSERT INTO package_tests (package_id, test_id, custom_test_name, is_custom, test_category, base_test_price, package_test_price, display_order) VALUES (?, NULL, ?, 1, ?, ?, ?, ?)');
            if (!$pt_stmt_custom) {
                $pt_stmt_existing->close();
                throw new Exception('Failed to save custom package tests: ' . $conn->error);
            }

            foreach ($package_rows as $meta) {
                $base_test_price = (float)$meta['base_test_price'];
                $package_test_price = (float)$meta['package_test_price'];
                $display_order = (int)$meta['display_order'];
                $test_category = (string)$meta['test_category'];

                if ($meta['row_type'] === 'existing') {
                    $test_id = (int)$meta['test_id'];
                    $pt_stmt_existing->bind_param('iisddi', $package_id, $test_id, $test_category, $base_test_price, $package_test_price, $display_order);
                    if (!$pt_stmt_existing->execute()) {
                        $err = $pt_stmt_existing->error;
                        $pt_stmt_existing->close();
                        $pt_stmt_custom->close();
                        throw new Exception('Failed to save package test item: ' . $err);
                    }
                    continue;
                }

                $custom_test_name = (string)$meta['custom_test_name'];
                $pt_stmt_custom->bind_param('issddi', $package_id, $custom_test_name, $test_category, $base_test_price, $package_test_price, $display_order);
                if (!$pt_stmt_custom->execute()) {
                    $err = $pt_stmt_custom->error;
                    $pt_stmt_existing->close();
                    $pt_stmt_custom->close();
                    throw new Exception('Failed to save custom package test item: ' . $err);
                }
            }

            $pt_stmt_existing->close();
            $pt_stmt_custom->close();

            $conn->commit();

            $_SESSION['package_feedback'] = [
                'class' => 'success-banner',
                'message' => ($action === 'save_package' && isset($_POST['package_id']) && (int)$_POST['package_id'] > 0)
                    ? 'Package updated successfully.'
                    : 'Package created successfully.'
            ];

            header('Location: manage_packages.php');
            exit();
        }

        if ($action === 'toggle_status') {
            $package_id = isset($_POST['package_id']) ? (int)$_POST['package_id'] : 0;
            if ($package_id <= 0) {
                throw new Exception('Invalid package selected.');
            }

            $status_stmt = $conn->prepare('SELECT status FROM test_packages WHERE id = ? LIMIT 1');
            if (!$status_stmt) {
                throw new Exception('Failed to read package status.');
            }
            $status_stmt->bind_param('i', $package_id);
            $status_stmt->execute();
            $current = $status_stmt->get_result()->fetch_assoc();
            $status_stmt->close();

            if (!$current) {
                throw new Exception('Package not found.');
            }

            $next_status = ($current['status'] === 'active') ? 'inactive' : 'active';
            $upd_stmt = $conn->prepare('UPDATE test_packages SET status = ? WHERE id = ?');
            if (!$upd_stmt) {
                throw new Exception('Failed to update package status.');
            }
            $upd_stmt->bind_param('si', $next_status, $package_id);
            $upd_stmt->execute();
            $upd_stmt->close();

            $conn->commit();
            $_SESSION['package_feedback'] = [
                'class' => 'success-banner',
                'message' => 'Package status updated successfully.'
            ];

            header('Location: manage_packages.php');
            exit();
        }

        if ($action === 'delete_package') {
            $package_id = isset($_POST['package_id']) ? (int)$_POST['package_id'] : 0;
            if ($package_id <= 0) {
                throw new Exception('Invalid package selected.');
            }

            $delete_stmt = $conn->prepare('DELETE FROM test_packages WHERE id = ?');
            if (!$delete_stmt) {
                throw new Exception('Failed to delete package.');
            }
            $delete_stmt->bind_param('i', $package_id);
            $delete_stmt->execute();
            $delete_stmt->close();

            $conn->commit();
            $_SESSION['package_feedback'] = [
                'class' => 'success-banner',
                'message' => 'Package deleted successfully.'
            ];

            header('Location: manage_packages.php');
            exit();
        }

        throw new Exception('Unsupported action.');
    } catch (Exception $e) {
        $conn->rollback();
        $feedback = htmlspecialchars($e->getMessage());
        $feedback_class = 'error-banner';
    }
}

if (isset($_SESSION['package_feedback']) && is_array($_SESSION['package_feedback'])) {
    $feedback = htmlspecialchars((string)($_SESSION['package_feedback']['message'] ?? ''));
    $feedback_class = (string)($_SESSION['package_feedback']['class'] ?? 'success-banner');
    unset($_SESSION['package_feedback']);
}

$tests = [];
$tests_query = $conn->query('SELECT id, main_test_name, sub_test_name, price FROM tests ORDER BY main_test_name, sub_test_name');
if ($tests_query) {
    while ($row = $tests_query->fetch_assoc()) {
        $sub = trim((string)$row['sub_test_name']);
        $main = trim((string)$row['main_test_name']);
        if ($main === '') {
            $main = 'General';
        }
        $label = $sub !== '' ? ($main . ' - ' . $sub) : $main;
        $price = round((float)$row['price'], 2);
        $display_label = $label . ' (Rs ' . number_format($price, 2, '.', '') . ')';
        $tests[] = [
            'id' => (int)$row['id'],
            'label' => $label,
            'display_label' => $display_label,
            'category' => $main,
            'price' => $price
        ];
    }
    $tests_query->free();
}

$editing_package = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
if ($edit_id > 0) {
    $editing_package = fetch_package_details($conn, $edit_id, false);
    if (!$editing_package) {
        $feedback = 'Package not found for editing.';
        $feedback_class = 'error-banner';
    }
}

$view_package = null;
$view_id = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($view_id > 0) {
    $view_package = fetch_package_details($conn, $view_id, false);
}

$packages = [];
$package_sql = "SELECT tp.id, tp.package_code, tp.package_name, tp.total_base_price, tp.package_price,
                       tp.discount_amount, tp.discount_percent, tp.status, tp.created_at,
                       COUNT(pt.id) AS test_count
                FROM test_packages tp
                LEFT JOIN package_tests pt ON pt.package_id = tp.id
                GROUP BY tp.id
                ORDER BY tp.package_name";
if ($package_result = $conn->query($package_sql)) {
    while ($row = $package_result->fetch_assoc()) {
        $packages[] = $row;
    }
    $package_result->free();
}

$role_for_links = (string)($_SESSION['role'] ?? 'manager');
$tests_link = $role_for_links === 'superadmin' ? '../superadmin/lists.php' : 'manage_tests.php';
$show_form = $editing_package || $feedback_class === 'error-banner';

require_once '../includes/header.php';
?>

<div class="page-container manage-tests-compact">
    <div class="dashboard-header">
        <div>
            <h1>Manage Packages</h1>
            <p>Create, edit and control bundled test packages.</p>
        </div>
        <div class="actions-container" style="margin-top:0;">
            <a href="<?php echo htmlspecialchars($tests_link); ?>" class="btn-cancel" style="text-decoration:none;">Tests</a>
            <button type="button" class="btn-submit" id="toggle-package-form"><?php echo $show_form ? 'Close Form' : 'Create Package'; ?></button>
        </div>
    </div>

    <?php if ($feedback !== ''): ?>
        <div class="<?php echo htmlspecialchars($feedback_class !== '' ? $feedback_class : 'success-banner'); ?>"><?php echo $feedback; ?></div>
    <?php endif; ?>

    <div class="management-form form-container-collapsible <?php echo $show_form ? 'visible' : ''; ?>" id="package-form-shell">
        <h3><?php echo $editing_package ? 'Edit Package' : 'Create Package'; ?></h3>
        <form id="package-form" action="manage_packages.php<?php echo $editing_package ? '?edit=' . (int)$editing_package['id'] : ''; ?>" method="POST">
            <input type="hidden" name="action" value="save_package">
            <input type="hidden" name="package_id" value="<?php echo (int)($editing_package['id'] ?? 0); ?>">
            <input type="hidden" name="tests_json" id="tests_json" value="">

            <fieldset>
                <legend>Package Basic Details</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label for="package_name">Package Name</label>
                        <input type="text" id="package_name" name="package_name" required value="<?php echo htmlspecialchars((string)($editing_package['package_name'] ?? '')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="package_code">Package Code</label>
                        <input type="text" id="package_code" name="package_code" placeholder="Auto-generated if blank" value="<?php echo htmlspecialchars((string)($editing_package['package_code'] ?? '')); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="2"><?php echo htmlspecialchars((string)($editing_package['description'] ?? '')); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <?php $status_value = (string)($editing_package['status'] ?? 'active'); ?>
                        <select id="status" name="status">
                            <option value="active" <?php echo $status_value === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_value === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="package_price">Package Final Price</label>
                        <input type="number" id="package_price" name="package_price" readonly step="0.01" min="0" value="<?php echo number_format((float)($editing_package['package_price'] ?? 0), 2, '.', ''); ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>Add Tests to Package</legend>
                <div id="package-tests-container"></div>
                <button type="button" class="btn-submit" id="add-package-test-row">+ Add Another Test</button>
                <small id="package-form-hint" class="uid-hint" style="display:block;margin-top:0.5rem;">Select category first, then search/select an existing test or type a custom test name with manual price.</small>
            </fieldset>

            <fieldset>
                <legend>Package Summary</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label>Tests Count</label>
                        <input type="text" id="summary_tests_count" readonly value="0">
                    </div>
                    <div class="form-group">
                        <label>Original Total</label>
                        <input type="text" id="summary_base_total" readonly value="0.00">
                    </div>
                    <div class="form-group">
                        <label>Package Total</label>
                        <input type="text" id="summary_package_total" readonly value="0.00">
                    </div>
                    <div class="form-group">
                        <label>Discount Amount</label>
                        <input type="text" id="summary_discount_amount" readonly value="0.00">
                    </div>
                    <div class="form-group">
                        <label>Discount %</label>
                        <input type="text" id="summary_discount_percent" readonly value="0.00%">
                    </div>
                </div>
            </fieldset>

            <button type="submit" class="btn-submit"><?php echo $editing_package ? 'Update Package' : 'Save Package'; ?></button>
            <a href="manage_packages.php" class="btn-cancel" style="text-decoration:none;">Cancel</a>
        </form>
    </div>

    <?php if ($view_package): ?>
        <div class="detail-section" style="margin-top:1rem;">
            <h3>Package Details: <?php echo htmlspecialchars((string)$view_package['package_name']); ?></h3>
            <div class="detail-grid">
                <p><strong>Code:</strong> <?php echo htmlspecialchars((string)$view_package['package_code']); ?></p>
                <p><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst((string)$view_package['status'])); ?></p>
                <p><strong>Original Total:</strong> Rs <?php echo number_format((float)$view_package['total_base_price'], 2); ?></p>
                <p><strong>Package Price:</strong> Rs <?php echo number_format((float)$view_package['package_price'], 2); ?></p>
                <p><strong>Discount:</strong> Rs <?php echo number_format((float)$view_package['discount_amount'], 2); ?> (<?php echo number_format((float)$view_package['discount_percent'], 2); ?>%)</p>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Test Name</th>
                            <th>Base Price</th>
                            <th>Package Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (($view_package['tests'] ?? []) as $idx => $test): ?>
                            <?php
                                $is_custom_test = ((int)($test['is_custom'] ?? 0) === 1) || ((int)($test['test_id'] ?? 0) <= 0);
                                if ($is_custom_test) {
                                    $custom_name = trim((string)($test['custom_test_name'] ?? ''));
                                    $custom_category = trim((string)($test['test_category'] ?? ''));
                                    $full_name = $custom_name !== '' ? $custom_name : 'Custom Test';
                                    if ($custom_category !== '') {
                                        $full_name = $custom_category . ' - ' . $full_name;
                                    }
                                    $full_name .= ' (Custom)';
                                } else {
                                    $name_main = trim((string)($test['main_test_name'] ?? ''));
                                    $name_sub = trim((string)($test['sub_test_name'] ?? ''));
                                    $full_name = $name_sub !== '' ? ($name_main . ' - ' . $name_sub) : $name_main;
                                }
                            ?>
                            <tr>
                                <td><?php echo (int)$idx + 1; ?></td>
                                <td><?php echo htmlspecialchars($full_name); ?></td>
                                <td>Rs <?php echo number_format((float)$test['base_test_price'], 2); ?></td>
                                <td>Rs <?php echo number_format((float)$test['package_test_price'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($view_package['tests'])): ?>
                            <tr><td colspan="4" style="text-align:center;">No tests configured for this package.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="table-container" style="margin-top:1rem;">
        <h3>Existing Packages</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Package Code</th>
                        <th>Package Name</th>
                        <th>Tests</th>
                        <th>Original Total</th>
                        <th>Package Price</th>
                        <th>Discount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($packages)): ?>
                        <?php foreach ($packages as $pkg): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$pkg['package_code']); ?></td>
                                <td><?php echo htmlspecialchars((string)$pkg['package_name']); ?></td>
                                <td><?php echo (int)$pkg['test_count']; ?></td>
                                <td>Rs <?php echo number_format((float)$pkg['total_base_price'], 2); ?></td>
                                <td>Rs <?php echo number_format((float)$pkg['package_price'], 2); ?></td>
                                <td>
                                    Rs <?php echo number_format((float)$pkg['discount_amount'], 2); ?>
                                    (<?php echo number_format((float)$pkg['discount_percent'], 2); ?>%)
                                </td>
                                <td><?php echo htmlspecialchars(ucfirst((string)$pkg['status'])); ?></td>
                                <td class="actions-cell">
                                    <a href="?view=<?php echo (int)$pkg['id']; ?>" class="btn-action btn-view">View</a>
                                    <a href="?edit=<?php echo (int)$pkg['id']; ?>" class="btn-action btn-edit">Edit</a>

                                    <form action="manage_packages.php" method="POST" style="display:inline-block;">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="package_id" value="<?php echo (int)$pkg['id']; ?>">
                                        <button type="submit" class="btn-action btn-warning"><?php echo ((string)$pkg['status'] === 'active') ? 'Disable' : 'Enable'; ?></button>
                                    </form>

                                    <form action="manage_packages.php" method="POST" style="display:inline-block;" onsubmit="return confirm('Delete this package? This cannot be undone.');">
                                        <input type="hidden" name="action" value="delete_package">
                                        <input type="hidden" name="package_id" value="<?php echo (int)$pkg['id']; ?>">
                                        <button type="submit" class="btn-action btn-delete">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align:center;">No packages created yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function() {
    const packageFormShell = document.getElementById('package-form-shell');
    const toggleFormBtn = document.getElementById('toggle-package-form');
    const packageForm = document.getElementById('package-form');
    const testsContainer = document.getElementById('package-tests-container');
    const addRowBtn = document.getElementById('add-package-test-row');
    const testsJsonInput = document.getElementById('tests_json');
    const packagePriceInput = document.getElementById('package_price');

    if (!packageForm || !testsContainer || !addRowBtn || !testsJsonInput) {
        return;
    }

    const allTests = <?php echo json_encode($tests, JSON_UNESCAPED_UNICODE); ?>;
    const editingTests = <?php echo json_encode(($editing_package['tests'] ?? []), JSON_UNESCAPED_UNICODE); ?>;
    const testsById = {};
    const testsByCategory = {};
    let rowSerial = 0;

    allTests.forEach(function(test) {
        const id = String(test.id || '');
        const category = String(test.category || 'General').trim() || 'General';
        test.category = category;
        testsById[id] = test;
        if (!testsByCategory[category]) {
            testsByCategory[category] = [];
        }
        testsByCategory[category].push(test);
    });

    Object.keys(testsByCategory).forEach(function(category) {
        testsByCategory[category].sort(function(a, b) {
            return String(a.label || '').localeCompare(String(b.label || ''));
        });
    });

    const categories = Object.keys(testsByCategory).sort(function(a, b) {
        return a.localeCompare(b);
    });

    function roundMoney(value) {
        const n = Number(value);
        if (!Number.isFinite(n) || n < 0) {
            return 0;
        }
        return Math.round((n + Number.EPSILON) * 100) / 100;
    }

    function formatAmount(value) {
        return roundMoney(value).toFixed(2);
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function normalizeText(value) {
        return String(value || '').trim().replace(/\s+/g, ' ').toLowerCase();
    }

    function buildCategoryOptions(selectedCategory) {
        let options = '<option value="">Select Category</option>';
        categories.forEach(function(category) {
            const selected = category === selectedCategory ? ' selected' : '';
            options += '<option value="' + escapeHtml(category) + '"' + selected + '>' + escapeHtml(category) + '</option>';
        });
        return options;
    }

    function findExistingTestByInput(category, inputText) {
        const normalizedInput = normalizeText(inputText);
        if (!normalizedInput || !category || !testsByCategory[category]) {
            return null;
        }
        const list = testsByCategory[category];
        for (let i = 0; i < list.length; i += 1) {
            const plainLabel = normalizeText(list[i].label);
            const pricedLabel = normalizeText(list[i].display_label || '');
            if (plainLabel === normalizedInput || pricedLabel === normalizedInput) {
                return list[i];
            }
        }
        return null;
    }

    function populateDatalist(row, category) {
        const datalist = row.querySelector('datalist');
        if (!datalist) {
            return;
        }
        datalist.innerHTML = '';
        const list = category ? (testsByCategory[category] || []) : [];
        list.forEach(function(test) {
            const option = document.createElement('option');
            option.value = test.display_label || test.label;
            datalist.appendChild(option);
        });
    }

    function refreshRowState(row, forcePriceFromBase) {
        const categorySelect = row.querySelector('.package-test-category');
        const testInput = row.querySelector('.package-test-input');
        const hiddenIdInput = row.querySelector('.package-test-id');
        const baseInput = row.querySelector('.package-test-base');
        const priceInput = row.querySelector('.package-test-price');
        const modeHint = row.querySelector('.package-test-mode');

        if (!categorySelect || !testInput || !hiddenIdInput || !baseInput || !priceInput || !modeHint) {
            return;
        }

        const category = categorySelect.value;
        populateDatalist(row, category);

        if (!category) {
            testInput.disabled = true;
            testInput.placeholder = 'Select category first';
            hiddenIdInput.value = '';
            row.dataset.rowType = 'pending';
            modeHint.textContent = 'Select a category first.';
            if (forcePriceFromBase) {
                baseInput.value = formatAmount(0);
                priceInput.value = formatAmount(0);
            }
            updateSummary();
            return;
        }

        testInput.disabled = false;
        testInput.placeholder = 'Search existing test or type custom test';

        const existing = findExistingTestByInput(category, testInput.value);
        if (existing) {
            hiddenIdInput.value = String(existing.id);
            testInput.value = existing.display_label || existing.label;
            baseInput.value = formatAmount(existing.price);
            priceInput.max = formatAmount(existing.price);
            if (forcePriceFromBase || testInput.dataset.lastMode !== 'existing' || testInput.value.trim() === '') {
                priceInput.value = formatAmount(existing.price);
            }
            row.dataset.rowType = 'existing';
            modeHint.textContent = 'Existing test selected.';
            testInput.dataset.lastMode = 'existing';
            updateSummary();
            return;
        }

        hiddenIdInput.value = '';
    priceInput.removeAttribute('max');
        const customName = testInput.value.trim();
        if (customName !== '') {
            row.dataset.rowType = 'custom';
            modeHint.textContent = 'Custom test will be saved in this package only.';
            if (forcePriceFromBase || testInput.dataset.lastMode === 'existing') {
                if (roundMoney(priceInput.value) <= 0) {
                    priceInput.value = formatAmount(0);
                }
            }
            baseInput.value = formatAmount(priceInput.value);
            testInput.dataset.lastMode = 'custom';
        } else {
            row.dataset.rowType = 'pending';
            modeHint.textContent = 'Search/select existing test or type a custom test name.';
            if (forcePriceFromBase) {
                baseInput.value = formatAmount(0);
                priceInput.value = formatAmount(0);
            } else if (testInput.dataset.lastMode === 'existing') {
                baseInput.value = formatAmount(0);
            }
            testInput.dataset.lastMode = 'pending';
        }

        updateSummary();
    }

    function validatePackageSpecificPrice(row, showWarning) {
        const baseInput = row.querySelector('.package-test-base');
        const priceInput = row.querySelector('.package-test-price');
        if (!baseInput || !priceInput) {
            return true;
        }

        if (row.dataset.rowType !== 'existing') {
            priceInput.setCustomValidity('');
            return true;
        }

        const basePrice = roundMoney(baseInput.value);
        const packagePrice = roundMoney(priceInput.value);

        if (packagePrice > basePrice) {
            priceInput.value = '';
            priceInput.setCustomValidity('Package-specific price cannot exceed base price.');
            if (showWarning) {
                priceInput.reportValidity();
            }
            return false;
        }

        priceInput.setCustomValidity('');
        return true;
    }

    function createRow(prefill) {
        rowSerial += 1;
        const row = document.createElement('div');
        row.className = 'form-row package-test-row';

        const datalistId = 'package-test-options-' + rowSerial;
        const prefillTestId = prefill && prefill.test_id ? Number(prefill.test_id) : 0;
        const prefillIsCustom = prefill && ((Number(prefill.is_custom || 0) === 1) || prefillTestId <= 0);
        const prefillCategoryFromTest = prefillTestId > 0 && testsById[String(prefillTestId)]
            ? String(testsById[String(prefillTestId)].category || '')
            : '';
        const prefillCategory = String((prefill && prefill.test_category) || prefillCategoryFromTest || '');
        const prefillLabel = prefillIsCustom
            ? String((prefill && prefill.custom_test_name) || '')
            : String((prefillTestId > 0 && testsById[String(prefillTestId)]) ? (testsById[String(prefillTestId)].display_label || testsById[String(prefillTestId)].label) : '');
        const prefillBase = prefill && prefill.base_test_price !== undefined ? Number(prefill.base_test_price) : 0;
        const prefillPrice = prefill && prefill.package_test_price !== undefined ? Number(prefill.package_test_price) : prefillBase;

        row.innerHTML = '' +
            '<div class="form-group">' +
                '<label>Category</label>' +
                '<select class="package-test-category" required>' + buildCategoryOptions(prefillCategory) + '</select>' +
            '</div>' +
            '<div class="form-group">' +
                '<label>Select Test (Search or Type New)</label>' +
                '<div class="package-test-input-row">' +
                    '<input type="text" class="package-test-input" list="' + datalistId + '" value="' + escapeHtml(prefillLabel) + '" autocomplete="off" placeholder="Select category first" required>' +
                    '<button type="button" class="package-test-clear" aria-label="Clear selected test" title="Clear selected test">x</button>' +
                '</div>' +
                '<datalist id="' + datalistId + '"></datalist>' +
                '<small class="uid-hint package-test-mode">Search/select existing test or type a custom test name.</small>' +
                '<input type="hidden" class="package-test-id" value="' + (prefillTestId > 0 ? String(prefillTestId) : '') + '">' +
            '</div>' +
            '<div class="form-group">' +
                '<label>Original/Base Price</label>' +
                '<input type="number" class="package-test-base" readonly value="' + formatAmount(prefillBase) + '">' +
            '</div>' +
            '<div class="form-group">' +
                '<label>Package-specific Price</label>' +
                '<input type="number" class="package-test-price" min="0" step="0.01" value="' + formatAmount(prefillPrice) + '" required>' +
            '</div>' +
            '<div class="form-group package-test-action-group">' +
                '<label>Action</label>' +
                '<button type="button" class="btn-action btn-delete package-test-remove">Remove</button>' +
            '</div>';

        testsContainer.appendChild(row);

        const categorySelect = row.querySelector('.package-test-category');
        const testInput = row.querySelector('.package-test-input');
        const hiddenIdInput = row.querySelector('.package-test-id');
        const baseInput = row.querySelector('.package-test-base');
        const priceInput = row.querySelector('.package-test-price');
        const clearTestBtn = row.querySelector('.package-test-clear');
        const removeBtn = row.querySelector('.package-test-remove');

        if (prefillTestId > 0 && testsById[String(prefillTestId)]) {
            hiddenIdInput.value = String(prefillTestId);
            row.dataset.rowType = 'existing';
            testInput.dataset.lastMode = 'existing';
        } else if (prefillLabel.trim() !== '') {
            hiddenIdInput.value = '';
            row.dataset.rowType = 'custom';
            testInput.dataset.lastMode = 'custom';
        } else {
            hiddenIdInput.value = '';
            row.dataset.rowType = 'pending';
            testInput.dataset.lastMode = 'pending';
        }

        categorySelect.addEventListener('change', function() {
            hiddenIdInput.value = '';
            const stillValidForCategory = findExistingTestByInput(categorySelect.value, testInput.value);
            if (!stillValidForCategory) {
                testInput.value = '';
            }
            refreshRowState(row, true);
        });

        testInput.addEventListener('input', function() {
            refreshRowState(row, false);
        });

        testInput.addEventListener('change', function() {
            refreshRowState(row, false);
        });

        if (clearTestBtn) {
            clearTestBtn.addEventListener('click', function() {
                testInput.value = '';
                hiddenIdInput.value = '';
                refreshRowState(row, true);
                testInput.focus();
            });
        }

        priceInput.addEventListener('input', function() {
            if (!Number.isFinite(Number(priceInput.value)) || Number(priceInput.value) < 0) {
                priceInput.value = formatAmount(0);
            }
            validatePackageSpecificPrice(row, true);
            if (row.dataset.rowType === 'custom') {
                baseInput.value = formatAmount(priceInput.value);
            }
            updateSummary();
        });

        priceInput.addEventListener('blur', function() {
            if (!validatePackageSpecificPrice(row, false)) {
                updateSummary();
                return;
            }
            priceInput.value = formatAmount(priceInput.value);
            if (row.dataset.rowType === 'custom') {
                baseInput.value = formatAmount(priceInput.value);
            }
            updateSummary();
        });

        removeBtn.addEventListener('click', function() {
            row.remove();
            if (!testsContainer.querySelector('.package-test-row')) {
                createRow();
            }
            updateSummary();
        });

        refreshRowState(row, false);
    }

    function updateSummary() {
        const rows = testsContainer.querySelectorAll('.package-test-row');
        let testsCount = 0;
        let baseTotal = 0;
        let packageTotal = 0;

        rows.forEach(function(row) {
            const categorySelect = row.querySelector('.package-test-category');
            const testInput = row.querySelector('.package-test-input');
            const hiddenIdInput = row.querySelector('.package-test-id');
            const baseInput = row.querySelector('.package-test-base');
            const priceInput = row.querySelector('.package-test-price');

            if (!categorySelect || !testInput || !hiddenIdInput || !baseInput || !priceInput) {
                return;
            }

            const category = categorySelect.value.trim();
            const typedName = testInput.value.trim();
            const testId = Number(hiddenIdInput.value || 0);

            if (!category || (!testId && typedName === '')) {
                return;
            }

            if (!validatePackageSpecificPrice(row, false)) {
                return;
            }

            let basePrice = roundMoney(baseInput.value);
            const packagePrice = roundMoney(priceInput.value);

            if (row.dataset.rowType === 'custom' || testId <= 0) {
                basePrice = packagePrice;
                baseInput.value = formatAmount(basePrice);
            }

            testsCount += 1;
            baseTotal += basePrice;
            packageTotal += packagePrice;
        });

        const discountAmount = Math.max(baseTotal - packageTotal, 0);
        const discountPercent = baseTotal > 0 ? ((discountAmount / baseTotal) * 100) : 0;

        document.getElementById('summary_tests_count').value = String(testsCount);
        document.getElementById('summary_base_total').value = formatAmount(baseTotal);
        document.getElementById('summary_package_total').value = formatAmount(packageTotal);
        document.getElementById('summary_discount_amount').value = formatAmount(discountAmount);
        document.getElementById('summary_discount_percent').value = discountPercent.toFixed(2) + '%';

        if (packagePriceInput) {
            packagePriceInput.value = formatAmount(packageTotal);
        }
    }

    function collectPayload() {
        const rows = testsContainer.querySelectorAll('.package-test-row');
        const payload = [];
        const seenExisting = {};
        const seenCustom = {};

        for (let i = 0; i < rows.length; i += 1) {
            const row = rows[i];
            const categorySelect = row.querySelector('.package-test-category');
            const testInput = row.querySelector('.package-test-input');
            const hiddenIdInput = row.querySelector('.package-test-id');
            const baseInput = row.querySelector('.package-test-base');
            const priceInput = row.querySelector('.package-test-price');

            const category = categorySelect ? categorySelect.value.trim() : '';
            const typedName = testInput ? testInput.value.trim() : '';
            const testId = hiddenIdInput && hiddenIdInput.value ? Number(hiddenIdInput.value) : 0;
            const basePrice = baseInput ? roundMoney(baseInput.value) : 0;
            const packagePrice = priceInput ? roundMoney(priceInput.value) : 0;

            if (!category && typedName === '' && !testId) {
                continue;
            }

            if (!category) {
                throw new Error('Please select a category for each package row.');
            }

            if (!validatePackageSpecificPrice(row, false)) {
                if (priceInput) {
                    priceInput.reportValidity();
                }
                throw new Error('Package-specific price cannot exceed base price.');
            }

            if (testId > 0) {
                if (packagePrice > basePrice) {
                    if (priceInput) {
                        priceInput.value = '';
                        priceInput.setCustomValidity('Package-specific price cannot exceed base price.');
                        priceInput.reportValidity();
                    }
                    throw new Error('Package-specific price cannot exceed base price.');
                }
                if (seenExisting[testId]) {
                    throw new Error('Duplicate existing tests are not allowed in package rows.');
                }
                seenExisting[testId] = true;
                payload.push({
                    test_id: testId,
                    custom_test_name: '',
                    test_category: category,
                    package_test_price: Number(packagePrice.toFixed(2))
                });
                continue;
            }

            if (typedName === '') {
                throw new Error('Select an existing test or enter a custom test name.');
            }

            if (packagePrice <= 0) {
                throw new Error('Custom test price must be greater than zero.');
            }

            const customKey = normalizeText(category + '::' + typedName);
            if (seenCustom[customKey]) {
                throw new Error('Duplicate custom tests are not allowed in package rows.');
            }
            seenCustom[customKey] = true;

            payload.push({
                test_id: 0,
                custom_test_name: typedName,
                test_category: category,
                package_test_price: Number(packagePrice.toFixed(2))
            });
        }

        if (!payload.length) {
            throw new Error('Please add at least one test to the package.');
        }

        return payload;
    }

    packageForm.addEventListener('submit', function(e) {
        try {
            updateSummary();
            const payload = collectPayload();
            testsJsonInput.value = JSON.stringify(payload);
        } catch (err) {
            e.preventDefault();
            alert(err.message || 'Please correct package rows before saving.');
        }
    });

    addRowBtn.addEventListener('click', function() {
        createRow();
        updateSummary();
    });

    if (toggleFormBtn && packageFormShell) {
        toggleFormBtn.addEventListener('click', function() {
            if (packageFormShell.classList.contains('visible')) {
                window.location.href = 'manage_packages.php';
                return;
            }
            packageFormShell.classList.add('visible');
            toggleFormBtn.textContent = 'Close Form';
        });
    }

    if (Array.isArray(editingTests) && editingTests.length) {
        editingTests.forEach(function(test) {
            createRow({
                test_id: Number(test.test_id || 0),
                is_custom: Number(test.is_custom || 0),
                custom_test_name: String(test.custom_test_name || ''),
                test_category: String(test.test_category || ''),
                base_test_price: Number(test.base_test_price || 0),
                package_test_price: Number(test.package_test_price || 0)
            });
        });
    } else {
        createRow();
    }

    updateSummary();
})();
</script>

<?php require_once '../includes/footer.php'; ?>
