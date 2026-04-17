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
 * Returns whether a base table exists in current schema.
 *
 * @param mysqli $conn Database connection.
 * @param string $table_name Table name.
 * @return bool
 */
function schema_has_table(mysqli $conn, string $table_name): bool {
    $table = trim($table_name);
    if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    $stmt = $conn->prepare("SELECT 1
                            FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME = ?
                            LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && (bool)$res->fetch_row();
    $stmt->close();

    return $exists;
}

/**
 * Returns whether a column exists in a table in current schema.
 *
 * @param mysqli $conn Database connection.
 * @param string $table_name Table name.
 * @param string $column_name Column name.
 * @return bool
 */
function schema_has_column(mysqli $conn, string $table_name, string $column_name): bool {
    $table = trim($table_name);
    $column = trim($column_name);
    if ($table === '' || $column === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }

    $stmt = $conn->prepare("SELECT 1
                            FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME = ?
                              AND COLUMN_NAME = ?
                            LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && (bool)$res->fetch_row();
    $stmt->close();

    return $exists;
}

/**
 * Returns metadata for a single column in current schema.
 *
 * @param mysqli $conn Database connection.
 * @param string $table_name Table name.
 * @param string $column_name Column name.
 * @return array<string, mixed>|null
 */
function schema_get_column_metadata(mysqli $conn, string $table_name, string $column_name): ?array {
    $table = trim($table_name);
    $column = trim($column_name);
    if ($table === '' || $column === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return null;
    }

    $stmt = $conn->prepare("SELECT COLUMN_NAME,
                                   COLUMN_TYPE,
                                   IS_NULLABLE,
                                   COLUMN_DEFAULT,
                                   EXTRA,
                                   DATA_TYPE,
                                   COLLATION_NAME
                            FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME = ?
                              AND COLUMN_NAME = ?
                            LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return is_array($row) ? $row : null;
}

/**
 * Lists column names for a table in current schema.
 *
 * @param mysqli $conn Database connection.
 * @param string $table_name Table name.
 * @return array<int, string>
 */
function schema_list_columns(mysqli $conn, string $table_name): array {
    $columns = [];
    $table = trim($table_name);
    if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return $columns;
    }

    $stmt = $conn->prepare("SELECT COLUMN_NAME
                            FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME = ?
                            ORDER BY ORDINAL_POSITION");
    if (!$stmt) {
        return $columns;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $col = (string)($row['COLUMN_NAME'] ?? '');
        if ($col !== '') {
            $columns[] = $col;
        }
    }
    $stmt->close();

    return $columns;
}

/**
 * Validates that app_settings schema exists from SQL init files.
 *
 * @param mysqli $conn The database connection object.
 * @throws Exception When app_settings table is missing.
 */
function app_settings_ensure_schema(mysqli $conn) {
    if (!schema_has_table($conn, 'app_settings')) {
        throw new Exception('Missing app_settings table. Run SQL init bundle (001-main-schema.sql -> 500-data-flow-tunnel.sql -> 900-post-schema.sql).');
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
 * Returns the fixed reporting radiologist list used across manager and writer pages.
 *
 * @return array<int, string>
 */
function get_reporting_radiologist_list(): array {
    return [
        'Dr. G. Mamatha MD (RD)',
        'Dr. G. Sri Kanth DMRD',
        'Dr. P. Madhu Babu MD',
        'Dr. Sahithi Chowdary',
        'Dr. SVN. Vamsi Krishna MD(RD)',
        'Dr. T. Koushik MD(RD)',
        'Dr. T. Rajeshwar Rao MD DMRD',
    ];
}

/**
 * Returns project root path used by filesystem storage helpers.
 *
 * @return string
 */
function data_storage_project_root_path(): string {
    static $root_path = null;

    if ($root_path !== null) {
        return $root_path;
    }

    $root_path = dirname(__DIR__);
    return $root_path;
}

/**
 * Converts arbitrary labels into a filesystem-safe folder segment.
 *
 * @param string|null $value Raw segment value.
 * @param string $fallback Fallback value if segment becomes empty.
 * @return string
 */
function data_storage_safe_segment($value, string $fallback = 'unknown'): string {
    $segment = trim((string)$value);
    if ($segment === '') {
        return $fallback;
    }

    $segment = str_replace(['\\', '/'], '-', $segment);
    $segment = preg_replace('/[^A-Za-z0-9. _-]/', '', $segment);
    $segment = preg_replace('/\s+/', '_', (string)$segment);
    $segment = trim((string)$segment, ' ._-');

    if ($segment === '') {
        return $fallback;
    }

    return $segment;
}

/**
 * Ensures and returns one path inside project data storage.
 *
 * @param array<int, string> $segments Folder segments under data/.
 * @return array{relative_path: string, storage_path: string, absolute_path: string}
 */
function data_storage_prepare_path(array $segments): array {
    $clean_segments = [];
    foreach ($segments as $index => $segment) {
        $fallback = 'segment_' . ($index + 1);
        $clean_segments[] = data_storage_safe_segment($segment, $fallback);
    }

    $relative_parts = ['data'];
    $root_path = data_storage_project_root_path();
    $absolute_current = $root_path . DIRECTORY_SEPARATOR . 'data';

    if (!is_dir($absolute_current) && !mkdir($absolute_current, 0777, true) && !is_dir($absolute_current)) {
        throw new RuntimeException('Unable to create data storage root path: data');
    }
    @chmod($absolute_current, 0777);

    foreach ($clean_segments as $segment) {
        $relative_parts[] = $segment;
        $absolute_current .= DIRECTORY_SEPARATOR . $segment;

        if (!is_dir($absolute_current) && !mkdir($absolute_current, 0777, true) && !is_dir($absolute_current)) {
            throw new RuntimeException('Unable to create data storage path: ' . implode('/', $relative_parts));
        }

        // Keep nested storage folders writable when CLI/root creates them.
        @chmod($absolute_current, 0777);
    }

    $relative_path = implode('/', $relative_parts);
    $absolute_path = $absolute_current;

    if (!is_writable($absolute_path)) {
        throw new RuntimeException('Data storage path is not writable: ' . $relative_path);
    }

    return [
        'relative_path' => $relative_path,
        'storage_path' => '../' . $relative_path,
        'absolute_path' => $absolute_path,
    ];
}

/**
 * Creates the base data storage folders required by the application.
 */
function ensure_data_storage_base_structure(): void {
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $base_folders = [
        'receipts',
        'reports',
        'expenses',
        'professional_charges',
        'monthly_reports',
        'daily_reports',
    ];

    foreach ($base_folders as $folder) {
        data_storage_prepare_path([$folder]);
    }

    $initialized = true;
}

/**
 * Builds receipts storage hierarchy:
 * receipts/year/month/date/bill_receipts
 *
 * @param DateTimeInterface|null $for_date Optional target date.
 * @return array{relative_path: string, storage_path: string, absolute_path: string}
 */
function data_storage_receipts_directory(?DateTimeInterface $for_date = null): array {
    $dt = $for_date ?: new DateTimeImmutable('now');
    return data_storage_prepare_path([
        'receipts',
        $dt->format('Y'),
        $dt->format('m'),
        $dt->format('d'),
        'bill_receipts',
    ]);
}

/**
 * Builds reports storage hierarchy:
 * reports/radiologist/year/month/main_category/date/reports
 *
 * @param string $radiologist_name Reporting radiologist name.
 * @param string $main_category Main test category.
 * @param DateTimeInterface|null $for_date Optional target date.
 * @return array{relative_path: string, storage_path: string, absolute_path: string}
 */
function data_storage_reports_directory(string $radiologist_name, string $main_category, ?DateTimeInterface $for_date = null): array {
    $dt = $for_date ?: new DateTimeImmutable('now');
    return data_storage_prepare_path([
        'reports',
        data_storage_safe_segment($radiologist_name, 'unassigned_radiologist'),
        $dt->format('Y'),
        $dt->format('m'),
        data_storage_safe_segment($main_category, 'uncategorized'),
        $dt->format('d'),
        'reports',
    ]);
}

/**
 * Builds expense proof storage hierarchy:
 * expenses/year/month/category/date/proof
 *
 * @param string $expense_category Expense category label.
 * @param DateTimeInterface|null $for_date Optional target date.
 * @return array{relative_path: string, storage_path: string, absolute_path: string}
 */
function data_storage_expense_proof_directory(string $expense_category, ?DateTimeInterface $for_date = null): array {
    $dt = $for_date ?: new DateTimeImmutable('now');
    return data_storage_prepare_path([
        'expenses',
        $dt->format('Y'),
        $dt->format('m'),
        data_storage_safe_segment($expense_category, 'general'),
        $dt->format('d'),
        'proof',
    ]);
}

/**
 * Builds professional charges storage hierarchy:
 * professional_charges/doctor/year/month/excel_sheet
 *
 * @param string $doctor_name Doctor name label.
 * @param DateTimeInterface|null $for_date Optional target date.
 * @return array{relative_path: string, storage_path: string, absolute_path: string}
 */
function data_storage_professional_charges_directory(string $doctor_name, ?DateTimeInterface $for_date = null): array {
    $dt = $for_date ?: new DateTimeImmutable('now');
    return data_storage_prepare_path([
        'professional_charges',
        data_storage_safe_segment($doctor_name, 'doctor'),
        $dt->format('Y'),
        $dt->format('m'),
        'excel_sheet',
    ]);
}

/**
 * Builds monthly reports storage hierarchy:
 * monthly_reports/year/month
 *
 * @param DateTimeInterface|null $for_date Optional target date.
 * @return array{relative_path: string, storage_path: string, absolute_path: string}
 */
function data_storage_monthly_reports_directory(?DateTimeInterface $for_date = null): array {
    $dt = $for_date ?: new DateTimeImmutable('now');
    return data_storage_prepare_path([
        'monthly_reports',
        $dt->format('Y'),
        $dt->format('m'),
    ]);
}

/**
 * Builds daily reports storage hierarchy:
 * daily_reports/year/month/date
 *
 * @param DateTimeInterface|null $for_date Optional target date.
 * @return array{relative_path: string, storage_path: string, absolute_path: string}
 */
function data_storage_daily_reports_directory(?DateTimeInterface $for_date = null): array {
    $dt = $for_date ?: new DateTimeImmutable('now');
    return data_storage_prepare_path([
        'daily_reports',
        $dt->format('Y'),
        $dt->format('m'),
        $dt->format('d'),
    ]);
}

/**
 * Normalizes a project-relative storage path and rejects traversal patterns.
 *
 * @param string $path Relative path from request/DB.
 * @return string|null Normalized relative path or null when invalid.
 */
function data_storage_normalize_relative_path(string $path): ?string {
    $candidate = trim(str_replace('\\', '/', $path));
    if ($candidate === '' || strpos($candidate, "\0") !== false) {
        return null;
    }

    while (strpos($candidate, '../') === 0) {
        $candidate = substr($candidate, 3);
    }
    while (strpos($candidate, './') === 0) {
        $candidate = substr($candidate, 2);
    }

    $candidate = ltrim($candidate, '/');
    if ($candidate === '') {
        return null;
    }

    $parts = explode('/', $candidate);
    $clean_parts = [];
    foreach ($parts as $part) {
        $seg = trim($part);
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            return null;
        }
        $clean_parts[] = $seg;
    }

    if (empty($clean_parts)) {
        return null;
    }

    return implode('/', $clean_parts);
}

/**
 * Converts an absolute file path under project root to project-relative storage path.
 *
 * @param string $absolute_path Absolute file path.
 * @return string|null Project-relative path or null when outside root.
 */
function data_storage_relative_from_absolute_path(string $absolute_path): ?string {
    $root_real = realpath(data_storage_project_root_path());
    if ($root_real === false) {
        return null;
    }

    $absolute_real = realpath($absolute_path);
    if ($absolute_real === false) {
        return null;
    }

    $root_norm = str_replace('\\', '/', $root_real);
    $absolute_norm = str_replace('\\', '/', $absolute_real);

    if (strpos($absolute_norm, $root_norm . '/') !== 0) {
        return null;
    }

    $relative = substr($absolute_norm, strlen($root_norm) + 1);
    return data_storage_normalize_relative_path($relative ?: '');
}

/**
 * Maps a primary storage relative path to its mirrored relative path.
 *
 * @param string $relative_path Normalized project-relative path.
 * @return string Mirror-relative path under data/mirror.
 */
function data_storage_mirror_relative_path(string $relative_path): string {
    $relative = data_storage_normalize_relative_path($relative_path) ?: '';
    if ($relative === '') {
        return 'data/mirror/invalid_path';
    }

    if (strpos($relative, 'data/') === 0) {
        $suffix = substr($relative, 5);
        return 'data/mirror/' . ltrim((string)$suffix, '/');
    }

    return 'data/mirror/legacy/' . $relative;
}

/**
 * Copies a stored project-relative file to mirrored storage path.
 *
 * @param string $path Stored DB path or relative path.
 * @return string|null Mirror storage path in DB format (../data/...) when copied.
 */
function data_storage_copy_file_to_mirror(string $path): ?string {
    $relative = data_storage_normalize_relative_path($path);
    if ($relative === null) {
        return null;
    }

    $root = data_storage_project_root_path();
    $source_absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($source_absolute)) {
        return null;
    }

    $mirror_relative = data_storage_mirror_relative_path($relative);
    $mirror_absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mirror_relative);
    $mirror_dir = dirname($mirror_absolute);
    if (!is_dir($mirror_dir) && !mkdir($mirror_dir, 0777, true) && !is_dir($mirror_dir)) {
        return null;
    }
    @chmod($mirror_dir, 0777);

    if (!@copy($source_absolute, $mirror_absolute)) {
        return null;
    }

    return '../' . $mirror_relative;
}

/**
 * Copies an absolute project file to mirrored storage path.
 *
 * @param string $absolute_path Absolute path under project root.
 * @return string|null Mirror storage path in DB format when copied.
 */
function data_storage_copy_absolute_file_to_mirror(string $absolute_path): ?string {
    $relative = data_storage_relative_from_absolute_path($absolute_path);
    if ($relative === null) {
        return null;
    }

    return data_storage_copy_file_to_mirror($relative);
}

/**
 * Resolves a stored file path to an existing absolute file path, primary first then mirror.
 *
 * @param string $path Stored DB path or relative path.
 * @return string|null Absolute file path when found.
 */
function data_storage_resolve_primary_or_mirror(string $path): ?string {
    $relative = data_storage_normalize_relative_path($path);
    if ($relative === null) {
        return null;
    }

    $root = data_storage_project_root_path();
    $primary_absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (is_file($primary_absolute)) {
        return $primary_absolute;
    }

    $mirror_relative = data_storage_mirror_relative_path($relative);
    $mirror_absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mirror_relative);
    if (is_file($mirror_absolute)) {
        return $mirror_absolute;
    }

    return null;
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

    $columns = schema_list_columns($conn, 'patients');
    foreach ($columns as $field) {
        if ($field === 'uid' || $field === 'registration_id') {
            $cached[$field] = true;
        }
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
 * Returns allowed bookmark statuses for writer saved bills staging.
 *
 * @return array<string, string>
 */
function writer_saved_bills_status_options(): array {
    return [
        'completed' => 'Completed',
        'needs_changes' => 'Needs Changes',
        'needs_approval' => 'Needs Approval',
    ];
}

/**
 * Normalizes bookmark status for writer saved bills staging.
 *
 * @param string|null $status Raw status value.
 * @return string One of allowed status keys.
 */
function writer_saved_bills_normalize_status(?string $status): string {
    $value = strtolower(trim((string)$status));
    $options = writer_saved_bills_status_options();
    if (isset($options[$value])) {
        return $value;
    }
    return 'completed';
}

/**
 * Ensures writer saved bills staging table exists.
 *
 * @param mysqli $conn The database connection object.
 * @throws Exception When schema operations fail.
 */
function ensure_writer_saved_bills_stage_table(mysqli $conn): void {
    static $is_checked = false;

    if ($is_checked) {
        return;
    }

    $create_sql = "CREATE TABLE IF NOT EXISTS writer_saved_bills_stage (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bill_item_id INT UNSIGNED NOT NULL,
        bookmark_status ENUM('completed','needs_changes','needs_approval') NOT NULL DEFAULT 'completed',
        saved_by INT UNSIGNED DEFAULT NULL,
        updated_by INT UNSIGNED DEFAULT NULL,
        saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_writer_saved_bill_item (bill_item_id),
        INDEX idx_writer_saved_status (bookmark_status),
        INDEX idx_writer_saved_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if (!$conn->query($create_sql)) {
        throw new Exception('Unable to ensure writer_saved_bills_stage table: ' . $conn->error);
    }

    if (!schema_has_column($conn, 'writer_saved_bills_stage', 'updated_by')) {
        if (!$conn->query("ALTER TABLE writer_saved_bills_stage ADD COLUMN updated_by INT UNSIGNED DEFAULT NULL AFTER saved_by")) {
            throw new Exception('Unable to add writer_saved_bills_stage.updated_by: ' . $conn->error);
        }
    }

    $is_checked = true;
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
    if (schema_has_column($conn, 'patients', 'registration_id')) {
        $conn->query("ALTER TABLE patients CHANGE COLUMN registration_id uid VARCHAR(12) NULL");
        // Drop old unique key if present then recreate under new name
        $conn->query("ALTER TABLE patients DROP INDEX uniq_patient_registration_id");
    }

    if (!schema_has_column($conn, 'patients', 'uid')) {
        if (!$conn->query("ALTER TABLE patients ADD COLUMN uid VARCHAR(12) NULL AFTER id")) {
            throw new Exception('Unable to add patients.uid column: ' . $conn->error);
        }
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
        if (!schema_has_column($conn, 'bills', $name)) {
            if (!$conn->query("ALTER TABLE bills ADD COLUMN {$name} {$definition}")) {
                throw new Exception('Unable to add bills.' . $name . ': ' . $conn->error);
            }
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
        if (!schema_has_column($conn, 'payment_history', $name)) {
            if (!$conn->query("ALTER TABLE payment_history ADD COLUMN {$name} {$definition}")) {
                throw new Exception('Unable to add payment_history.' . $name . ': ' . $conn->error);
            }
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
        if (!schema_has_column($conn, 'bill_items', $name)) {
            if (!$conn->query("ALTER TABLE bill_items ADD COLUMN {$name} {$definition}")) {
                throw new Exception('Unable to add bill_items.' . $name . ': ' . $conn->error);
            }
        }
    }

    $test_id_meta = schema_get_column_metadata($conn, 'bill_items', 'test_id');
    if (is_array($test_id_meta) && strtoupper((string)($test_id_meta['IS_NULLABLE'] ?? 'YES')) !== 'YES') {
        if (!$conn->query("ALTER TABLE bill_items MODIFY COLUMN test_id INT NULL")) {
            throw new Exception('Unable to update bill_items.test_id nullability: ' . $conn->error);
        }
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
 * Validates table or alias identifier safety.
 *
 * @param string $name SQL identifier candidate.
 * @return bool
 */
function table_scale_is_safe_identifier(string $name): bool {
    return (bool)preg_match('/^[A-Za-z0-9_]+$/', $name);
}

/**
 * Quotes SQL identifiers with backticks.
 *
 * @param string $identifier SQL identifier.
 * @return string
 */
function table_scale_quote_identifier(string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Returns shard table name for a base table and shard index.
 *
 * @param string $base_table Base table name.
 * @param int $shard_index Shard number (2+ for overflow tables).
 * @return string
 */
function table_scale_shard_table_name(string $base_table, int $shard_index): string {
    return $base_table . '__p' . $shard_index;
}

/**
 * Returns union view name for shard-aware reads.
 *
 * @param string $base_table Base table name.
 * @return string
 */
function table_scale_view_name(string $base_table): string {
    return 'vhs_' . $base_table;
}

/**
 * Builds deterministic SQL object names while staying within identifier limits.
 *
 * @param string $prefix Name prefix.
 * @param string $source Source table name.
 * @return string
 */
function table_mirror_compose_name(string $prefix, string $source): string {
    $candidate = $prefix . $source;
    if (strlen($candidate) <= 64) {
        return $candidate;
    }

    $hash = substr(sha1($source), 0, 12);
    $max_source_len = 64 - strlen($prefix) - 1 - strlen($hash);
    if ($max_source_len < 1) {
        $max_source_len = 1;
    }

    return $prefix . substr($source, 0, $max_source_len) . '_' . $hash;
}

/**
 * Returns mirrored table name for a physical table.
 *
 * @param string $source_table Source physical table name.
 * @return string
 */
function table_mirror_table_name(string $source_table): string {
    return table_mirror_compose_name('mir_', $source_table);
}

/**
 * Returns mirrored read-view name for a sharded base table.
 *
 * @param string $base_table Base table name.
 * @return string
 */
function table_mirror_view_name(string $base_table): string {
    return table_mirror_compose_name('vhm_', $base_table);
}

/**
 * Returns trigger name used for table mirror synchronization.
 *
 * @param string $source_table Source physical table name.
 * @param string $suffix Trigger suffix (ai|au|ad).
 * @return string
 */
function table_mirror_trigger_name(string $source_table, string $suffix): string {
    return table_mirror_compose_name('trg_m_' . $suffix . '_', $source_table);
}

/**
 * Ensures metadata table for table mirroring exists.
 *
 * @param mysqli $conn Database connection.
 */
function table_mirror_ensure_registry_schema(mysqli $conn): void {
    $sql = "CREATE TABLE IF NOT EXISTS table_mirror_registry (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_table VARCHAR(64) NOT NULL UNIQUE,
        mirror_table VARCHAR(64) NOT NULL,
        initial_sync_done TINYINT(1) NOT NULL DEFAULT 0,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_mirror_table (mirror_table)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    $conn->query($sql);
}

/**
 * Lists source physical tables that should be mirrored.
 *
 * @param mysqli $conn Database connection.
 * @return array<int, string>
 */
function table_mirror_list_source_tables(mysqli $conn): array {
    $tables = [];
    $sql = "SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_TYPE = 'BASE TABLE'
              AND TABLE_NAME <> 'table_mirror_registry'
              AND TABLE_NAME NOT LIKE 'mir\\_%' ESCAPE '\\\\'
            ORDER BY TABLE_NAME";

    $res = $conn->query($sql);
    if (!($res instanceof mysqli_result)) {
        return $tables;
    }

    while ($row = $res->fetch_assoc()) {
        $name = (string)($row['TABLE_NAME'] ?? '');
        if (table_scale_is_safe_identifier($name)) {
            $tables[] = $name;
        }
    }
    $res->free();

    return $tables;
}

/**
 * Lists column names for a physical table.
 *
 * @param mysqli $conn Database connection.
 * @param string $table_name Physical table name.
 * @return array<int, string>
 */
function table_mirror_list_columns(mysqli $conn, string $table_name): array {
    $columns = [];
    if (!table_scale_is_safe_identifier($table_name)) {
        return $columns;
    }

    $stmt = $conn->prepare("SELECT COLUMN_NAME
                            FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME = ?
                            ORDER BY ORDINAL_POSITION");
    if (!$stmt) {
        return $columns;
    }

    $stmt->bind_param('s', $table_name);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $col = (string)($row['COLUMN_NAME'] ?? '');
        if (table_scale_is_safe_identifier($col)) {
            $columns[] = $col;
        }
    }
    $stmt->close();

    return $columns;
}

/**
 * Lists primary-key columns for a physical table.
 *
 * @param mysqli $conn Database connection.
 * @param string $table_name Physical table name.
 * @return array<int, string>
 */
function table_mirror_list_primary_columns(mysqli $conn, string $table_name): array {
    $columns = [];
    if (!table_scale_is_safe_identifier($table_name)) {
        return $columns;
    }

    $stmt = $conn->prepare("SELECT COLUMN_NAME
                            FROM information_schema.KEY_COLUMN_USAGE
                            WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME = ?
                              AND CONSTRAINT_NAME = 'PRIMARY'
                            ORDER BY ORDINAL_POSITION");
    if (!$stmt) {
        return $columns;
    }

    $stmt->bind_param('s', $table_name);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $col = (string)($row['COLUMN_NAME'] ?? '');
        if (table_scale_is_safe_identifier($col)) {
            $columns[] = $col;
        }
    }
    $stmt->close();

    return $columns;
}

/**
 * Checks if a trigger already exists in current schema.
 *
 * @param mysqli $conn Database connection.
 * @param string $trigger_name Trigger identifier.
 * @return bool
 */
function table_mirror_trigger_exists(mysqli $conn, string $trigger_name): bool {
    if (!table_scale_is_safe_identifier($trigger_name)) {
        return false;
    }

    $stmt = $conn->prepare("SELECT 1
                            FROM information_schema.TRIGGERS
                            WHERE TRIGGER_SCHEMA = DATABASE()
                              AND TRIGGER_NAME = ?
                            LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $trigger_name);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && (bool)$res->fetch_row();
    $stmt->close();

    return $exists;
}

/**
 * Ensures a mirror trigger exists for the given source table event.
 *
 * @param mysqli $conn Database connection.
 * @param string $source_table Source table name.
 * @param string $mirror_table Mirror table name.
 * @param string $event insert|update|delete.
 * @param array<int, string> $columns Source table columns.
 * @param array<int, string> $match_columns Key columns used for delete matching.
 */
function table_mirror_ensure_trigger(
    mysqli $conn,
    string $source_table,
    string $mirror_table,
    string $event,
    array $columns,
    array $match_columns
): void {
    $event = strtolower(trim($event));
    if (!in_array($event, ['insert', 'update', 'delete'], true)) {
        return;
    }

    $suffix_map = ['insert' => 'ai', 'update' => 'au', 'delete' => 'ad'];
    $trigger_name = table_mirror_trigger_name($source_table, $suffix_map[$event]);
    if (table_mirror_trigger_exists($conn, $trigger_name)) {
        return;
    }

    $quoted_trigger = table_scale_quote_identifier($trigger_name);
    $quoted_source = table_scale_quote_identifier($source_table);
    $quoted_mirror = table_scale_quote_identifier($mirror_table);

    $body_sql = '';
    if ($event === 'insert' || $event === 'update') {
        $safe_columns = array_values(array_filter($columns, 'table_scale_is_safe_identifier'));
        if (empty($safe_columns)) {
            return;
        }

        $column_list = implode(', ', array_map('table_scale_quote_identifier', $safe_columns));
        $new_values = implode(', ', array_map(static function ($col) {
            return 'NEW.' . table_scale_quote_identifier($col);
        }, $safe_columns));
        $update_list = implode(', ', array_map(static function ($col) {
            $quoted = table_scale_quote_identifier($col);
            return $quoted . ' = VALUES(' . $quoted . ')';
        }, $safe_columns));

        $body_sql = 'INSERT INTO ' . $quoted_mirror . ' (' . $column_list . ') VALUES (' . $new_values . ') ON DUPLICATE KEY UPDATE ' . $update_list;
    } else {
        $safe_match_columns = array_values(array_filter($match_columns, 'table_scale_is_safe_identifier'));
        if (empty($safe_match_columns)) {
            $safe_match_columns = array_values(array_filter($columns, 'table_scale_is_safe_identifier'));
        }
        if (empty($safe_match_columns)) {
            return;
        }

        $where_sql = implode(' AND ', array_map(static function ($col) {
            $quoted = table_scale_quote_identifier($col);
            return $quoted . ' <=> OLD.' . $quoted;
        }, $safe_match_columns));

        $body_sql = 'DELETE FROM ' . $quoted_mirror . ' WHERE ' . $where_sql;
    }

    $sql = 'CREATE TRIGGER ' . $quoted_trigger .
           ' AFTER ' . strtoupper($event) .
           ' ON ' . $quoted_source .
           ' FOR EACH ROW ' . $body_sql;

    if (!$conn->query($sql)) {
        error_log('Table mirror trigger create failed for ' . $source_table . ' (' . $event . '): ' . $conn->error);
    }
}

/**
 * Upserts table mirror registry row.
 *
 * @param mysqli $conn Database connection.
 * @param string $source_table Source table name.
 * @param string $mirror_table Mirror table name.
 * @param int $initial_sync_done 0 or 1.
 */
function table_mirror_sync_registry_row(mysqli $conn, string $source_table, string $mirror_table, int $initial_sync_done): void {
    table_mirror_ensure_registry_schema($conn);

    $sync_flag = ($initial_sync_done === 1) ? 1 : 0;
    $stmt = $conn->prepare("INSERT INTO table_mirror_registry (source_table, mirror_table, initial_sync_done, is_enabled)
                            VALUES (?, ?, ?, 1)
                            ON DUPLICATE KEY UPDATE mirror_table = VALUES(mirror_table), initial_sync_done = VALUES(initial_sync_done), is_enabled = 1, updated_at = CURRENT_TIMESTAMP");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ssi', $source_table, $mirror_table, $sync_flag);
    $stmt->execute();
    $stmt->close();
}

/**
 * Ensures mirrored table and sync triggers for one source table.
 *
 * @param mysqli $conn Database connection.
 * @param string $source_table Source table name.
 */
function table_mirror_ensure_table(mysqli $conn, string $source_table): void {
    if (!table_scale_is_safe_identifier($source_table)) {
        return;
    }

    $mirror_table = table_mirror_table_name($source_table);
    if (!table_scale_is_safe_identifier($mirror_table)) {
        return;
    }

    $create_sql = 'CREATE TABLE IF NOT EXISTS ' . table_scale_quote_identifier($mirror_table) . ' LIKE ' . table_scale_quote_identifier($source_table);
    if (!$conn->query($create_sql)) {
        error_log('Table mirror create failed for ' . $source_table . ': ' . $conn->error);
        return;
    }

    $columns = table_mirror_list_columns($conn, $source_table);
    if (empty($columns)) {
        return;
    }

    $primary_columns = table_mirror_list_primary_columns($conn, $source_table);
    table_mirror_ensure_trigger($conn, $source_table, $mirror_table, 'insert', $columns, $primary_columns);
    table_mirror_ensure_trigger($conn, $source_table, $mirror_table, 'update', $columns, $primary_columns);
    table_mirror_ensure_trigger($conn, $source_table, $mirror_table, 'delete', $columns, $primary_columns);

    $initial_sync_done = 0;
    $stmt = $conn->prepare("SELECT initial_sync_done FROM table_mirror_registry WHERE source_table = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $source_table);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        $initial_sync_done = (int)($row['initial_sync_done'] ?? 0);
    }

    if ($initial_sync_done !== 1) {
        $column_list = implode(', ', array_map('table_scale_quote_identifier', $columns));
        $select_list = implode(', ', array_map('table_scale_quote_identifier', $columns));
        $update_list = implode(', ', array_map(static function ($col) {
            $quoted = table_scale_quote_identifier($col);
            return $quoted . ' = VALUES(' . $quoted . ')';
        }, $columns));

        $seed_sql = 'INSERT INTO ' . table_scale_quote_identifier($mirror_table) .
                    ' (' . $column_list . ') ' .
                    'SELECT ' . $select_list . ' FROM ' . table_scale_quote_identifier($source_table) .
                    ' ON DUPLICATE KEY UPDATE ' . $update_list;

        if ($conn->query($seed_sql)) {
            $initial_sync_done = 1;
        } else {
            error_log('Table mirror seed failed for ' . $source_table . ': ' . $conn->error);
        }
    }

    table_mirror_sync_registry_row($conn, $source_table, $mirror_table, $initial_sync_done);
}

/**
 * Refreshes mirrored UNION ALL view for sharded base tables.
 *
 * @param mysqli $conn Database connection.
 * @param string $base_table Base table name.
 * @param int $active_shards Active shard count.
 */
function table_mirror_refresh_union_view(mysqli $conn, string $base_table, int $active_shards): void {
    $safe_table = trim($base_table);
    if (!table_scale_is_safe_identifier($safe_table)) {
        return;
    }

    $view_name = table_mirror_view_name($safe_table);
    $quoted_view = table_scale_quote_identifier($view_name);
    if ($active_shards <= 1) {
        $conn->query('DROP VIEW IF EXISTS ' . $quoted_view);
        return;
    }

    $parts = [];
    for ($i = 1; $i <= $active_shards; $i++) {
        $source_table = ($i === 1) ? $safe_table : table_scale_shard_table_name($safe_table, $i);
        $mirror_table = table_mirror_table_name($source_table);
        if (!table_mirror_probe_source($conn, $mirror_table)) {
            continue;
        }
        $parts[] = 'SELECT * FROM ' . table_scale_quote_identifier($mirror_table);
    }

    if (count($parts) <= 1) {
        $conn->query('DROP VIEW IF EXISTS ' . $quoted_view);
        return;
    }

    $sql = 'CREATE OR REPLACE VIEW ' . $quoted_view . ' AS ' . implode(' UNION ALL ', $parts);
    if (!$conn->query($sql)) {
        error_log('Table mirror view refresh failed for ' . $safe_table . ': ' . $conn->error);
    }
}

/**
 * Probes whether a table/view can be read from the current connection.
 *
 * @param mysqli $conn Database connection.
 * @param string $source Table or view identifier.
 * @return bool
 */
function table_mirror_probe_source(mysqli $conn, string $source): bool {
    static $cache = [];

    $safe_source = trim($source);
    if (!table_scale_is_safe_identifier($safe_source)) {
        return false;
    }

    if (array_key_exists($safe_source, $cache)) {
        return (bool)$cache[$safe_source];
    }

    $sql = 'SELECT 1 FROM ' . table_scale_quote_identifier($safe_source) . ' LIMIT 1';
    $ok = false;
    try {
        $probe_res = $conn->query($sql);
        $ok = ($probe_res !== false);
        if ($probe_res instanceof mysqli_result) {
            $probe_res->free();
        }
    } catch (Throwable $e) {
        $ok = false;
    }

    $cache[$safe_source] = $ok;
    return $ok;
}

/**
 * Returns mirrored read source for a base table when available.
 *
 * @param mysqli $conn Database connection.
 * @param string $base_table Base table name.
 * @param int $active_shards Active shards count.
 * @return string Empty string when mirror source is unavailable.
 */
function table_mirror_get_read_source(mysqli $conn, string $base_table, int $active_shards = 1): string {
    $safe_table = trim($base_table);
    if (!table_scale_is_safe_identifier($safe_table)) {
        return '';
    }

    $source = ($active_shards > 1)
        ? table_mirror_view_name($safe_table)
        : table_mirror_table_name($safe_table);

    return table_mirror_probe_source($conn, $source) ? $source : '';
}

/**
 * Ensures table mirror sync infrastructure across all source tables.
 *
 * @param mysqli $conn Database connection.
 */
function ensure_table_mirror_sync(mysqli $conn): void {
    static $is_checked = false;

    if ($is_checked) {
        return;
    }
    $is_checked = true;

    $enabled_env = strtolower(trim((string)(getenv('TABLE_MIRROR_ENABLED') ?: 'true')));
    if (in_array($enabled_env, ['0', 'false', 'off', 'no'], true)) {
        return;
    }

    table_mirror_ensure_registry_schema($conn);

    $tables = table_mirror_list_source_tables($conn);
    foreach ($tables as $source_table) {
        table_mirror_ensure_table($conn, $source_table);
    }

    $registry_res = $conn->query("SELECT base_table, active_shards FROM table_shard_registry WHERE is_enabled = 1 AND active_shards > 1");
    if ($registry_res instanceof mysqli_result) {
        while ($row = $registry_res->fetch_assoc()) {
            $base_table = (string)($row['base_table'] ?? '');
            $active_shards = max(1, (int)($row['active_shards'] ?? 1));
            if (!table_scale_is_safe_identifier($base_table)) {
                continue;
            }
            table_mirror_refresh_union_view($conn, $base_table, $active_shards);
        }
        $registry_res->free();
    }
}

/**
 * Repairs mirrored tables by re-seeding any table whose row count diverges
 * from the source table.
 *
 * @param mysqli $conn Database connection.
 * @param bool $force_all When true, re-seeds all mirrored tables.
 * @return array{checked:int,repaired:int,skipped:int,errors:int,tables:array<int,array<string,mixed>>}
 */
function table_mirror_repair_diverged_tables(mysqli $conn, bool $force_all = false): array {
    $summary = [
        'checked' => 0,
        'repaired' => 0,
        'skipped' => 0,
        'errors' => 0,
        'tables' => [],
    ];

    $enabled_env = strtolower(trim((string)(getenv('TABLE_MIRROR_ENABLED') ?: 'true')));
    if (in_array($enabled_env, ['0', 'false', 'off', 'no'], true)) {
        return $summary;
    }

    table_mirror_ensure_registry_schema($conn);
    $tables = table_mirror_list_source_tables($conn);
    if (empty($tables)) {
        return $summary;
    }

    $conn->query('SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS');
    $conn->query('SET FOREIGN_KEY_CHECKS = 0');

    try {
        foreach ($tables as $source_table) {
            $summary['checked']++;
            $mirror_table = table_mirror_table_name($source_table);

            $row = [
                'source_table' => $source_table,
                'mirror_table' => $mirror_table,
                'source_rows' => 0,
                'mirror_rows_before' => 0,
                'mirror_rows_after' => 0,
                'status' => 'skipped',
                'message' => '',
            ];

            if (!table_scale_is_safe_identifier($source_table) || !table_scale_is_safe_identifier($mirror_table)) {
                $row['status'] = 'error';
                $row['message'] = 'Unsafe table identifier.';
                $summary['errors']++;
                $summary['tables'][] = $row;
                continue;
            }

            // Ensure mirror table and triggers exist before attempting repairs.
            table_mirror_ensure_table($conn, $source_table);

            if (!table_mirror_probe_source($conn, $source_table)) {
                $row['status'] = 'error';
                $row['message'] = 'Source table is not readable.';
                $summary['errors']++;
                $summary['tables'][] = $row;
                continue;
            }

            if (!table_mirror_probe_source($conn, $mirror_table)) {
                $row['status'] = 'error';
                $row['message'] = 'Mirror table is not readable.';
                $summary['errors']++;
                $summary['tables'][] = $row;
                continue;
            }

            $source_count = 0;
            $mirror_count_before = 0;

            $src_count_res = $conn->query('SELECT COUNT(*) AS c FROM ' . table_scale_quote_identifier($source_table));
            if ($src_count_res instanceof mysqli_result) {
                $src_count_row = $src_count_res->fetch_assoc();
                $source_count = (int)($src_count_row['c'] ?? 0);
                $src_count_res->free();
            }

            $mir_count_res = $conn->query('SELECT COUNT(*) AS c FROM ' . table_scale_quote_identifier($mirror_table));
            if ($mir_count_res instanceof mysqli_result) {
                $mir_count_row = $mir_count_res->fetch_assoc();
                $mirror_count_before = (int)($mir_count_row['c'] ?? 0);
                $mir_count_res->free();
            }

            $row['source_rows'] = $source_count;
            $row['mirror_rows_before'] = $mirror_count_before;

            if (!$force_all && $source_count === $mirror_count_before) {
                $row['mirror_rows_after'] = $mirror_count_before;
                $row['status'] = 'skipped';
                $row['message'] = 'Row counts are already aligned.';
                $summary['skipped']++;
                $summary['tables'][] = $row;
                continue;
            }

            $columns = table_mirror_list_columns($conn, $source_table);
            if (empty($columns)) {
                $row['status'] = 'error';
                $row['message'] = 'Unable to list table columns.';
                $summary['errors']++;
                $summary['tables'][] = $row;
                continue;
            }

            $quoted_columns = implode(', ', array_map('table_scale_quote_identifier', $columns));

            if (!$conn->query('DELETE FROM ' . table_scale_quote_identifier($mirror_table))) {
                $row['status'] = 'error';
                $row['message'] = 'Failed clearing mirror table: ' . $conn->error;
                $summary['errors']++;
                $summary['tables'][] = $row;
                continue;
            }

            $seed_sql = 'INSERT INTO ' . table_scale_quote_identifier($mirror_table) .
                        ' (' . $quoted_columns . ') ' .
                        'SELECT ' . $quoted_columns . ' FROM ' . table_scale_quote_identifier($source_table);

            if (!$conn->query($seed_sql)) {
                $row['status'] = 'error';
                $row['message'] = 'Failed re-seeding mirror table: ' . $conn->error;
                $summary['errors']++;
                $summary['tables'][] = $row;
                continue;
            }

            $mirror_count_after = 0;
            $mir_after_res = $conn->query('SELECT COUNT(*) AS c FROM ' . table_scale_quote_identifier($mirror_table));
            if ($mir_after_res instanceof mysqli_result) {
                $mir_after_row = $mir_after_res->fetch_assoc();
                $mirror_count_after = (int)($mir_after_row['c'] ?? 0);
                $mir_after_res->free();
            }

            $row['mirror_rows_after'] = $mirror_count_after;

            if ($mirror_count_after === $source_count) {
                $row['status'] = 'repaired';
                $row['message'] = 'Mirror re-seeded successfully.';
                $summary['repaired']++;
                table_mirror_sync_registry_row($conn, $source_table, $mirror_table, 1);
            } else {
                $row['status'] = 'error';
                $row['message'] = 'Row counts still differ after re-seed.';
                $summary['errors']++;
            }

            $summary['tables'][] = $row;
        }
    } finally {
        $conn->query('SET FOREIGN_KEY_CHECKS = IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1)');
    }

    return $summary;
}

/**
 * Ensures metadata table for horizontal table scaling exists.
 *
 * @param mysqli $conn Database connection.
 */
function table_scale_ensure_registry_schema(mysqli $conn): void {
    $sql = "CREATE TABLE IF NOT EXISTS table_shard_registry (
        id INT AUTO_INCREMENT PRIMARY KEY,
        base_table VARCHAR(64) NOT NULL UNIQUE,
        threshold_rows INT NOT NULL DEFAULT 100000,
        active_shards INT NOT NULL DEFAULT 1,
        last_exact_rows BIGINT NOT NULL DEFAULT 0,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    $conn->query($sql);
}

/**
 * Reads active shard count for a base table from registry.
 *
 * @param mysqli $conn Database connection.
 * @param string $base_table Base table name.
 * @return int
 */
function table_scale_get_active_shards(mysqli $conn, string $base_table): int {
    static $cache = [];

    $safe_table = trim($base_table);
    if (!table_scale_is_safe_identifier($safe_table)) {
        return 1;
    }

    if (array_key_exists($safe_table, $cache)) {
        return max(1, (int)$cache[$safe_table]);
    }

    table_scale_ensure_registry_schema($conn);

    $stmt = $conn->prepare("SELECT active_shards FROM table_shard_registry WHERE base_table = ? AND is_enabled = 1 LIMIT 1");
    if (!$stmt) {
        $cache[$safe_table] = 1;
        return 1;
    }

    $stmt->bind_param('s', $safe_table);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $shards = $row ? (int)($row['active_shards'] ?? 1) : 1;
    $cache[$safe_table] = max(1, $shards);
    return max(1, $shards);
}

/**
 * Lists physical tables for a shard-managed base table.
 *
 * @param mysqli $conn Database connection.
 * @param string $base_table Base table name.
 * @return array<int, string>
 */
function table_scale_list_physical_tables(mysqli $conn, string $base_table): array {
    $safe_table = trim($base_table);
    if (!table_scale_is_safe_identifier($safe_table)) {
        return [];
    }

    $shards = table_scale_get_active_shards($conn, $safe_table);
    $tables = [$safe_table];

    for ($i = 2; $i <= $shards; $i++) {
        $tables[] = table_scale_shard_table_name($safe_table, $i);
    }

    return $tables;
}

/**
 * Creates or refreshes UNION ALL read view for a sharded base table.
 *
 * @param mysqli $conn Database connection.
 * @param string $base_table Base table name.
 * @param int $active_shards Active shard count.
 */
function table_scale_refresh_union_view(mysqli $conn, string $base_table, int $active_shards): void {
    $safe_table = trim($base_table);
    if (!table_scale_is_safe_identifier($safe_table)) {
        return;
    }

    $view_name = table_scale_view_name($safe_table);
    $quoted_view = table_scale_quote_identifier($view_name);

    if ($active_shards <= 1) {
        $conn->query("DROP VIEW IF EXISTS {$quoted_view}");
        return;
    }

    $parts = [];
    $parts[] = 'SELECT * FROM ' . table_scale_quote_identifier($safe_table);
    for ($i = 2; $i <= $active_shards; $i++) {
        $parts[] = 'SELECT * FROM ' . table_scale_quote_identifier(table_scale_shard_table_name($safe_table, $i));
    }

    $sql = 'CREATE OR REPLACE VIEW ' . $quoted_view . ' AS ' . implode(' UNION ALL ', $parts);
    if (!$conn->query($sql)) {
        error_log('Table scale view refresh failed for ' . $safe_table . ': ' . $conn->error);
    }
}

/**
 * Ensures sharding metadata and overflow table creation for all eligible tables.
 *
 * Notes:
 * - Tables are split by overflow table creation at 100k-row boundaries.
 * - Existing rows are not moved automatically.
 * - Searches can read across all shards through generated `vhs_<table>` views.
 *
 * @param mysqli $conn Database connection.
 * @param int $threshold Maximum rows per shard.
 */
function ensure_horizontal_table_scaling(mysqli $conn, int $threshold = 100000): void {
    static $is_checked = false;

    if ($is_checked) {
        return;
    }
    $is_checked = true;

    $threshold = max(1000, (int)$threshold);
    table_scale_ensure_registry_schema($conn);

    $registry = [];
    $registry_res = $conn->query("SELECT base_table, active_shards FROM table_shard_registry WHERE is_enabled = 1");
    if ($registry_res instanceof mysqli_result) {
        while ($row = $registry_res->fetch_assoc()) {
            $name = (string)($row['base_table'] ?? '');
            if (table_scale_is_safe_identifier($name)) {
                $registry[$name] = max(1, (int)($row['active_shards'] ?? 1));
            }
        }
        $registry_res->free();
    }

        $tables_sql = "SELECT TABLE_NAME, TABLE_ROWS, AUTO_INCREMENT
                   FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_TYPE = 'BASE TABLE'
                     AND TABLE_NAME <> 'table_shard_registry'
                     AND TABLE_NAME NOT REGEXP '__p[0-9]+$'
                   ORDER BY TABLE_NAME";
    $tables_res = $conn->query($tables_sql);
    if (!($tables_res instanceof mysqli_result)) {
        return;
    }

    while ($row = $tables_res->fetch_assoc()) {
        $base_table = (string)($row['TABLE_NAME'] ?? '');
        if (!table_scale_is_safe_identifier($base_table)) {
            continue;
        }

        $known_shards = max(1, (int)($registry[$base_table] ?? 1));
        $estimated_rows = max(0, (int)($row['TABLE_ROWS'] ?? 0));
        $auto_increment_hint = max(0, (int)($row['AUTO_INCREMENT'] ?? 0));

        // Fast skip for clearly small tables not currently sharded.
        // AUTO_INCREMENT is used as a second signal because InnoDB TABLE_ROWS can be coarse.
        if ($known_shards <= 1 && $estimated_rows < $threshold && $auto_increment_hint <= ($threshold + 1)) {
            continue;
        }

        $exact_rows = 0;
        $count_sql = 'SELECT COUNT(*) AS total_rows FROM ' . table_scale_quote_identifier($base_table);
        $count_res = $conn->query($count_sql);
        if ($count_res instanceof mysqli_result) {
            $count_row = $count_res->fetch_assoc();
            $exact_rows = (int)($count_row['total_rows'] ?? 0);
            $count_res->free();
        }

        $required_shards = max(1, (int)ceil(max(1, $exact_rows) / $threshold));
        $active_shards = max($known_shards, $required_shards);

        for ($i = 2; $i <= $active_shards; $i++) {
            $shard_table = table_scale_shard_table_name($base_table, $i);
            if (!table_scale_is_safe_identifier($shard_table)) {
                continue;
            }

            $create_sql = 'CREATE TABLE IF NOT EXISTS ' . table_scale_quote_identifier($shard_table) .
                          ' LIKE ' . table_scale_quote_identifier($base_table);
            if (!$conn->query($create_sql)) {
                error_log('Table scale shard create failed for ' . $shard_table . ': ' . $conn->error);
                continue;
            }

            $next_auto = (($i - 1) * $threshold) + 1;
            $conn->query('ALTER TABLE ' . table_scale_quote_identifier($shard_table) . ' AUTO_INCREMENT = ' . (int)$next_auto);
        }

        table_scale_refresh_union_view($conn, $base_table, $active_shards);

        $stmt = $conn->prepare("INSERT INTO table_shard_registry (base_table, threshold_rows, active_shards, last_exact_rows, is_enabled)
                                VALUES (?, ?, ?, ?, 1)
                                ON DUPLICATE KEY UPDATE threshold_rows = VALUES(threshold_rows), active_shards = VALUES(active_shards), last_exact_rows = VALUES(last_exact_rows), is_enabled = 1");
        if ($stmt) {
            $stmt->bind_param('siii', $base_table, $threshold, $active_shards, $exact_rows);
            $stmt->execute();
            $stmt->close();
        }
    }

    $tables_res->free();
}

/**
 * Returns shard-aware read source for SQL FROM/JOIN usage.
 *
 * @param mysqli $conn Database connection.
 * @param string $base_table Base table name.
 * @param string $alias Optional alias.
 * @return string SQL fragment like "`vhs_bills` b".
 */
function table_scale_get_read_source(mysqli $conn, string $base_table, string $alias = ''): string {
    $safe_table = trim($base_table);
    if (!table_scale_is_safe_identifier($safe_table)) {
        return table_scale_quote_identifier($base_table);
    }

    $shards = table_scale_get_active_shards($conn, $safe_table);
    $source = ($shards > 1) ? table_scale_view_name($safe_table) : $safe_table;

    if (!table_mirror_probe_source($conn, $source)) {
        $mirror_source = table_mirror_get_read_source($conn, $safe_table, $shards);
        if ($mirror_source !== '') {
            error_log('Primary read source unavailable for ' . $safe_table . '; using mirror source ' . $mirror_source);
            $source = $mirror_source;
        }
    }

    $fragment = table_scale_quote_identifier($source);
    $safe_alias = trim($alias);
    if ($safe_alias !== '' && table_scale_is_safe_identifier($safe_alias)) {
        $fragment .= ' ' . $safe_alias;
    }

    return $fragment;
}

/**
 * Returns shard-aware write table (latest shard when sharding is active).
 *
 * @param mysqli $conn Database connection.
 * @param string $base_table Base table name.
 * @return string Physical table name for writes.
 */
function table_scale_get_write_table(mysqli $conn, string $base_table): string {
    $safe_table = trim($base_table);
    if (!table_scale_is_safe_identifier($safe_table)) {
        return $base_table;
    }

    $shards = table_scale_get_active_shards($conn, $safe_table);
    if ($shards <= 1) {
        return $safe_table;
    }

    return table_scale_shard_table_name($safe_table, $shards);
}

/**
 * Finds which physical table currently stores a given row by id.
 *
 * @param mysqli $conn Database connection.
 * @param string $base_table Base table name.
 * @param int $id Row id.
 * @param string $id_column PK/id column name.
 * @return string|null Physical table name if found.
 */
function table_scale_find_physical_table_by_id(mysqli $conn, string $base_table, int $id, string $id_column = 'id'): ?string {
    if ($id <= 0) {
        return null;
    }

    $safe_table = trim($base_table);
    $safe_id_col = trim($id_column);
    if (!table_scale_is_safe_identifier($safe_table) || !table_scale_is_safe_identifier($safe_id_col)) {
        return null;
    }

    $tables = array_reverse(table_scale_list_physical_tables($conn, $safe_table));
    foreach ($tables as $table_name) {
        if (!table_scale_is_safe_identifier($table_name)) {
            continue;
        }

        $sql = 'SELECT 1 FROM ' . table_scale_quote_identifier($table_name) . ' WHERE ' . table_scale_quote_identifier($safe_id_col) . ' = ? LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            continue;
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result && (bool)$result->fetch_row();
        $stmt->close();

        if ($exists) {
            return $table_name;
        }
    }

    return $safe_table;
}

/**
 * Resolves write table for an existing row id, with safe fallback.
 *
 * @param mysqli $conn Database connection.
 * @param string $base_table Base table name.
 * @param int $id Row id.
 * @param string $id_column ID column name.
 * @return string Physical write table.
 */
function table_scale_get_write_table_for_row(mysqli $conn, string $base_table, int $id, string $id_column = 'id'): string {
    $safe_table = trim($base_table);
    if (!table_scale_is_safe_identifier($safe_table)) {
        return $base_table;
    }

    if ($id > 0) {
        $resolved = table_scale_find_physical_table_by_id($conn, $safe_table, $id, $id_column);
        if (is_string($resolved) && $resolved !== '' && table_scale_is_safe_identifier($resolved)) {
            return $resolved;
        }
    }

    return table_scale_get_write_table($conn, $safe_table);
}

/**
 * Applies same ALTER TABLE clause on base table and all overflow shards.
 *
 * @param mysqli $conn Database connection.
 * @param string $base_table Base table name.
 * @param string $alter_clause ALTER clause without "ALTER TABLE".
 */
function table_scale_apply_alter_to_all_physical_tables(mysqli $conn, string $base_table, string $alter_clause): void {
    $safe_table = trim($base_table);
    if (!table_scale_is_safe_identifier($safe_table)) {
        return;
    }

    $clause = trim($alter_clause);
    if ($clause === '' || strpos($clause, ';') !== false) {
        return;
    }

    $tables = table_scale_list_physical_tables($conn, $safe_table);
    if (empty($tables)) {
        $tables = [$safe_table];
    }

    foreach ($tables as $table_name) {
        if (!table_scale_is_safe_identifier($table_name)) {
            continue;
        }

        $sql = 'ALTER TABLE ' . table_scale_quote_identifier($table_name) . ' ' . $clause;
        if (!$conn->query($sql)) {
            error_log('Table scale ALTER sync failed for ' . $table_name . ': ' . $conn->error);
        }
    }

    // Rebuild read view so column shape remains consistent.
    $shards = table_scale_get_active_shards($conn, $safe_table);
    table_scale_refresh_union_view($conn, $safe_table, $shards);
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