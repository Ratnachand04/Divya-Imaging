<?php
// includes/functions.php

/**
 * Logs a critical system action to the system_audit_log table.
 *
 * @param mysqli $conn The database connection object.
 * @param string $action_type A descriptor for the action (e.g., 'BILL_CREATED').
 * @param int|null $target_id The ID of the record that was affected.
 * @param string $details A human-readable description of the action.
 */
function log_system_action($conn, $action_type, $target_id = null, $details = '') {
    // Ensure session is started and user is logged in to get user details
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
        $user_id = $_SESSION['user_id'];
        $username = $_SESSION['username'];

        $stmt = $conn->prepare("INSERT INTO system_audit_log (user_id, username, action_type, target_id, details) VALUES (?, ?, ?, ?, ?)");
        // For target_id, if it's null, we pass null, otherwise we pass it as an integer.
        // PHP's bind_param doesn't handle nulls well with type 'i', so we check it.
        if ($target_id === null) {
            $stmt->bind_param("isss", $user_id, $username, $action_type, $details);
            // This is a workaround, re-binding with null target
            $stmt = $conn->prepare("INSERT INTO system_audit_log (user_id, username, action_type, target_id, details) VALUES (?, ?, ?, NULL, ?)");
            $stmt->bind_param("isss", $user_id, $username, $action_type, $details);

        } else {
             $stmt->bind_param("isiss", $user_id, $username, $action_type, $target_id, $details);
        }
       
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Ensures flexible app settings storage exists and is migration-friendly.
 *
 * @param mysqli $conn The database connection object.
 * @throws Exception When schema operations fail.
 */
function app_settings_ensure_schema(mysqli $conn) {
    $create_sql = "CREATE TABLE IF NOT EXISTS app_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_scope VARCHAR(50) NOT NULL DEFAULT 'global',
        scope_id INT NOT NULL DEFAULT 0,
        setting_key VARCHAR(120) NOT NULL,
        setting_value LONGTEXT DEFAULT NULL,
        value_type VARCHAR(20) NOT NULL DEFAULT 'string',
        category VARCHAR(80) DEFAULT NULL,
        metadata_json JSON DEFAULT NULL,
        updated_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_scope_key (setting_scope, scope_id, setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if (!$conn->query($create_sql)) {
        throw new Exception('Unable to ensure app_settings table: ' . $conn->error);
    }

    $columns = [
        'setting_scope' => "VARCHAR(50) NOT NULL DEFAULT 'global' AFTER id",
        'scope_id' => "INT NOT NULL DEFAULT 0 AFTER setting_scope",
        'setting_key' => "VARCHAR(120) NOT NULL AFTER scope_id",
        'setting_value' => "LONGTEXT NULL AFTER setting_key",
        'value_type' => "VARCHAR(20) NOT NULL DEFAULT 'string' AFTER setting_value",
        'category' => "VARCHAR(80) NULL AFTER value_type",
        'metadata_json' => "JSON NULL AFTER category",
        'updated_by' => "INT NULL AFTER metadata_json",
        'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER updated_by",
        'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
    ];

    foreach ($columns as $name => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM app_settings LIKE '{$name}'");
        if ($check && $check->num_rows === 0) {
            if (!$conn->query("ALTER TABLE app_settings ADD COLUMN {$name} {$definition}")) {
                throw new Exception('Unable to add app_settings.' . $name . ': ' . $conn->error);
            }
        }
        if ($check instanceof mysqli_result) {
            $check->free();
        }
    }

    $idx = $conn->query("SHOW INDEX FROM app_settings WHERE Key_name = 'idx_scope_key'");
    if ($idx && $idx->num_rows === 0) {
        if (!$conn->query("ALTER TABLE app_settings ADD INDEX idx_scope_key (setting_scope, scope_id, setting_key)")) {
            throw new Exception('Unable to add app_settings index idx_scope_key: ' . $conn->error);
        }
    }
    if ($idx instanceof mysqli_result) {
        $idx->free();
    }
}

/**
 * Casts a stored settings value by explicit type or fallback default type.
 *
 * @param string|null $value Raw value from DB.
 * @param string|null $value_type Stored type hint.
 * @param mixed $default Fallback default value.
 * @return mixed Typed value.
 */
function app_settings_cast_value($value, $value_type, $default) {
    $type = is_string($value_type) ? strtolower($value_type) : '';
    if ($type === '') {
        if (is_bool($default)) {
            $type = 'bool';
        } elseif (is_int($default)) {
            $type = 'int';
        } elseif (is_float($default)) {
            $type = 'float';
        } else {
            $type = 'string';
        }
    }

    if ($type === 'bool') {
        return $value === '1' || $value === 1 || $value === true;
    }
    if ($type === 'int') {
        return (int)($value ?? 0);
    }
    if ($type === 'float') {
        return (float)($value ?? 0);
    }

    return (string)($value ?? '');
}

/**
 * Retrieves settings for a scope and merges with provided defaults.
 *
 * @param mysqli $conn The database connection object.
 * @param string $scope Logical scope identifier (e.g. manager_user).
 * @param int $scope_id Scope target id (e.g. user id, role id, 0 for global).
 * @param array $defaults Key-value defaults to merge with.
 * @return array Settings merged with defaults.
 */
function app_settings_get_many(mysqli $conn, string $scope, int $scope_id, array $defaults = []): array {
    $settings = $defaults;

    $stmt = $conn->prepare("SELECT setting_key, setting_value, value_type FROM app_settings WHERE setting_scope = ? AND scope_id = ?");
    if (!$stmt) {
        return $settings;
    }

    $stmt->bind_param('si', $scope, $scope_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $key = $row['setting_key'];
            $default_value = array_key_exists($key, $settings) ? $settings[$key] : '';
            $settings[$key] = app_settings_cast_value($row['setting_value'] ?? null, $row['value_type'] ?? null, $default_value);
        }
    }
    $stmt->close();

    return $settings;
}

/**
 * Resolves settings across multiple scopes in precedence order.
 *
 * @param mysqli $conn The database connection object.
 * @param array $defaults Base defaults.
 * @param array $layers Ordered scope layers, low-to-high precedence.
 * @return array Fully merged settings.
 */
function app_settings_resolve(mysqli $conn, array $defaults, array $layers): array {
    $resolved = $defaults;

    foreach ($layers as $layer) {
        if (!is_array($layer) || !isset($layer['scope']) || !isset($layer['scope_id'])) {
            continue;
        }

        $scope = (string)$layer['scope'];
        $scope_id = (int)$layer['scope_id'];
        $resolved = app_settings_get_many($conn, $scope, $scope_id, $resolved);
    }

    return $resolved;
}

/**
 * Saves one setting with update-then-insert behavior.
 *
 * @param mysqli $conn The database connection object.
 * @param string $scope Logical scope identifier (e.g. manager_user).
 * @param int $scope_id Scope target id (e.g. user id, role id, 0 for global).
 * @param string $key Setting key.
 * @param string $value Serialized setting value.
 * @param string $value_type Type hint such as string|bool|int|float.
 * @param string|null $category Optional grouping category.
 * @param int|null $updated_by Optional actor user id.
 * @param string|null $metadata_json Optional JSON metadata.
 * @return bool True when persisted.
 */
function app_settings_set(
    mysqli $conn,
    string $scope,
    int $scope_id,
    string $key,
    string $value,
    string $value_type = 'string',
    $category = null,
    $updated_by = null,
    $metadata_json = null
): bool {
    $category_val = is_string($category) ? $category : null;
    $updated_by_val = is_int($updated_by) ? $updated_by : null;
    $metadata_val = is_string($metadata_json) ? $metadata_json : null;

    $update = $conn->prepare("UPDATE app_settings SET setting_value = ?, value_type = ?, category = ?, metadata_json = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE setting_scope = ? AND scope_id = ? AND setting_key = ?");
    if (!$update) {
        return false;
    }

    $update->bind_param('ssssisis', $value, $value_type, $category_val, $metadata_val, $updated_by_val, $scope, $scope_id, $key);
    $ok = $update->execute();
    $affected = $update->affected_rows;
    $update->close();

    if (!$ok) {
        return false;
    }

    if ($affected > 0) {
        return true;
    }

    $insert = $conn->prepare("INSERT INTO app_settings (setting_scope, scope_id, setting_key, setting_value, value_type, category, metadata_json, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$insert) {
        return false;
    }

    $insert->bind_param('sisssssi', $scope, $scope_id, $key, $value, $value_type, $category_val, $metadata_val, $updated_by_val);
    $insert_ok = $insert->execute();
    $insert->close();

    return $insert_ok;
}

/**
 * Ensures patient uid schema exists and is enforced.
 *
 * @param mysqli $conn The database connection object.
 */
function ensure_patient_registration_schema(mysqli $conn) {
    static $is_checked = false;

    if ($is_checked) {
        return;
    }

    // Migrate old registration_id column to uid if it still exists
    $old_col = $conn->query("SHOW COLUMNS FROM patients LIKE 'registration_id'");
    if ($old_col && $old_col->num_rows > 0) {
        $conn->query("ALTER TABLE patients CHANGE COLUMN registration_id uid VARCHAR(12) NULL");
        // Drop old unique key if present then recreate under new name
        $conn->query("ALTER TABLE patients DROP INDEX uniq_patient_registration_id");
    }
    if ($old_col instanceof mysqli_result) {
        $old_col->free();
    }

    $column_result = $conn->query("SHOW COLUMNS FROM patients LIKE 'uid'");
    if ($column_result && $column_result->num_rows === 0) {
        if (!$conn->query("ALTER TABLE patients ADD COLUMN uid VARCHAR(12) NULL AFTER id")) {
            throw new Exception('Unable to add patients.uid column: ' . $conn->error);
        }
    }
    if ($column_result instanceof mysqli_result) {
        $column_result->free();
    }

    if (!$conn->query("UPDATE patients SET uid = CONCAT('DC', YEAR(created_at), LPAD(id, 4, '0')) WHERE uid IS NULL OR uid = ''")) {
        throw new Exception('Unable to backfill patient UIDs: ' . $conn->error);
    }

    $index_result = $conn->query("SHOW INDEX FROM patients WHERE Key_name = 'uniq_patient_uid'");
    if ($index_result && $index_result->num_rows === 0) {
        if (!$conn->query("ALTER TABLE patients ADD UNIQUE KEY uniq_patient_uid (uid)")) {
            throw new Exception('Unable to create unique index on patients.uid: ' . $conn->error);
        }
    }
    if ($index_result instanceof mysqli_result) {
        $index_result->free();
    }

    if (!$conn->query("ALTER TABLE patients MODIFY uid VARCHAR(12) NOT NULL")) {
        throw new Exception('Unable to enforce NOT NULL on patients.uid: ' . $conn->error);
    }

    $is_checked = true;
}

/**
 * Generates next patient UID in DCYYYYNNNN format.
 *
 * @param mysqli $conn The database connection object.
 * @return string UID like DC20260001.
 */
function generate_patient_uid(mysqli $conn) {
    ensure_patient_registration_schema($conn);

    $year = date('Y');
    $prefix = 'DC' . $year;

    $stmt = $conn->prepare("SELECT uid FROM patients WHERE uid LIKE CONCAT(?, '%') ORDER BY uid DESC LIMIT 1 FOR UPDATE");
    if (!$stmt) {
        throw new Exception('Unable to prepare uid query: ' . $conn->error);
    }

    $stmt->bind_param('s', $prefix);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception('Unable to fetch latest uid: ' . $err);
    }

    $result = $stmt->get_result();
    $latest = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $next_number = 1;
    if ($latest && !empty($latest['uid'])) {
        $last_number = (int)substr($latest['uid'], 6);
        $next_number = $last_number + 1;
    }

    return $prefix . str_pad((string)$next_number, 4, '0', STR_PAD_LEFT);
}

