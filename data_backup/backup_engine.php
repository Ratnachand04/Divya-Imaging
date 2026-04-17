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
    $argv_list = isset($argv) && is_array($argv) ? $argv : [];
    $force_backup = in_array('--force', $argv_list, true) || in_array('-f', $argv_list, true);
    $result = run_monthly_backup($conn, $force_backup);
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

    // Mirror the split init SQL bundle snapshot alongside this backup.
    $mirror_rel = mirror_sql_bundle_snapshot($filepath);

    // Get file size
    $file_size = filesize($filepath);

    // Update the JSON index for fast searching
    update_backup_index($filepath, $db_name, $year, $month, $timestamp, $tables, $total_rows, $file_size, $table_info, $mirror_rel);

    $size_str = format_file_size($file_size);
    $mirror_msg = $mirror_rel ? ", mirrored SQL bundle: {$mirror_rel}" : '';
    return [
        'success' => true,
        'message' => "Backup created: {$year}/{$month}/{$filename} ({$size_str}, {$total_rows} rows, " . count($tables) . " tables){$mirror_msg}",
        'file'    => $filepath,
        'mirror_bundle' => $mirror_rel,
        'size'    => $file_size,
        'rows'    => $total_rows,
        'tables'  => count($tables)
    ];
}

/**
 * Normalize a path to a dump/backup-relative forward-slash path.
 *
 * @param string $storage_base Absolute or relative dump/backup root path.
 * @param string $target_path Absolute, relative, or already-normalized target path.
 * @return string Normalized relative path (no leading slash).
 */
function backup_storage_relative_path($storage_base, $target_path) {
    $base = realpath($storage_base);
    if ($base === false) {
        $base = (string)$storage_base;
    }

    $target = realpath($target_path);
    if ($target === false) {
        $target = (string)$target_path;
    }

    $base_norm = str_replace('\\', '/', rtrim((string)$base, "\\/"));
    $target_norm = str_replace('\\', '/', (string)$target);

    if ($base_norm !== '' && strpos($target_norm, $base_norm . '/') === 0) {
        $target_norm = substr($target_norm, strlen($base_norm) + 1);
    }

    $target_norm = ltrim($target_norm, '/');
    if ($target_norm === '') {
        return '';
    }

    $segments = [];
    foreach (explode('/', $target_norm) as $segment) {
        $seg = trim($segment);
        if ($seg === '' || $seg === '.' || $seg === '..') {
            continue;
        }
        $segments[] = $seg;
    }

    return implode('/', $segments);
}

/**
 * Mirror split SQL init bundle next to backup SQL file.
 * Returns relative path from dump/backup or null on failure.
 */
function mirror_sql_bundle_snapshot($backup_file) {
    $storage_base = realpath(__DIR__ . '/../dump/backup');
    $init_source = realpath(__DIR__ . '/../dump/init');

    if (!$storage_base || !$init_source || !is_dir($init_source)) {
        return null;
    }

    $backup_dir = dirname($backup_file);
    $backup_name = pathinfo($backup_file, PATHINFO_FILENAME);
    $mirror_dir = $backup_dir . '/sql_bundle_' . $backup_name;
    $mirror_init = $mirror_dir . '/init';

    if (!is_dir($mirror_init) && !mkdir($mirror_init, 0775, true) && !is_dir($mirror_init)) {
        return null;
    }

    if (!copy_directory_recursive($init_source, $mirror_init)) {
        return null;
    }

    $rel_path = backup_storage_relative_path($storage_base, $mirror_dir);
    return $rel_path !== '' ? $rel_path : null;
}

/**
 * Recursively copy directory contents.
 */
function copy_directory_recursive($source, $destination) {
    if (!is_dir($source)) {
        return false;
    }

    if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
        return false;
    }

    $items = scandir($source);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $src_item = $source . '/' . $item;
        $dst_item = $destination . '/' . $item;

        if (is_dir($src_item)) {
            if (!copy_directory_recursive($src_item, $dst_item)) {
                return false;
            }
            continue;
        }

        if (!@copy($src_item, $dst_item)) {
            return false;
        }
    }

    return true;
}

/**
 * Update the central JSON index with metadata about this backup.
 * The index allows fast searching without scanning SQL files.
 */
function update_backup_index($filepath, $db_name, $year, $month, $timestamp, $tables, $total_rows, $file_size, $table_info, $mirror_bundle = null) {
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
    $rel_path = backup_storage_relative_path($storage_base, $filepath);
    if ($rel_path === '') {
        $rel_path = basename((string)$filepath);
    }

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

    if (is_string($mirror_bundle) && $mirror_bundle !== '') {
        $normalized_bundle = backup_storage_relative_path($storage_base, $mirror_bundle);
        if ($normalized_bundle === '') {
            $normalized_bundle = trim(str_replace('\\', '/', $mirror_bundle), '/');
        }
        if ($normalized_bundle !== '') {
            $entry['mirror_bundle'] = $normalized_bundle;
        }
    }

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
