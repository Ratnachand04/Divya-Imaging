<?php
/**
 * CLI helper: Update backup index from shell/bat scripts.
 * Usage: php update_index_cli.php <backup_file> <db_name> <year> <month>
 */

if (php_sapi_name() !== 'cli' || $argc < 5) {
    echo "Usage: php update_index_cli.php <backup_file> <db_name> <year> <month>\n";
    exit(1);
}

$backup_file = $argv[1];
$db_name     = $argv[2];
$year        = $argv[3];
$month       = $argv[4];

if (!file_exists($backup_file)) {
    echo "Error: Backup file not found: {$backup_file}\n";
    exit(1);
}

$storage_base = __DIR__ . '/../dump/backup';
if (!is_dir($storage_base)) {
    mkdir($storage_base, 0775, true);
}

$index_file = $storage_base . '/backup_index.json';
$index = [];
if (file_exists($index_file)) {
    $index = json_decode(file_get_contents($index_file), true) ?: [];
}

// Parse table names from the SQL file (scan for CREATE TABLE statements)
$tables = [];
$table_rows = [];
$fh = fopen($backup_file, 'r');
if ($fh) {
    while (($line = fgets($fh)) !== false) {
        if (preg_match('/^CREATE TABLE.*`([^`]+)`/i', $line, $m)) {
            $tables[] = $m[1];
        }
        if (preg_match('/^INSERT INTO `([^`]+)`/i', $line, $m)) {
            $tbl = $m[1];
            // Count approximate rows by counting value groups
            $count = substr_count($line, '),(') + 1;
            $table_rows[$tbl] = ($table_rows[$tbl] ?? 0) + $count;
        }
    }
    fclose($fh);
}

$total_rows = array_sum($table_rows);
$file_size = filesize($backup_file);

// Format size
function fmt_size($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

// Build relative path
$real_backup_file = realpath($backup_file);
$rel_path = str_replace($storage_base . '/', '', $real_backup_file);
$rel_path = str_replace($storage_base . '\\', '', $rel_path);

$entry = [
    'file'       => $rel_path,
    'database'   => $db_name,
    'year'       => $year,
    'month'      => $month,
    'timestamp'  => date('Y-m-d_His'),
    'created_at' => date('Y-m-d H:i:s'),
    'tables'     => $tables,
    'table_rows' => $table_rows,
    'total_rows' => $total_rows,
    'file_size'  => $file_size,
    'size_human' => fmt_size($file_size),
    'checksum'   => md5_file($backup_file)
];

$index[] = $entry;

$tmp = $index_file . '.tmp';
file_put_contents($tmp, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
rename($tmp, $index_file);

echo "Index updated: " . count($tables) . " tables, ~{$total_rows} rows, " . fmt_size($file_size) . "\n";
