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
 * Validates that app_settings schema exists from SQL init files.
 *
 * @param mysqli $conn The database connection object.
 * @throws Exception When app_settings table is missing.
 */
function app_settings_ensure_schema(mysqli $conn) {
    $check = $conn->query("SHOW TABLES LIKE 'app_settings'");
    if (!$check || $check->num_rows === 0) {
        if ($check instanceof mysqli_result) {
            $check->free();
        }
        throw new Exception('Missing app_settings table. Run SQL init bundle (001-main-schema.sql -> 500-data-flow-tunnel.sql -> 900-post-schema.sql).');
    }

    if ($check instanceof mysqli_result) {
        $check->free();
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
?>