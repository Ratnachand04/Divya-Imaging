<?php
$page_title = 'Manage Packages';
$required_role = ['manager', 'superadmin'];

require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_package_management_schema($conn);

$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';
$tests_source_validation = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't_val') : '`tests` t_val';
$test_packages_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'test_packages', 'tp') : '`test_packages` tp';
$package_tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'package_tests', 'pt') : '`package_tests` pt';

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

            $test_map = [];
            foreach ($tests_payload as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $test_id = isset($row['test_id']) ? (int)$row['test_id'] : 0;
                $package_test_price = isset($row['package_test_price']) ? round((float)$row['package_test_price'], 2) : 0.00;

                if ($test_id <= 0) {
                    throw new Exception('Every package row must have a valid test selected.');
                }
                if (isset($test_map[$test_id])) {
                    throw new Exception('Duplicate tests are not allowed in one package.');
                }
                if ($package_test_price < 0) {
                    throw new Exception('Package test price cannot be negative.');
                }

                $test_map[$test_id] = [
                    'package_test_price' => $package_test_price,
                    'display_order' => (int)$idx + 1
                ];
            }

            if (empty($test_map)) {
                throw new Exception('Please add at least one valid test to the package.');
            }

            $test_ids = array_keys($test_map);
            $placeholders = implode(',', array_fill(0, count($test_ids), '?'));
            $types = str_repeat('i', count($test_ids));

            $tests_stmt = $conn->prepare("SELECT t_val.id, t_val.price FROM {$tests_source_validation} WHERE t_val.id IN ($placeholders)");
            if (!$tests_stmt) {
                throw new Exception('Failed to validate tests: ' . $conn->error);
            }
            $tests_stmt->bind_param($types, ...$test_ids);
            $tests_stmt->execute();
            $tests_result = $tests_stmt->get_result();

            $base_price_map = [];
            while ($test_row = $tests_result->fetch_assoc()) {
                $base_price_map[(int)$test_row['id']] = round((float)$test_row['price'], 2);
            }
            $tests_stmt->close();

            foreach ($test_ids as $test_id) {
                if (!array_key_exists($test_id, $base_price_map)) {
                    throw new Exception('One or more selected tests were not found. Add new test first.');
                }
            }

            $total_base_price = 0.00;
            $total_package_price = 0.00;
            foreach ($test_map as $test_id => &$meta) {
                $base_price = $base_price_map[$test_id];
                $meta['base_test_price'] = $base_price;
                $total_base_price += $base_price;
                $total_package_price += $meta['package_test_price'];
            }
            unset($meta);

            $total_base_price = round($total_base_price, 2);
            $total_package_price = round($total_package_price, 2);
            $discount_amount = round(max($total_base_price - $total_package_price, 0), 2);
            $discount_percent = $total_base_price > 0 ? round(($discount_amount / $total_base_price) * 100, 2) : 0.00;

            if ($package_id > 0) {
                $dup_stmt = $conn->prepare("SELECT tp.id FROM {$test_packages_source} WHERE tp.package_code = ? AND tp.id != ? LIMIT 1");
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
                $dup_stmt = $conn->prepare("SELECT tp.id FROM {$test_packages_source} WHERE tp.package_code = ? LIMIT 1");
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

            $pt_stmt = $conn->prepare('INSERT INTO package_tests (package_id, test_id, base_test_price, package_test_price, display_order) VALUES (?, ?, ?, ?, ?)');
            if (!$pt_stmt) {
                throw new Exception('Failed to save package tests: ' . $conn->error);
            }
            foreach ($test_map as $test_id => $meta) {
                $base_test_price = (float)$meta['base_test_price'];
                $package_test_price = (float)$meta['package_test_price'];
                $display_order = (int)$meta['display_order'];
                $pt_stmt->bind_param('iiddi', $package_id, $test_id, $base_test_price, $package_test_price, $display_order);
                if (!$pt_stmt->execute()) {
                    $err = $pt_stmt->error;
                    $pt_stmt->close();
                    throw new Exception('Failed to save package test item: ' . $err);
                }
            }
            $pt_stmt->close();

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

            $status_stmt = $conn->prepare("SELECT tp.status FROM {$test_packages_source} WHERE tp.id = ? LIMIT 1");
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
$tests_query = $conn->query("SELECT t.id, t.main_test_name, t.sub_test_name, t.price FROM {$tests_source} ORDER BY t.main_test_name, t.sub_test_name");
if ($tests_query) {
    while ($row = $tests_query->fetch_assoc()) {
        $sub = trim((string)$row['sub_test_name']);
        $main = trim((string)$row['main_test_name']);
        $label = $sub !== '' ? ($main . ' - ' . $sub) : $main;
        $tests[] = [
            'id' => (int)$row['id'],
            'label' => $label,
            'price' => round((float)$row['price'], 2)
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
                FROM {$test_packages_source}
                LEFT JOIN {$package_tests_source} ON pt.package_id = tp.id
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
                <small id="package-form-hint" class="uid-hint" style="display:block;margin-top:0.5rem;">If a test is not listed here, add it first in Tests management.</small>
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
                                $name_main = trim((string)($test['main_test_name'] ?? ''));
                                $name_sub = trim((string)($test['sub_test_name'] ?? ''));
                                $full_name = $name_sub !== '' ? ($name_main . ' - ' . $name_sub) : $name_main;
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
    allTests.forEach(function(test) {
        testsById[String(test.id)] = test;
    });

    function formatAmount(value) {
        const n = Number(value) || 0;
        return n.toFixed(2);
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function getSelectedTestIds(excludeRow) {
        const ids = [];
        const rows = testsContainer.querySelectorAll('.package-test-row');
        rows.forEach(function(row) {
            if (excludeRow && row === excludeRow) {
                return;
            }
            const select = row.querySelector('.package-test-select');
            if (select && select.value) {
                ids.push(select.value);
            }
        });
        return ids;
    }

    function buildSelectOptions(selectedId) {
        let options = '<option value="">Select Test</option>';
        allTests.forEach(function(test) {
            const isSelected = String(selectedId) === String(test.id) ? ' selected' : '';
            options += '<option value="' + test.id + '"' + isSelected + '>' + escapeHtml(test.label) + '</option>';
        });
        return options;
    }

    function createRow(prefill) {
        const row = document.createElement('div');
        row.className = 'form-row package-test-row';

        const selectedTestId = prefill && prefill.test_id ? String(prefill.test_id) : '';
        const basePrice = prefill && prefill.base_test_price ? Number(prefill.base_test_price) : 0;
        const packagePrice = prefill && prefill.package_test_price !== undefined ? Number(prefill.package_test_price) : basePrice;

        row.innerHTML = '' +
            '<div class="form-group">' +
                '<label>Test</label>' +
                '<select class="package-test-select" required>' + buildSelectOptions(selectedTestId) + '</select>' +
            '</div>' +
            '<div class="form-group">' +
                '<label>Original/Base Price</label>' +
                '<input type="number" class="package-test-base" readonly value="' + formatAmount(basePrice) + '">' +
            '</div>' +
            '<div class="form-group">' +
                '<label>Package-specific Price</label>' +
                '<input type="number" class="package-test-price" min="0" step="0.01" value="' + formatAmount(packagePrice) + '" required>' +
            '</div>' +
            '<div class="form-group" style="max-width:140px;">' +
                '<label>Action</label>' +
                '<button type="button" class="btn-action btn-delete package-test-remove">Remove</button>' +
            '</div>';

        testsContainer.appendChild(row);

        const select = row.querySelector('.package-test-select');
        const baseInput = row.querySelector('.package-test-base');
        const priceInput = row.querySelector('.package-test-price');
        const removeBtn = row.querySelector('.package-test-remove');

        function syncFromSelectedTest(forcePrice) {
            const selected = testsById[String(select.value)] || null;
            if (!selected) {
                baseInput.value = formatAmount(0);
                if (forcePrice) {
                    priceInput.value = formatAmount(0);
                }
                updateSummary();
                return;
            }

            baseInput.value = formatAmount(selected.price);
            if (forcePrice || !priceInput.value) {
                priceInput.value = formatAmount(selected.price);
            }
            updateSummary();
        }

        select.addEventListener('change', function() {
            if (select.value) {
                const selectedElsewhere = getSelectedTestIds(row);
                if (selectedElsewhere.indexOf(select.value) !== -1) {
                    alert('This test is already added in the package.');
                    select.value = '';
                    syncFromSelectedTest(true);
                    return;
                }
            }
            syncFromSelectedTest(true);
        });

        priceInput.addEventListener('input', function() {
            if (Number(priceInput.value) < 0 || !isFinite(Number(priceInput.value))) {
                priceInput.value = formatAmount(0);
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

        syncFromSelectedTest(false);
    }

    function updateSummary() {
        const rows = testsContainer.querySelectorAll('.package-test-row');
        let testsCount = 0;
        let baseTotal = 0;
        let packageTotal = 0;

        rows.forEach(function(row) {
            const select = row.querySelector('.package-test-select');
            const baseInput = row.querySelector('.package-test-base');
            const priceInput = row.querySelector('.package-test-price');

            if (!select || !priceInput || !baseInput || !select.value) {
                return;
            }

            const basePrice = Number(baseInput.value) || 0;
            const packagePrice = Math.max(Number(priceInput.value) || 0, 0);

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
        const seen = {};

        for (let i = 0; i < rows.length; i += 1) {
            const row = rows[i];
            const select = row.querySelector('.package-test-select');
            const priceInput = row.querySelector('.package-test-price');

            const testId = select && select.value ? Number(select.value) : 0;
            const packagePrice = priceInput ? Math.max(Number(priceInput.value) || 0, 0) : 0;

            if (!testId) {
                continue;
            }
            if (seen[testId]) {
                throw new Error('Duplicate tests are not allowed in package rows.');
            }
            seen[testId] = true;

            payload.push({
                test_id: testId,
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
