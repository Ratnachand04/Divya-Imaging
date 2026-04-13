Bring stack up again and verify app boot normally.<?php
/**
 * =============================================================
 * Database Backup Engine - Diagnostic Center
 * =============================================================
 * Creates monthly SQL backups in: dump/backup/YEAR/MONTH/
 * Maintains a lightweight JSON index for fast searching.
 * Memory-efficient: streams large tables in batches.
 * =============================================================
 */

// Can be called from CLI or included from PHP
if (php_sapi_name() === 'cli') {
    // CLI mode: load DB connection
    require_once __DIR__ . '/../includes/db_connect.php';
    $result = run_monthly_backup($conn);
    echo $result['message'] . "\n";
    exit($result['success'] ? 0 : 1);
}

/**
 * Run a monthly backup of the entire database.
 * Saves to: dump/backup/YEAR/MONTH/backup_YYYY-MM-DD_HHiiss.sql
 * Uses streaming to avoid high memory usage on large tables.
 *
 * @param mysqli $conn  Active database connection
 * @param bool   $force Force backup even if one already exists for this month
 * @return array ['success' => bool, 'message' => string, 'file' => string|null]
 */
function run_monthly_backup($conn, $force = false) {
    $year  = date('Y');
    $month = date('m');
    $base  = __DIR__ . '/../dump/backup';

    if (!is_dir($base)) {
        mkdir($base, 0775, true);
    }

    // Create folder: dump/backup/YEAR/MONTH/
    $dir = "{$base}/{$year}/{$month}";
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    // Check if a backup already exists this month (unless forced)
    if (!$force) {
        $existing = glob("{$dir}/backup_*.sql");
        if (!empty($existing)) {
            return [
                'success' => true,
                'message' => "Backup already exists for {$year}/{$month}: " . basename(end($existing)),
                'file'    => end($existing),
                'skipped' => true
            ];
        }
    }

    $timestamp = date('Y-m-d_His');
    $filename  = "backup_{$timestamp}.sql";
    $filepath  = "{$dir}/{$filename}";

    // Get database name
    $db_name = $conn->query("SELECT DATABASE()")->fetch_row()[0];

    // Open file for writing (stream, not buffer in memory)
    $fh = fopen($filepath, 'w');
    if (!$fh) {
        return ['success' => false, 'message' => "Cannot create backup file: {$filepath}", 'file' => null];
    }

    // Write header
    fwrite($fh, "-- =============================================================\n");
    fwrite($fh, "-- Diagnostic Center - Monthly Database Backup\n");
    fwrite($fh, "-- Database: {$db_name}\n");
    fwrite($fh, "-- Date: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fh, "-- Period: {$year}-{$month}\n");
    fwrite($fh, "-- =============================================================\n\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($fh, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
    fwrite($fh, "SET NAMES utf8mb4;\n\n");

    // Get all tables
    $tables = [];
    $table_res = $conn->query("SHOW TABLES");
    if (!$table_res) {
        fclose($fh);
        unlink($filepath);
        return ['success' => false, 'message' => "Failed to list tables: " . $conn->error, 'file' => null];
    }
    while ($row = $table_res->fetch_row()) {
        $tables[] = $row[0];
    }

    $total_rows = 0;
    $table_info = [];

    foreach ($tables as $table) {
        // Write table structure
        fwrite($fh, "-- -----------------------------------------------\n");
        fwrite($fh, "-- Table: `{$table}`\n");
        fwrite($fh, "-- -----------------------------------------------\n");
        fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");

        $create_res = $conn->query("SHOW CREATE TABLE `{$table}`");
        if ($create_res) {
            $create_row = $create_res->fetch_row();
            fwrite($fh, $create_row[1] . ";\n\n");
        }

        // Count rows
        $count_res = $conn->query("SELECT COUNT(*) FROM `{$table}`");
        $row_count = $count_res ? (int)$count_res->fetch_row()[0] : 0;
        $total_rows += $row_count;
        $table_info[$table] = $row_count;

        if ($row_count === 0) {
            fwrite($fh, "-- (empty table)\n\n");
            continue;
        }

        // Stream data in batches of 500 rows to limit memory usage
        $batch_size = 500;
        $offset = 0;

        // Get column names once
        $fields_res = $conn->query("SELECT * FROM `{$table}` LIMIT 0");
        $fields = $fields_res->fetch_fields();
        $col_names = array_map(fn($f) => "`{$f->name}`", $fields);
        $col_list = implode(', ', $col_names);

        fwrite($fh, "LOCK TABLES `{$table}` WRITE;\n");

        while ($offset < $row_count) {
            $data_res = $conn->query("SELECT * FROM `{$table}` LIMIT {$batch_size} OFFSET {$offset}");
            if (!$data_res || $data_res->num_rows === 0) break;

            fwrite($fh, "INSERT INTO `{$table}` ({$col_list}) VALUES\n");

            $first = true;
            while ($row = $data_res->fetch_row()) {
                $vals = [];
                foreach ($row as $val) {
                    $vals[] = ($val === null) ? 'NULL' : "'" . $conn->real_escape_string($val) . "'";
                }
                $line = '(' . implode(', ', $vals) . ')';
                if (!$first) {
                    fwrite($fh, ",\n");
                }
                fwrite($fh, $line);
                $first = false;
            }
            fwrite($fh, ";\n");

            $data_res->free();
            $offset += $batch_size;
        }

        fwrite($fh, "UNLOCK TABLES;\n\n");
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fwrite($fh, "-- End of backup\n");
    fclose($fh);

    // Get file size
    $file_size = filesize($filepath);

    // Update the JSON index for fast searching
    update_backup_index($filepath, $db_name, $year, $month, $timestamp, $tables, $total_rows, $file_size, $table_info);

    $size_str = format_file_size($file_size);
    return [
        'success' => true,
        'message' => "Backup created: {$year}/{$month}/{$filename} ({$size_str}, {$total_rows} rows, " . count($tables) . " tables)",
        'file'    => $filepath,
        'size'    => $file_size,
        'rows'    => $total_rows,
        'tables'  => count($tables)
    ];
}

/**
 * Update the central JSON index with metadata about this backup.
 * The index allows fast searching without scanning SQL files.
 */
function update_backup_index($filepath, $db_name, $year, $month, $timestamp, $tables, $total_rows, $file_size, $table_info) {
    $storage_base = __DIR__ . '/../dump/backup';
    if (!is_dir($storage_base)) {
        mkdir($storage_base, 0775, true);
    }
    $index_file = $storage_base . '/backup_index.json';

    // Load existing index
    $index = [];
    if (file_exists($index_file)) {
        $raw = file_get_contents($index_file);
        $index = json_decode($raw, true) ?: [];
    }

    // Build relative path for portability
    $rel_path = str_replace($storage_base . '/', '', $filepath);
    $rel_path = str_replace($storage_base . '\\', '', $rel_path);

    // Add entry
    $entry = [
        'file'       => $rel_path,
        'database'   => $db_name,
        'year'       => $year,
        'month'      => $month,
        'timestamp'  => $timestamp,
        'created_at' => date('Y-m-d H:i:s'),
        'tables'     => $tables,
        'table_rows' => $table_info,
        'total_rows' => $total_rows,
        'file_size'  => $file_size,
        'size_human' => format_file_size($file_size),
        'checksum'   => md5_file($filepath)
    ];

    $index[] = $entry;

    // Write index back (atomic write with temp file)
    $tmp = $index_file . '.tmp';
    file_put_contents($tmp, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $index_file);
}

/**
 * Format bytes into human-readable size string
 */
function format_file_size($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}