/**
 * Ensures split payment amount columns exist on bills.
 *
 * @param mysqli $conn The database connection object.
 * @throws Exception When schema operations fail.
 */
function ensure_bill_payment_split_columns(mysqli $conn) {
    static $is_checked = false;

    if ($is_checked) {
        return;
    }

    $columns = [
        'cash_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_mode",
        'card_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_mode",
        'upi_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_mode",
        'other_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_mode",
    ];

    foreach ($columns as $name => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM bills LIKE '{$name}'");
        if ($check && $check->num_rows === 0) {
            if (!$conn->query("ALTER TABLE bills ADD COLUMN {$name} {$definition}")) {
                throw new Exception('Unable to add bills.' . $name . ': ' . $conn->error);
            }
        }
        if ($check instanceof mysqli_result) {
            $check->free();
        }
    }

    $is_checked = true;
}

/**
 * Ensures payment_history table exists and supports split payment columns.
 *
 * @param mysqli $conn The database connection object.
 * @throws Exception When schema operations fail.
 */
function ensure_payment_history_split_columns(mysqli $conn) {
    static $is_checked = false;

    if ($is_checked) {
        return;
    }

    $create_sql = "CREATE TABLE IF NOT EXISTS payment_history (
        id INT(11) NOT NULL AUTO_INCREMENT,
        bill_id INT(11) NOT NULL,
        amount_paid_in_txn DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        previous_amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        new_total_amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        payment_mode VARCHAR(50) NOT NULL,
        cash_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        card_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        upi_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        other_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        user_id INT(11) NOT NULL,
        paid_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_payment_history_bill (bill_id),
        KEY idx_payment_history_paid_at (paid_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if (!$conn->query($create_sql)) {
        throw new Exception('Unable to ensure payment_history table: ' . $conn->error);
    }

    $columns = [
        'cash_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_mode",
        'card_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_mode",
        'upi_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_mode",
        'other_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_mode",
    ];

    foreach ($columns as $name => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM payment_history LIKE '{$name}'");
        if ($check && $check->num_rows === 0) {
            if (!$conn->query("ALTER TABLE payment_history ADD COLUMN {$name} {$definition}")) {
                throw new Exception('Unable to add payment_history.' . $name . ': ' . $conn->error);
            }
        }
        if ($check instanceof mysqli_result) {
            $check->free();
        }
    }

    $is_checked = true;
}

/**
 * Returns the list of supported payment modes in forms.
 *
 * @return array
 */
function get_supported_payment_modes(): array {
    return ['Cash', 'Card', 'UPI', 'Cash + Card', 'UPI + Cash', 'Card + UPI'];
}

/**
 * Formats any stored payment mode string into canonical label format.
 *
 * @param string|null $payment_mode Raw payment mode value.
 * @return string Canonical mode label.
 */
function format_payment_mode_label($payment_mode): string {
    $mode = trim((string)$payment_mode);
    if ($mode === '') {
        return 'Cash';
    }

    $normalized = strtolower((string)preg_replace('/\s+/', '', $mode));
    $map = [
        'cash' => 'Cash',
        'card' => 'Card',
        'upi' => 'UPI',
        'other' => 'Other',
        'cash+card' => 'Cash + Card',
        'card+cash' => 'Cash + Card',
        'upi+cash' => 'UPI + Cash',
        'cash+upi' => 'UPI + Cash',
        'card+upi' => 'Card + UPI',
        'upi+card' => 'Card + UPI',
    ];

    if (isset($map[$normalized])) {
        return $map[$normalized];
    }

    $parts = preg_split('/\+/', $mode);
    $parts = array_filter(array_map(function($part) {
        $piece = trim((string)$part);
        if ($piece === '') {
            return '';
        }
        if (strtolower($piece) === 'upi') {
            return 'UPI';
        }
        return ucwords(strtolower($piece));
    }, $parts));

    if (empty($parts)) {
        return 'Cash';
    }

    return implode(' + ', $parts);
}

/**
 * Sanitizes payment mode for form handling.
 *
 * @param string|null $payment_mode Raw mode input.
 * @return string Supported payment mode.
 */
function sanitize_payment_mode_input($payment_mode): string {
    $label = format_payment_mode_label($payment_mode);
    return in_array($label, get_supported_payment_modes(), true) ? $label : 'Cash';
}

/**
 * Resolves a mode label from split values; falls back to provided mode.
 *
 * @param array $split Keys: cash, card, upi, other.
 * @param string $fallback_mode Fallback mode when no split values are present.
 * @return string Mode label.
 */
function resolve_payment_mode_from_split(array $split, string $fallback_mode = 'Cash'): string {
    $cash = (float)($split['cash'] ?? 0);
    $card = (float)($split['card'] ?? 0);
    $upi = (float)($split['upi'] ?? 0);
    $other = (float)($split['other'] ?? 0);

    $active = [];
    if ($cash > 0.0001) { $active[] = 'Cash'; }
    if ($card > 0.0001) { $active[] = 'Card'; }
    if ($upi > 0.0001) { $active[] = 'UPI'; }
    if ($other > 0.0001) { $active[] = 'Other'; }

    if (count($active) === 0) {
        return format_payment_mode_label($fallback_mode);
    }
    if (count($active) === 1) {
        return $active[0];
    }
    if (count($active) === 2 && in_array('Cash', $active, true) && in_array('Card', $active, true)) {
        return 'Cash + Card';
    }
    if (count($active) === 2 && in_array('Cash', $active, true) && in_array('UPI', $active, true)) {
        return 'UPI + Cash';
    }
    if (count($active) === 2 && in_array('Card', $active, true) && in_array('UPI', $active, true)) {
        return 'Card + UPI';
    }

    return implode(' + ', $active);
}

/**
 * Validates split-payment input and returns normalized split values.
 *
 * @param array $source Source data (typically $_POST).
 * @param string|null $payment_mode Selected payment mode.
 * @param float $expected_amount Amount that must be allocated across split fields.
 * @return array Keys: cash_amount, card_amount, upi_amount, other_amount.
 * @throws Exception When split values are invalid.
 */
function build_payment_split_from_input(array $source, $payment_mode, float $expected_amount): array {
    $mode = sanitize_payment_mode_input((string)$payment_mode);
    $expected = round(max(0.0, $expected_amount), 2);

    $fields = [
        'cash' => 'split_cash_amount',
        'card' => 'split_card_amount',
        'upi' => 'split_upi_amount',
        'other' => 'split_other_amount',
    ];

    $raw = [];
    $split = ['cash' => 0.0, 'card' => 0.0, 'upi' => 0.0, 'other' => 0.0];

    foreach ($fields as $key => $field_name) {
        $raw_val = isset($source[$field_name]) ? trim((string)$source[$field_name]) : '';
        $raw[$key] = $raw_val;

        if ($raw_val === '') {
            continue;
        }
        if (!is_numeric($raw_val)) {
            throw new Exception('Please enter valid numeric split amounts.');
        }

        $amount = round((float)$raw_val, 2);
        if ($amount < 0) {
            throw new Exception('Split amounts cannot be negative.');
        }

        $split[$key] = $amount;
    }

    if ($expected <= 0.0001) {
        return [
            'cash_amount' => 0.0,
            'card_amount' => 0.0,
            'upi_amount' => 0.0,
            'other_amount' => 0.0,
        ];
    }

    if ($mode === 'Cash') {
        $split = ['cash' => $expected, 'card' => 0.0, 'upi' => 0.0, 'other' => 0.0];
    } elseif ($mode === 'Card') {
        $split = ['cash' => 0.0, 'card' => $expected, 'upi' => 0.0, 'other' => 0.0];
    } elseif ($mode === 'UPI') {
        $split = ['cash' => 0.0, 'card' => 0.0, 'upi' => $expected, 'other' => 0.0];
    } elseif ($mode === 'Cash + Card') {
        if ($raw['cash'] === '' || $raw['card'] === '') {
            throw new Exception('Please enter both Cash and Card split amounts.');
        }
        if ($split['cash'] <= 0 || $split['card'] <= 0) {
            throw new Exception('Cash and Card split amounts must be greater than zero.');
        }
        if ($split['upi'] > 0 || $split['other'] > 0) {
            throw new Exception('Only Cash and Card amounts are allowed for Cash + Card mode.');
        }
    } elseif ($mode === 'UPI + Cash') {
        if ($raw['upi'] === '' || $raw['cash'] === '') {
            throw new Exception('Please enter both UPI and Cash split amounts.');
        }
        if ($split['upi'] <= 0 || $split['cash'] <= 0) {
            throw new Exception('UPI and Cash split amounts must be greater than zero.');
        }
        if ($split['card'] > 0 || $split['other'] > 0) {
            throw new Exception('Only UPI and Cash amounts are allowed for UPI + Cash mode.');
        }
    } elseif ($mode === 'Card + UPI') {
        if ($raw['card'] === '' || $raw['upi'] === '') {
            throw new Exception('Please enter both Card and UPI split amounts.');
        }
        if ($split['card'] <= 0 || $split['upi'] <= 0) {
            throw new Exception('Card and UPI split amounts must be greater than zero.');
        }
        if ($split['cash'] > 0 || $split['other'] > 0) {
            throw new Exception('Only Card and UPI amounts are allowed for Card + UPI mode.');
        }
    }

    $sum = round($split['cash'] + $split['card'] + $split['upi'] + $split['other'], 2);
    if (abs($sum - $expected) > 0.01) {
        throw new Exception('Split amounts must exactly match the payment amount.');
    }
    if ($sum > ($expected + 0.01)) {
        throw new Exception('Split amounts cannot exceed the payment amount.');
    }

    return [
        'cash_amount' => round($split['cash'], 2),
        'card_amount' => round($split['card'], 2),
        'upi_amount' => round($split['upi'], 2),
        'other_amount' => round($split['other'], 2),
    ];
}

/**
 * Returns a display-ready payment mode string with optional split breakdown.
 *
 * @param array $row Data row containing payment_mode and split columns.
 * @param bool $with_breakdown Whether to append split details for combined payments.
 * @return string
 */
function format_payment_mode_display(array $row, bool $with_breakdown = true): string {
    $cash = round((float)($row['cash_amount'] ?? $row['split_cash_amount'] ?? 0), 2);
    $card = round((float)($row['card_amount'] ?? $row['split_card_amount'] ?? 0), 2);
    $upi = round((float)($row['upi_amount'] ?? $row['split_upi_amount'] ?? 0), 2);
    $other = round((float)($row['other_amount'] ?? $row['split_other_amount'] ?? 0), 2);

    $mode = resolve_payment_mode_from_split(
        ['cash' => $cash, 'card' => $card, 'upi' => $upi, 'other' => $other],
        (string)($row['payment_mode'] ?? '')
    );

    if (!$with_breakdown) {
        return $mode;
    }

    $parts = [];
    if ($cash > 0.0001) {
        $parts[] = 'Cash: ₹' . number_format($cash, 2);
    }
    if ($card > 0.0001) {
        $parts[] = 'Card: ₹' . number_format($card, 2);
    }
    if ($upi > 0.0001) {
        $parts[] = 'UPI: ₹' . number_format($upi, 2);
    }
    if ($other > 0.0001) {
        $parts[] = 'Other: ₹' . number_format($other, 2);
    }

    if (count($parts) < 2) {
        return $mode;
    }

    return $mode . ' (' . implode(', ', $parts) . ')';
}

/**
 * Calculates pending amount from net amount and total paid.
 *
 * @param float $net_amount Bill net amount.
 * @param float $amount_paid Total paid amount.
 * @return float Pending amount rounded to 2 decimals.
 */
function calculate_pending_amount(float $net_amount, float $amount_paid): float {
    $net = round(max(0.0, $net_amount), 2);
    $paid = round(max(0.0, $amount_paid), 2);
    return round(max($net - $paid, 0.0), 2);
}

/**
 * Derives normalized payment status using bill totals.
 *
 * Rules:
 * - Paid > 0 and Pending > 0 => Partial Paid
 * - Pending = 0 => Paid
 * - Paid = 0 and Pending > 0 => Due (or supplied pending label)
 *
 * @param float $net_amount Bill net amount.
 * @param float $amount_paid Total paid amount.
 * @param string $pending_label Label to use for zero-paid pending bills.
 * @return string Derived status label.
 */
function derive_payment_status_from_amounts(float $net_amount, float $amount_paid, string $pending_label = 'Due'): string {
    $paid = round(max(0.0, $amount_paid), 2);
    $pending = calculate_pending_amount($net_amount, $paid);

    if ($pending <= 0.01) {
        return 'Paid';
    }
    if ($paid > 0.01) {
        return 'Partial Paid';
    }
    return $pending_label;
}

/**
 * Aggregates payment-mode totals after normalizing mode labels.
 *
 * @param array $rows Source rows containing mode and total fields.
 * @param string $mode_key Key name for payment mode value.
 * @param string $value_key Key name for numeric total value.
 * @return array Associative array keyed by normalized payment mode label.
 */
function normalize_payment_mode_totals(array $rows, string $mode_key = 'payment_mode', string $value_key = 'total'): array {
    $totals = [];

    foreach ($rows as $row) {
        $mode = format_payment_mode_label($row[$mode_key] ?? '');
        $amount = (float)($row[$value_key] ?? 0);

        if (!isset($totals[$mode])) {
            $totals[$mode] = 0.0;
        }

        $totals[$mode] += $amount;
    }

    arsort($totals);
    return $totals;
}

/**
 * Ensures package-related schema exists for package management and billing.
 *
 * @param mysqli $conn The database connection object.
 * @throws Exception When schema operations fail.
 */
function ensure_package_management_schema(mysqli $conn) {
    static $is_checked = false;

    if ($is_checked) {
        return;
    }

    $create_packages_sql = "CREATE TABLE IF NOT EXISTS test_packages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        package_code VARCHAR(50) NOT NULL UNIQUE,
        package_name VARCHAR(255) NOT NULL,
        description TEXT DEFAULT NULL,
        total_base_price DECIMAL(10,2) DEFAULT 0.00,
        package_price DECIMAL(10,2) NOT NULL,
        discount_amount DECIMAL(10,2) DEFAULT 0.00,
        discount_percent DECIMAL(5,2) DEFAULT 0.00,
        status ENUM('active','inactive') DEFAULT 'active',
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_package_name (package_name),
        INDEX idx_package_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if (!$conn->query($create_packages_sql)) {
        throw new Exception('Unable to ensure test_packages table: ' . $conn->error);
    }

    $create_package_tests_sql = "CREATE TABLE IF NOT EXISTS package_tests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        package_id INT NOT NULL,
        test_id INT NOT NULL,
        base_test_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        package_test_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        display_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_package_test (package_id, test_id),
        INDEX idx_package_tests_package (package_id),
        INDEX idx_package_tests_test (test_id),
        CONSTRAINT fk_package_tests_package FOREIGN KEY (package_id) REFERENCES test_packages(id) ON DELETE CASCADE,
        CONSTRAINT fk_package_tests_test FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if (!$conn->query($create_package_tests_sql)) {
        throw new Exception('Unable to ensure package_tests table: ' . $conn->error);
    }

    $create_bill_package_items_sql = "CREATE TABLE IF NOT EXISTS bill_package_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bill_id INT NOT NULL,
        bill_item_id INT NOT NULL,
        package_id INT NOT NULL,
        test_id INT NOT NULL,
        test_name VARCHAR(255) NOT NULL,
        base_test_price DECIMAL(10,2) DEFAULT 0.00,
        package_test_price DECIMAL(10,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_bill_package_items_bill (bill_id),
        INDEX idx_bill_package_items_package (package_id),
        INDEX idx_bill_package_items_test (test_id),
        CONSTRAINT fk_bill_package_items_bill FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
        CONSTRAINT fk_bill_package_items_item FOREIGN KEY (bill_item_id) REFERENCES bill_items(id) ON DELETE CASCADE,
        CONSTRAINT fk_bill_package_items_package FOREIGN KEY (package_id) REFERENCES test_packages(id) ON DELETE CASCADE,
        CONSTRAINT fk_bill_package_items_test FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if (!$conn->query($create_bill_package_items_sql)) {
        throw new Exception('Unable to ensure bill_package_items table: ' . $conn->error);
    }

    $bill_item_columns = [
        'package_id' => "INT NULL DEFAULT NULL AFTER test_id",
        'is_package' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER package_id",
        'item_type' => "ENUM('test','package') NOT NULL DEFAULT 'test' AFTER is_package",
        'package_name' => "VARCHAR(255) NULL DEFAULT NULL AFTER item_type",
        'package_discount' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER package_name"
    ];

    foreach ($bill_item_columns as $name => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM bill_items LIKE '{$name}'");
        if ($check && $check->num_rows === 0) {
            if (!$conn->query("ALTER TABLE bill_items ADD COLUMN {$name} {$definition}")) {
                throw new Exception('Unable to add bill_items.' . $name . ': ' . $conn->error);
            }
        }
        if ($check instanceof mysqli_result) {
            $check->free();
        }
    }

    $test_id_check = $conn->query("SHOW COLUMNS FROM bill_items LIKE 'test_id'");
    if ($test_id_check && $test_id_check->num_rows > 0) {
        $test_id_column = $test_id_check->fetch_assoc();
        if (isset($test_id_column['Null']) && strtoupper((string)$test_id_column['Null']) !== 'YES') {
            if (!$conn->query("ALTER TABLE bill_items MODIFY COLUMN test_id INT NULL")) {
                throw new Exception('Unable to update bill_items.test_id nullability: ' . $conn->error);
            }
        }
    }
    if ($test_id_check instanceof mysqli_result) {
        $test_id_check->free();
    }

    $idx_package = $conn->query("SHOW INDEX FROM bill_items WHERE Key_name = 'idx_bill_items_package'");
    if ($idx_package && $idx_package->num_rows === 0) {
        if (!$conn->query("ALTER TABLE bill_items ADD INDEX idx_bill_items_package (package_id)")) {
            throw new Exception('Unable to add bill_items index idx_bill_items_package: ' . $conn->error);
        }
    }
    if ($idx_package instanceof mysqli_result) {
        $idx_package->free();
    }

    $idx_item_type = $conn->query("SHOW INDEX FROM bill_items WHERE Key_name = 'idx_bill_items_item_type'");
    if ($idx_item_type && $idx_item_type->num_rows === 0) {
        if (!$conn->query("ALTER TABLE bill_items ADD INDEX idx_bill_items_item_type (item_type)")) {
            throw new Exception('Unable to add bill_items index idx_bill_items_item_type: ' . $conn->error);
        }
    }
    if ($idx_item_type instanceof mysqli_result) {
        $idx_item_type->free();
    }

    $fk_package = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bill_items' AND COLUMN_NAME = 'package_id' AND REFERENCED_TABLE_NAME = 'test_packages' LIMIT 1");
    $has_package_fk = $fk_package && $fk_package->num_rows > 0;
    if ($fk_package instanceof mysqli_result) {
        $fk_package->free();
    }
    if (!$has_package_fk) {
        if (!$conn->query("ALTER TABLE bill_items ADD CONSTRAINT fk_bill_items_package FOREIGN KEY (package_id) REFERENCES test_packages(id) ON DELETE SET NULL")) {
            throw new Exception('Unable to add fk_bill_items_package: ' . $conn->error);
        }
    }

    $is_checked = true;
}

/**
 * Builds a package code prefix for auto-generated package codes.
 *
 * @param string $package_name Package name.
 * @return string
 */
function package_code_prefix(string $package_name): string {
    $letters = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($package_name));
    if ($letters === '') {
        return 'PKG';
    }
    return substr($letters, 0, 4);
}

/**
 * Generates a unique package code.
 *
 * @param mysqli $conn Database connection.
 * @param string $package_name Package name used for prefix fallback.
 * @return string
 * @throws Exception When code generation fails repeatedly.
 */
function generate_package_code(mysqli $conn, string $package_name = 'PACKAGE'): string {
    ensure_package_management_schema($conn);

    $prefix = package_code_prefix($package_name);
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = $prefix . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare("SELECT id FROM test_packages WHERE package_code = ? LIMIT 1");
        if (!$stmt) {
            break;
        }
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return $candidate;
        }
    }

    throw new Exception('Unable to generate a unique package code. Please enter a manual code.');
}

