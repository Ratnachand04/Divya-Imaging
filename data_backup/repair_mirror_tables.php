<?php
/**
 * CLI helper: Repair mirror tables by re-seeding any table whose row counts
 * diverge from the source table.
 *
 * Usage:
 *   php data_backup/repair_mirror_tables.php
 *   php data_backup/repair_mirror_tables.php --all
 *   php data_backup/repair_mirror_tables.php --json
 */

if (php_sapi_name() !== 'cli') {
    echo "This script is CLI-only.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

$argv_list = isset($argv) && is_array($argv) ? $argv : [];
$force_all = in_array('--all', $argv_list, true);
$as_json = in_array('--json', $argv_list, true);

if (!function_exists('table_mirror_repair_diverged_tables')) {
    echo "Mirror repair helper is not available.\n";
    exit(1);
}

$result = table_mirror_repair_diverged_tables($conn, $force_all);

if ($as_json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(((int)($result['errors'] ?? 0)) > 0 ? 1 : 0);
}

$checked = (int)($result['checked'] ?? 0);
$repaired = (int)($result['repaired'] ?? 0);
$skipped = (int)($result['skipped'] ?? 0);
$errors = (int)($result['errors'] ?? 0);

echo "Mirror repair summary: checked={$checked}, repaired={$repaired}, skipped={$skipped}, errors={$errors}\n";

$tables = isset($result['tables']) && is_array($result['tables']) ? $result['tables'] : [];
foreach ($tables as $row) {
    $source = (string)($row['source_table'] ?? 'unknown');
    $status = (string)($row['status'] ?? 'unknown');
    $src = (int)($row['source_rows'] ?? 0);
    $mir_before = (int)($row['mirror_rows_before'] ?? 0);
    $mir_after = (int)($row['mirror_rows_after'] ?? 0);
    $message = (string)($row['message'] ?? '');

    echo "- {$source}: {$status} (source={$src}, mirror_before={$mir_before}, mirror_after={$mir_after})";
    if ($message !== '') {
        echo " - {$message}";
    }
    echo "\n";
}

if ($errors > 0) {
    exit(1);
}

exit(0);
