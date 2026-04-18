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
 * Ensures bill edit request workflow schema supports lifecycle, remarks, and timeline events.
 *
 * @param mysqli $conn The database connection object.
 * @throws Exception When schema operations fail.
 */
function ensure_bill_edit_request_workflow_schema(mysqli $conn): void {
    static $is_checked = false;

    if ($is_checked) {
        return;
    }

    $create_requests_sql = "CREATE TABLE IF NOT EXISTS bill_edit_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bill_id INT NOT NULL,
        receptionist_id INT NOT NULL,
        reason_for_change TEXT NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        manager_comment TEXT NULL,
        receptionist_response TEXT NULL,
        manager_unread TINYINT(1) NOT NULL DEFAULT 1,
        receptionist_unread TINYINT(1) NOT NULL DEFAULT 0,
        last_manager_action_at DATETIME NULL,
        last_receptionist_action_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_ber_status (status),
        KEY idx_ber_receptionist_status (receptionist_id, status),
        KEY idx_ber_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if (!$conn->query($create_requests_sql)) {
        throw new Exception('Unable to ensure bill_edit_requests table: ' . $conn->error);
    }

    $added_manager_unread = false;
    $added_receptionist_unread = false;
    $added_updated_at = false;

    $request_columns = [
        'manager_comment' => "TEXT NULL AFTER reason_for_change",
        'receptionist_response' => "TEXT NULL AFTER manager_comment",
        'manager_unread' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER receptionist_response",
        'receptionist_unread' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER manager_unread",
        'last_manager_action_at' => "DATETIME NULL AFTER receptionist_unread",
        'last_receptionist_action_at' => "DATETIME NULL AFTER last_manager_action_at",
        'updated_at' => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($request_columns as $column_name => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM bill_edit_requests LIKE '{$column_name}'");
        if ($check && $check->num_rows === 0) {
            if (!$conn->query("ALTER TABLE bill_edit_requests ADD COLUMN {$column_name} {$definition}")) {
                throw new Exception('Unable to add bill_edit_requests.' . $column_name . ': ' . $conn->error);
            }

            if ($column_name === 'manager_unread') {
                $added_manager_unread = true;
            }
            if ($column_name === 'receptionist_unread') {
                $added_receptionist_unread = true;
            }
            if ($column_name === 'updated_at') {
                $added_updated_at = true;
            }
        }
        if ($check instanceof mysqli_result) {
            $check->free();
        }
    }

    if ($added_updated_at) {
        $conn->query("UPDATE bill_edit_requests SET updated_at = created_at WHERE updated_at IS NULL");
    }

    if ($added_manager_unread) {
        $conn->query("UPDATE bill_edit_requests
                      SET manager_unread = CASE WHEN LOWER(REPLACE(TRIM(status), ' ', '_')) = 'pending' THEN 1 ELSE 0 END");
    }

    if ($added_receptionist_unread) {
        $conn->query("UPDATE bill_edit_requests
                      SET receptionist_unread = CASE
                        WHEN LOWER(REPLACE(TRIM(status), ' ', '_')) IN ('approved', 'rejected', 'query_raised', 'completed') THEN 1
                        ELSE 0
                      END");
    }

    $conn->query("UPDATE bill_edit_requests
                  SET status = LOWER(REPLACE(REPLACE(TRIM(status), '-', '_'), ' ', '_'))");

    $conn->query("UPDATE bill_edit_requests
                  SET status = CASE
                    WHEN status IN ('pending', 'open', 'new') THEN 'pending'
                    WHEN status IN ('query', 'queryraised', 'query_raised') THEN 'query_raised'
                    WHEN status IN ('approved') THEN 'approved'
                    WHEN status IN ('rejected', 'declined') THEN 'rejected'
                    WHEN status IN ('completed', 'done', 'closed') THEN 'completed'
                    ELSE 'pending'
                  END");

    $create_events_sql = "CREATE TABLE IF NOT EXISTS bill_edit_request_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        actor_role VARCHAR(20) NOT NULL,
        actor_user_id INT NULL,
        action_type VARCHAR(50) NOT NULL,
        old_status VARCHAR(30) NULL,
        new_status VARCHAR(30) NULL,
        comment_text TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ber_events_request (request_id),
        KEY idx_ber_events_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if (!$conn->query($create_events_sql)) {
        throw new Exception('Unable to ensure bill_edit_request_events table: ' . $conn->error);
    }

    $seed_events_sql = "INSERT INTO bill_edit_request_events
        (request_id, actor_role, actor_user_id, action_type, old_status, new_status, comment_text, created_at)
        SELECT r.id, 'system', NULL, 'request_seeded', NULL, r.status, r.reason_for_change, r.created_at
        FROM bill_edit_requests r
        WHERE NOT EXISTS (
            SELECT 1 FROM bill_edit_request_events e WHERE e.request_id = r.id
        )";
    $conn->query($seed_events_sql);

    $is_checked = true;
}

/**
 * Normalizes bill edit request status to canonical workflow states.
 *
 * @param mixed $status Raw status value.
 * @return string Canonical status key.
 */
function normalize_bill_edit_request_status($status): string {
    $raw = strtolower(trim((string)$status));
    if ($raw === '') {
        return 'pending';
    }

    $token = str_replace(['-', ' '], '_', $raw);
    $compact = str_replace('_', '', $token);

    if (in_array($token, ['pending', 'open', 'new'], true) || in_array($compact, ['pending', 'open', 'new'], true)) {
        return 'pending';
    }
    if (in_array($token, ['query', 'query_raised', 'queryraised'], true) || in_array($compact, ['query', 'queryraised'], true)) {
        return 'query_raised';
    }
    if (in_array($token, ['approved'], true) || in_array($compact, ['approved'], true)) {
        return 'approved';
    }
    if (in_array($token, ['rejected', 'declined'], true) || in_array($compact, ['rejected', 'declined'], true)) {
        return 'rejected';
    }
    if (in_array($token, ['completed', 'done', 'closed'], true) || in_array($compact, ['completed', 'done', 'closed'], true)) {
        return 'completed';
    }

    return 'pending';
}

/**
 * Returns a human-readable request status label.
 *
 * @param mixed $status Raw status value.
 * @return string Display label.
 */
function get_bill_edit_request_status_label($status): string {
    $normalized = normalize_bill_edit_request_status($status);
    $labels = [
        'pending' => 'Pending',
        'query_raised' => 'Query Raised',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'completed' => 'Completed',
    ];

    return $labels[$normalized] ?? 'Pending';
}

/**
 * Returns CSS class token for request status pills.
 *
 * @param mixed $status Raw status value.
 * @return string CSS class name.
 */
function get_bill_edit_request_status_class($status): string {
    $normalized = normalize_bill_edit_request_status($status);
    return 'status-' . str_replace('_', '-', $normalized);
}

/**
 * Appends an event entry to bill edit request timeline.
 *
 * @param mysqli $conn The database connection object.
 * @param int $request_id Request id.
 * @param string $actor_role manager|receptionist|system.
 * @param int|null $actor_user_id User id if available.
 * @param string $action_type Semantic action key.
 * @param string|null $old_status Previous status key.
 * @param string|null $new_status New status key.
 * @param string $comment Optional comment text.
 */
function log_bill_edit_request_event(
    mysqli $conn,
    int $request_id,
    string $actor_role,
    $actor_user_id,
    string $action_type,
    $old_status = null,
    $new_status = null,
    string $comment = ''
): void {
    ensure_bill_edit_request_workflow_schema($conn);

    if ($request_id <= 0) {
        return;
    }

    $actor = strtolower(trim($actor_role));
    if (!in_array($actor, ['manager', 'receptionist', 'system'], true)) {
        $actor = 'system';
    }

    $action = trim($action_type);
    if ($action === '') {
        $action = 'status_updated';
    }

    $old = $old_status === null ? null : normalize_bill_edit_request_status($old_status);
    $new = $new_status === null ? null : normalize_bill_edit_request_status($new_status);
    $note = trim($comment);
    if ($note === '') {
        $note = null;
    }

    if ($actor_user_id === null) {
        $stmt = $conn->prepare("INSERT INTO bill_edit_request_events
            (request_id, actor_role, actor_user_id, action_type, old_status, new_status, comment_text, created_at)
            VALUES (?, ?, NULL, ?, ?, ?, ?, NOW())");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isssss', $request_id, $actor, $action, $old, $new, $note);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $actor_id = (int)$actor_user_id;
    $stmt = $conn->prepare("INSERT INTO bill_edit_request_events
        (request_id, actor_role, actor_user_id, action_type, old_status, new_status, comment_text, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('isissss', $request_id, $actor, $actor_id, $action, $old, $new, $note);
    $stmt->execute();
    $stmt->close();
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
 * Ensures reporting doctors management table exists.
 */
function ensure_reporting_doctors_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS reporting_doctors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doctor_name VARCHAR(150) NOT NULL,
        phone_number VARCHAR(30) NOT NULL,
        email VARCHAR(150) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        city VARCHAR(120) DEFAULT NULL,
        hospital_name VARCHAR(180) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_reporting_doctor_name (doctor_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Returns reporting radiologist list used across manager and writer pages.
 * Uses reporting_doctors table when available; falls back to legacy defaults.
 *
 * @return array<int, string>
 */
function get_reporting_radiologist_list(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $defaults = [
        'Dr. G. Mamatha MD (RD)',
        'Dr. G. Sri Kanth DMRD',
        'Dr. P. Madhu Babu MD',
        'Dr. Sahithi Chowdary',
        'Dr. SVN. Vamsi Krishna MD(RD)',
        'Dr. T. Koushik MD(RD)',
        'Dr. T. Rajeshwar Rao MD DMRD',
    ];

    $cached = $defaults;

    if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
        return $cached;
    }

    $conn = $GLOBALS['conn'];
    ensure_reporting_doctors_schema($conn);

    $list = [];
    $result = $conn->query("SELECT doctor_name FROM reporting_doctors WHERE is_active = 1 ORDER BY doctor_name ASC");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $name = trim((string)($row['doctor_name'] ?? ''));
            if ($name !== '') {
                $list[] = $name;
            }
        }
        $result->free();
    }

    if (!empty($list)) {
        $cached = array_values(array_unique($list));
        return $cached;
    }

    // Seed defaults for first-time setup to preserve existing behavior.
    $insert = $conn->prepare("INSERT IGNORE INTO reporting_doctors (doctor_name, phone_number, is_active) VALUES (?, 'NA', 1)");
    if ($insert) {
        foreach ($defaults as $default_name) {
            $insert->bind_param('s', $default_name);
            $insert->execute();
        }
        $insert->close();
    }

    $cached = $defaults;
    return $cached;
}

/**
 * Detects patient identifier columns available in current schema.
 *
 * @param mysqli $conn The database connection object.
 * @return array{uid: bool, registration_id: bool}
 */
function get_patient_identifier_columns(mysqli $conn): array {
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $cached = [
        'uid' => false,
        'registration_id' => false,
    ];

    $result = $conn->query("SHOW COLUMNS FROM patients");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $field = isset($row['Field']) ? (string)$row['Field'] : '';
            if ($field === 'uid' || $field === 'registration_id') {
                $cached[$field] = true;
            }
        }
        $result->free();
    }

    return $cached;
}

/**
 * Resolves the writable patient identifier column name.
 *
 * @param mysqli $conn The database connection object.
 * @return string
 * @throws Exception When neither uid nor registration_id exists.
 */
function get_patient_identifier_insert_column(mysqli $conn): string {
    $columns = get_patient_identifier_columns($conn);
    if ($columns['uid']) {
        return 'uid';
    }
    if ($columns['registration_id']) {
        return 'registration_id';
    }

    throw new Exception('Patients table is missing identifier column (uid/registration_id).');
}

/**
 * Builds a SQL expression for patient identifier lookup in SELECT/WHERE queries.
 *
 * @param mysqli $conn The database connection object.
 * @param string $tableAlias SQL alias for patients table.
 * @return string
 */
function get_patient_identifier_expression(mysqli $conn, string $tableAlias = 'p'): string {
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $tableAlias);
    if ($alias === '') {
        $alias = 'p';
    }

    $columns = get_patient_identifier_columns($conn);
    if ($columns['uid'] && $columns['registration_id']) {
        return "COALESCE(NULLIF({$alias}.uid, ''), NULLIF({$alias}.registration_id, ''))";
    }
    if ($columns['uid']) {
        return "{$alias}.uid";
    }
    if ($columns['registration_id']) {
        return "{$alias}.registration_id";
    }

    return "CAST({$alias}.id AS CHAR)";
}

/**
 * Generates the next patient identifier using whichever identifier column exists.
 *
 * @param mysqli $conn The database connection object.
 * @return string
 * @throws Exception On query errors.
 */
function generate_next_patient_identifier(mysqli $conn): string {
    $column = get_patient_identifier_insert_column($conn);
    $year = date('Y');
    $prefix = 'DC' . $year;

    $sql = "SELECT `{$column}` AS patient_code FROM patients WHERE `{$column}` LIKE CONCAT(?, '%') ORDER BY `{$column}` DESC LIMIT 1 FOR UPDATE";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Unable to prepare patient identifier query: ' . $conn->error);
    }

    $stmt->bind_param('s', $prefix);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception('Unable to fetch latest patient identifier: ' . $err);
    }

    $result = $stmt->get_result();
    $latest = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $next_number = 1;
    $latestCode = ($latest && isset($latest['patient_code'])) ? (string)$latest['patient_code'] : '';
    if ($latestCode !== '' && strpos($latestCode, $prefix) === 0) {
        $suffix = substr($latestCode, strlen($prefix));
        if ($suffix !== '' && ctype_digit($suffix)) {
            $next_number = (int)$suffix + 1;
        }
    }

    return $prefix . str_pad((string)$next_number, 4, '0', STR_PAD_LEFT);
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
 * Normalizes payment status labels to canonical values used by the app.
 *
 * @param mixed $payment_status Raw status value.
 * @param string $fallback Fallback canonical status.
 * @return string Canonical status label.
 */
function normalize_payment_status_label($payment_status, string $fallback = 'Due'): string {
    $allowed = ['Paid', 'Due', 'Partial Paid'];
    if (!in_array($fallback, $allowed, true)) {
        $fallback = 'Due';
    }

    $raw = trim((string)$payment_status);
    if ($raw === '') {
        return $fallback;
    }

    $normalized = strtolower((string)preg_replace('/[\s_\-]+/', '', $raw));
    $map = [
        'paid' => 'Paid',
        'fullpaid' => 'Paid',
        'due' => 'Due',
        'pending' => 'Due',
        'partialpaid' => 'Partial Paid',
        'partiallypaid' => 'Partial Paid',
        'halfpaid' => 'Partial Paid',
    ];

    if (isset($map[$normalized])) {
        return $map[$normalized];
    }

    return $fallback;
}

/**
 * Ensures bills.payment_status supports Partial Paid and migrates legacy Half Paid rows.
 *
 * @param mysqli $conn The database connection object.
 * @throws Exception When schema operations fail.
 */
function ensure_bill_payment_status_schema(mysqli $conn): void {
    $status_col_check = $conn->query("SHOW COLUMNS FROM bills LIKE 'payment_status'");
    if (!$status_col_check || $status_col_check->num_rows === 0) {
        if ($status_col_check instanceof mysqli_result) {
            $status_col_check->free();
        }
        return;
    }

    $status_col = $status_col_check->fetch_assoc();
    if ($status_col_check instanceof mysqli_result) {
        $status_col_check->free();
    }

    $column_type = (string)($status_col['Type'] ?? '');
    $column_type_lc = strtolower($column_type);

    if (strpos($column_type_lc, 'enum(') === 0) {
        $enum_matches = [];
        preg_match_all("/'((?:\\\\'|[^'])*)'/", $column_type, $enum_matches);
        $enum_values = [];
        foreach (($enum_matches[1] ?? []) as $enum_raw) {
            $enum_values[] = str_replace("\\'", "'", (string)$enum_raw);
        }

        if (!in_array('Partial Paid', $enum_values, true)) {
            if (!in_array('Paid', $enum_values, true)) {
                $enum_values[] = 'Paid';
            }
            if (!in_array('Due', $enum_values, true)) {
                $enum_values[] = 'Due';
            }
            $enum_values[] = 'Partial Paid';

            $escaped_values = array_map(static function (string $value): string {
                return "'" . str_replace("'", "''", $value) . "'";
            }, array_values(array_unique($enum_values)));

            $enum_sql = implode(',', $escaped_values);
            if (!$conn->query("ALTER TABLE bills MODIFY COLUMN payment_status ENUM({$enum_sql}) NOT NULL DEFAULT 'Due'")) {
                throw new Exception('Unable to update bills.payment_status enum values: ' . $conn->error);
            }
        }
    } elseif (preg_match('/^varchar\((\d+)\)$/i', $column_type, $matches)) {
        $length = (int)$matches[1];
        if ($length < 20) {
            if (!$conn->query("ALTER TABLE bills MODIFY COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'Due'")) {
                throw new Exception('Unable to expand bills.payment_status length: ' . $conn->error);
            }
        }
    }

    if (!$conn->query("UPDATE bills SET payment_status = 'Partial Paid' WHERE payment_status = 'Half Paid'")) {
        throw new Exception('Unable to migrate legacy payment_status values: ' . $conn->error);
    }
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

    ensure_bill_payment_status_schema($conn);

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
 * Converts a currency amount to integer paise to avoid float drift.
 *
 * @param mixed $amount Input amount.
 * @return int Amount in paise.
 */
function currency_to_paise($amount): int {
    if (is_string($amount)) {
        $amount = trim($amount);
        if ($amount === '') {
            return 0;
        }
    }

    if (!is_numeric($amount)) {
        return 0;
    }

    return (int)round((((float)$amount) + 0.0000001) * 100);
}

/**
 * Converts integer paise back to two-decimal currency amount.
 *
 * @param int $paise Amount in paise.
 * @return float Currency amount.
 */
function paise_to_currency(int $paise): float {
    return round($paise / 100, 2);
}

/**
 * Normalizes any money value to two decimal places.
 *
 * @param mixed $amount Input amount.
 * @return float Normalized amount.
 */
function normalize_currency_amount($amount): float {
    return paise_to_currency(currency_to_paise($amount));
}

/**
 * Snaps near-whole paise anomalies (..01 / ..99) to whole rupees when preferred.
 *
 * This guards against awkward artifacts that typically come from floating
 * imprecision or accidental spinner changes in whole-rupee billing flows.
 *
 * @param int $amount_paise Amount in paise.
 * @param bool $prefer_whole Whether whole-rupee snapping should be applied.
 * @return int Normalized amount in paise.
 */
function snap_near_whole_paise(int $amount_paise, bool $prefer_whole = true): int {
    if (!$prefer_whole || $amount_paise <= 0) {
        return $amount_paise;
    }

    $paise_part = $amount_paise % 100;
    if ($paise_part < 0) {
        $paise_part += 100;
    }

    if ($paise_part === 1 || $paise_part === 99) {
        return (int)round($amount_paise / 100) * 100;
    }

    return $amount_paise;
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
    $expected_paise = max(0, currency_to_paise($expected_amount));

    $fields = [
        'cash' => 'split_cash_amount',
        'card' => 'split_card_amount',
        'upi' => 'split_upi_amount',
        'other' => 'split_other_amount',
    ];

    $raw = [];
    $split_paise = ['cash' => 0, 'card' => 0, 'upi' => 0, 'other' => 0];

    foreach ($fields as $key => $field_name) {
        $raw_val = isset($source[$field_name]) ? trim((string)$source[$field_name]) : '';
        $raw[$key] = $raw_val;

        if ($raw_val === '') {
            continue;
        }
        if (!is_numeric($raw_val)) {
            throw new Exception('Please enter valid numeric split amounts.');
        }

        $amount_rupees = (int)round((float)$raw_val, 0);
        $amount_paise = $amount_rupees * 100;
        if ($amount_paise < 0) {
            throw new Exception('Split amounts cannot be negative.');
        }

        $split_paise[$key] = $amount_paise;
    }

    if ($expected_paise <= 0) {
        return [
            'cash_amount' => 0.0,
            'card_amount' => 0.0,
            'upi_amount' => 0.0,
            'other_amount' => 0.0,
        ];
    }

    if ($mode === 'Cash') {
        $split_paise = ['cash' => $expected_paise, 'card' => 0, 'upi' => 0, 'other' => 0];
    } elseif ($mode === 'Card') {
        $split_paise = ['cash' => 0, 'card' => $expected_paise, 'upi' => 0, 'other' => 0];
    } elseif ($mode === 'UPI') {
        $split_paise = ['cash' => 0, 'card' => 0, 'upi' => $expected_paise, 'other' => 0];
    } elseif ($mode === 'Cash + Card') {
        if ($raw['cash'] === '' || $raw['card'] === '') {
            throw new Exception('Please enter both Cash and Card split amounts.');
        }
        if ($split_paise['cash'] <= 0 || $split_paise['card'] <= 0) {
            throw new Exception('Cash and Card split amounts must be greater than zero.');
        }
        if ($split_paise['upi'] > 0 || $split_paise['other'] > 0) {
            throw new Exception('Only Cash and Card amounts are allowed for Cash + Card mode.');
        }
    } elseif ($mode === 'UPI + Cash') {
        if ($raw['upi'] === '' || $raw['cash'] === '') {
            throw new Exception('Please enter both UPI and Cash split amounts.');
        }
        if ($split_paise['upi'] <= 0 || $split_paise['cash'] <= 0) {
            throw new Exception('UPI and Cash split amounts must be greater than zero.');
        }
        if ($split_paise['card'] > 0 || $split_paise['other'] > 0) {
            throw new Exception('Only UPI and Cash amounts are allowed for UPI + Cash mode.');
        }
    } elseif ($mode === 'Card + UPI') {
        if ($raw['card'] === '' || $raw['upi'] === '') {
            throw new Exception('Please enter both Card and UPI split amounts.');
        }
        if ($split_paise['card'] <= 0 || $split_paise['upi'] <= 0) {
            throw new Exception('Card and UPI split amounts must be greater than zero.');
        }
        if ($split_paise['cash'] > 0 || $split_paise['other'] > 0) {
            throw new Exception('Only Card and UPI amounts are allowed for Card + UPI mode.');
        }
    }

    $sum_paise = $split_paise['cash'] + $split_paise['card'] + $split_paise['upi'] + $split_paise['other'];
    if ($sum_paise !== $expected_paise) {
        throw new Exception('Split amounts must exactly match the payment amount.');
    }
    if ($sum_paise > $expected_paise) {
        throw new Exception('Split amounts cannot exceed the payment amount.');
    }

    return [
        'cash_amount' => paise_to_currency($split_paise['cash']),
        'card_amount' => paise_to_currency($split_paise['card']),
        'upi_amount' => paise_to_currency($split_paise['upi']),
        'other_amount' => paise_to_currency($split_paise['other']),
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
    $net_paise = max(0, currency_to_paise($net_amount));
    $paid_paise = max(0, currency_to_paise($amount_paid));
    return paise_to_currency(max($net_paise - $paid_paise, 0));
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
    $paid_paise = max(0, currency_to_paise($amount_paid));
    $pending_paise = currency_to_paise(calculate_pending_amount($net_amount, $amount_paid));

    if ($pending_paise <= 0) {
        return 'Paid';
    }
    if ($paid_paise > 0) {
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
        test_id INT NULL,
        custom_test_name VARCHAR(255) NULL DEFAULT NULL,
        is_custom TINYINT(1) NOT NULL DEFAULT 0,
        test_category VARCHAR(255) NULL DEFAULT NULL,
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
        test_id INT NULL,
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

    $package_tests_test_id_check = $conn->query("SHOW COLUMNS FROM package_tests LIKE 'test_id'");
    if ($package_tests_test_id_check && $package_tests_test_id_check->num_rows > 0) {
        $test_id_col = $package_tests_test_id_check->fetch_assoc();
        if (isset($test_id_col['Null']) && strtoupper((string)$test_id_col['Null']) !== 'YES') {
            if (!$conn->query("ALTER TABLE package_tests MODIFY COLUMN test_id INT NULL")) {
                throw new Exception('Unable to update package_tests.test_id nullability: ' . $conn->error);
            }
        }
    }
    if ($package_tests_test_id_check instanceof mysqli_result) {
        $package_tests_test_id_check->free();
    }

    $package_tests_columns = [
        'custom_test_name' => "VARCHAR(255) NULL DEFAULT NULL AFTER test_id",
        'is_custom' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER custom_test_name",
        'test_category' => "VARCHAR(255) NULL DEFAULT NULL AFTER is_custom",
    ];

    foreach ($package_tests_columns as $column_name => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM package_tests LIKE '{$column_name}'");
        if ($check && $check->num_rows === 0) {
            if (!$conn->query("ALTER TABLE package_tests ADD COLUMN {$column_name} {$definition}")) {
                throw new Exception('Unable to add package_tests.' . $column_name . ': ' . $conn->error);
            }
        }
        if ($check instanceof mysqli_result) {
            $check->free();
        }
    }

    $bill_package_test_id_check = $conn->query("SHOW COLUMNS FROM bill_package_items LIKE 'test_id'");
    if ($bill_package_test_id_check && $bill_package_test_id_check->num_rows > 0) {
        $test_id_col = $bill_package_test_id_check->fetch_assoc();
        if (isset($test_id_col['Null']) && strtoupper((string)$test_id_col['Null']) !== 'YES') {
            if (!$conn->query("ALTER TABLE bill_package_items MODIFY COLUMN test_id INT NULL")) {
                throw new Exception('Unable to update bill_package_items.test_id nullability: ' . $conn->error);
            }
        }
    }
    if ($bill_package_test_id_check instanceof mysqli_result) {
        $bill_package_test_id_check->free();
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
                    pt.custom_test_name,
                    pt.is_custom,
                    pt.test_category,
                    t.main_test_name,
                    t.sub_test_name,
                         pt.base_test_price,
                         pt.package_test_price,
                         pt.display_order
                  FROM package_tests pt
                LEFT JOIN tests t ON t.id = pt.test_id
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