/**
 * Fetches active packages with aggregated metadata.
 *
 * @param mysqli $conn Database connection.
 * @return array
 */
function fetch_active_packages(mysqli $conn): array {
    ensure_package_management_schema($conn);

    $packages = [];
    $sql = "SELECT tp.id, tp.package_code, tp.package_name, tp.description, tp.total_base_price, tp.package_price,
                   tp.discount_amount, tp.discount_percent, tp.status, COUNT(pt.id) AS test_count
            FROM test_packages tp
            LEFT JOIN package_tests pt ON pt.package_id = tp.id
            WHERE tp.status = 'active'
            GROUP BY tp.id
            ORDER BY tp.package_name";

    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $packages[] = $row;
        }
        $result->free();
    }

    return $packages;
}

/**
 * Fetches package details with included tests.
 *
 * @param mysqli $conn Database connection.
 * @param int $package_id Package id.
 * @param bool $active_only Restrict to active packages.
 * @return array|null
 */
function fetch_package_details(mysqli $conn, int $package_id, bool $active_only = false) {
    ensure_package_management_schema($conn);

    if ($package_id <= 0) {
        return null;
    }

    $sql = "SELECT id, package_code, package_name, description, total_base_price, package_price,
                   discount_amount, discount_percent, status
            FROM test_packages
            WHERE id = ?";
    if ($active_only) {
        $sql .= " AND status = 'active'";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $package_id);
    $stmt->execute();
    $package = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$package) {
        return null;
    }

    $package['tests'] = [];
    $tests_sql = "SELECT pt.test_id,
                         t.main_test_name,
                         t.sub_test_name,
                         pt.base_test_price,
                         pt.package_test_price,
                         pt.display_order
                  FROM package_tests pt
                  JOIN tests t ON t.id = pt.test_id
                  WHERE pt.package_id = ?
                  ORDER BY pt.display_order ASC, pt.id ASC";
    $tests_stmt = $conn->prepare($tests_sql);
    if (!$tests_stmt) {
        return $package;
    }
    $tests_stmt->bind_param('i', $package_id);
    $tests_stmt->execute();
    $tests_result = $tests_stmt->get_result();
    while ($row = $tests_result->fetch_assoc()) {
        $package['tests'][] = $row;
    }
    $tests_stmt->close();

    return $package;
}

/**
 * Renders unified compact pagination markup with ellipsis.
 *
 * @param string $basePath Relative target path like 'dashboard.php'.
 * @param int $currentPage Current active page (1-based).
 * @param int $totalPages Total available pages.
 * @param array $queryParams Existing query parameters; page is replaced internally.
 * @param string $ariaLabel Accessible nav label.
 * @return string HTML navigation markup.
 */
function render_unified_pagination(
    string $basePath,
    int $currentPage,
    int $totalPages,
    array $queryParams = [],
    string $ariaLabel = 'Pagination'
): string {
    if ($totalPages <= 1) {
        return '';
    }

    $currentPage = max(1, min($currentPage, $totalPages));

    $buildLink = function (int $targetPage) use ($basePath, $queryParams): string {
        $params = $queryParams;
        $params['page'] = $targetPage;
        return $basePath . '?' . http_build_query($params);
    };

    $window = 2;
    $items = [1];
    $startWindow = max(2, $currentPage - $window);
    $endWindow = min($totalPages - 1, $currentPage + $window);

    if ($startWindow > 2) {
        $items[] = 'ellipsis';
    }

    for ($i = $startWindow; $i <= $endWindow; $i++) {
        $items[] = $i;
    }

    if ($endWindow < $totalPages - 1) {
        $items[] = 'ellipsis';
    }

    $items[] = $totalPages;

    $html = '<nav class="unified-pagination" aria-label="' . htmlspecialchars($ariaLabel) . '">';
    $html .= '<a href="' . (($currentPage > 1) ? htmlspecialchars($buildLink($currentPage - 1)) : '#') . '" class="page-item page-prev ' . (($currentPage <= 1) ? 'is-disabled' : '') . '" ' . (($currentPage <= 1) ? 'aria-disabled="true" tabindex="-1"' : '') . '>Previous</a>';

    foreach ($items as $item) {
        if ($item === 'ellipsis') {
            $html .= '<span class="page-item page-ellipsis" aria-hidden="true">...</span>';
            continue;
        }

        $isActive = ((int)$item === $currentPage);
        $html .= '<a href="' . htmlspecialchars($buildLink((int)$item)) . '" class="page-item page-number ' . ($isActive ? 'is-active' : '') . '" ' . ($isActive ? 'aria-current="page"' : '') . '>' . (int)$item . '</a>';
    }

    $html .= '<a href="' . (($currentPage < $totalPages) ? htmlspecialchars($buildLink($currentPage + 1)) : '#') . '" class="page-item page-next ' . (($currentPage >= $totalPages) ? 'is-disabled' : '') . '" ' . (($currentPage >= $totalPages) ? 'aria-disabled="true" tabindex="-1"' : '') . '>Next</a>';
    $html .= '</nav>';

    return $html;
}
?>