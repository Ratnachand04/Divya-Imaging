<?php
/**
 * =============================================================
 * Backup Search Engine - Diagnostic Center
 * =============================================================
 * Fast search across stored SQL backups using the JSON index.
 * - Searches metadata (index) first → instant results, zero I/O
 * - Optional deep search: streams through SQL files line-by-line
 *   without loading entire files into memory
 * =============================================================
 */

/**
 * Search backup index (fast metadata search - no file I/O).
 * Filters by year, month, table name, date range, etc.
 *
 * @param array $filters  [year, month, table, date_from, date_to, keyword]
 * @return array  Matching backup entries from the index
 */
function search_backup_index($filters = []) {
    $index_file = __DIR__ . '/../dump/backup/backup_index.json';
    if (!file_exists($index_file)) {
        return [];
    }

    $index = json_decode(file_get_contents($index_file), true) ?: [];

    $results = [];
    foreach ($index as $entry) {
        // Filter by year
        if (!empty($filters['year']) && $entry['year'] != $filters['year']) continue;

        // Filter by month
        if (!empty($filters['month']) && $entry['month'] != $filters['month']) continue;

        // Filter by table name
        if (!empty($filters['table'])) {
            $found = false;
            foreach ($entry['tables'] as $t) {
                if (stripos($t, $filters['table']) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) continue;
        }

        // Filter by date range
        if (!empty($filters['date_from'])) {
            $backup_date = substr($entry['timestamp'], 0, 10); // YYYY-MM-DD
            if ($backup_date < $filters['date_from']) continue;
        }
        if (!empty($filters['date_to'])) {
            $backup_date = substr($entry['timestamp'], 0, 10);
            if ($backup_date > $filters['date_to']) continue;
        }

        // Filter by keyword in filename or database name
        if (!empty($filters['keyword'])) {
            $kw = strtolower($filters['keyword']);
            $haystack = strtolower($entry['file'] . ' ' . $entry['database'] . ' ' . implode(' ', $entry['tables']));
            if (strpos($haystack, $kw) === false) continue;
        }

        $results[] = $entry;
    }

    // Sort by created_at descending (newest first)
    usort($results, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

    return $results;
}

/**
 * Deep search: search inside SQL backup files for a specific string.
 * Memory-efficient: reads line-by-line, never loads entire file.
 * Returns matching lines with context.
 *
 * @param string $search_term  The string to search for
 * @param array  $filters      Same filters as search_backup_index (narrows files first)
 * @param int    $max_results  Maximum matching lines to return (prevents runaway)
 * @param int    $context_lines Number of context lines before/after match
 * @return array  ['results' => [...], 'files_searched' => int, 'total_matches' => int]
 */
function deep_search_backups($search_term, $filters = [], $max_results = 50, $context_lines = 2) {
    // First narrow down which files to search using the index
    $entries = search_backup_index($filters);

    if (empty($entries)) {
        return ['results' => [], 'files_searched' => 0, 'total_matches' => 0];
    }

    $results = [];
    $total_matches = 0;
    $files_searched = 0;
    $base = __DIR__ . '/../dump/backup';

    foreach ($entries as $entry) {
        $file = $base . '/' . $entry['file'];
        if (!file_exists($file)) continue;

        $files_searched++;
        $matches = stream_search_file($file, $search_term, $max_results - count($results), $context_lines);

        foreach ($matches as $match) {
            $match['backup_file'] = $entry['file'];
            $match['backup_date'] = $entry['created_at'];
            $match['backup_year'] = $entry['year'];
            $match['backup_month'] = $entry['month'];
            $results[] = $match;
            $total_matches++;
        }

        if (count($results) >= $max_results) break;
    }

    return [
        'results'        => $results,
        'files_searched' => $files_searched,
        'total_matches'  => $total_matches
    ];
}

/**
 * Stream-search a single file line by line (memory efficient).
 * Uses a circular buffer for context lines.
 *
 * @param string $filepath       Full path to the SQL file
 * @param string $search_term    String to find
 * @param int    $max_matches    Stop after this many matches
 * @param int    $context_lines  Lines of context before/after
 * @return array  Array of matches with line number and context
 */
function stream_search_file($filepath, $search_term, $max_matches = 50, $context_lines = 2) {
    $fh = fopen($filepath, 'r');
    if (!$fh) return [];

    $matches = [];
    $line_num = 0;
    $search_lower = strtolower($search_term);

    // Circular buffer for "before" context
    $buffer = [];
    $buffer_size = $context_lines;

    // Track lines after a match for "after" context
    $pending_after = [];

    while (($line = fgets($fh)) !== false) {
        $line_num++;
        $line_trimmed = rtrim($line);

        // Check for pending "after" context
        foreach ($pending_after as $idx => &$pending) {
            if ($pending['remaining'] > 0) {
                $pending['after'][] = $line_trimmed;
                $pending['remaining']--;
            }
        }
        // Remove fulfilled pending items
        $pending_after = array_filter($pending_after, fn($p) => $p['remaining'] > 0);

        // Check if this line matches
        if (stripos($line_trimmed, $search_lower) !== false) {
            $match = [
                'line_number' => $line_num,
                'line'        => mb_substr($line_trimmed, 0, 500), // Truncate very long lines
                'before'      => array_values($buffer),
                'after'       => [],
                'remaining'   => $context_lines
            ];
            $matches[] = &$match;
            $pending_after[] = &$match;
            unset($match);

            if (count($matches) >= $max_matches) break;
        }

        // Update circular buffer
        $buffer[] = $line_trimmed;
        if (count($buffer) > $buffer_size) {
            array_shift($buffer);
        }
    }

    // Read remaining "after" context lines
    foreach ($pending_after as &$pending) {
        while ($pending['remaining'] > 0 && ($line = fgets($fh)) !== false) {
            $pending['after'][] = rtrim($line);
            $pending['remaining']--;
        }
    }

    fclose($fh);

    // Clean up internal tracking fields
    foreach ($matches as &$m) {
        unset($m['remaining']);
    }

    return $matches;
}

/**
 * Get summary statistics of all backups (from index).
 * @return array
 */
function get_backup_stats() {
    $index_file = __DIR__ . '/../dump/backup/backup_index.json';
    if (!file_exists($index_file)) {
        return [
            'total_backups' => 0,
            'total_size'    => 0,
            'years'         => [],
            'latest'        => null
        ];
    }

    $index = json_decode(file_get_contents($index_file), true) ?: [];

    $total_size = 0;
    $years = [];
    $latest = null;

    foreach ($index as $entry) {
        $total_size += $entry['file_size'] ?? 0;
        $y = $entry['year'];
        $m = $entry['month'];
        if (!isset($years[$y])) $years[$y] = [];
        if (!in_array($m, $years[$y])) $years[$y][] = $m;

        if (!$latest || $entry['created_at'] > $latest['created_at']) {
            $latest = $entry;
        }
    }

    // Sort years and months
    ksort($years);
    foreach ($years as &$months) sort($months);

    return [
        'total_backups' => count($index),
        'total_size'    => $total_size,
        'size_human'    => format_backup_size($total_size),
        'years'         => $years,
        'latest'        => $latest
    ];
}

/**
 * List all backups for a specific year/month from the filesystem.
 * Falls back to directory scan if index is missing.
 */
function list_backups($year = null, $month = null) {
    $base = __DIR__ . '/../dump/backup';

    // Try index first (fast)
    $index_file = $base . '/backup_index.json';
    if (file_exists($index_file)) {
        return search_backup_index(['year' => $year, 'month' => $month]);
    }

    // Fallback: scan directories
    $results = [];
    $scan_path = $base;
    if ($year) $scan_path .= "/{$year}";
    if ($month) $scan_path .= "/{$month}";

    if (!is_dir($scan_path)) return [];

    $pattern = $scan_path . (($year && $month) ? '/backup_*.sql' : '/**/backup_*.sql');
    foreach (glob($pattern) as $file) {
        $results[] = [
            'file'       => str_replace($base . '/', '', $file),
            'file_size'  => filesize($file),
            'size_human' => format_backup_size(filesize($file)),
            'created_at' => date('Y-m-d H:i:s', filemtime($file))
        ];
    }

    return $results;
}

/**
 * Delete a specific backup file and remove it from the index.
 */
function delete_backup($rel_path) {
    $base = __DIR__ . '/../dump/backup';
    $full_path = $base . '/' . $rel_path;

    if (!file_exists($full_path)) {
        return ['success' => false, 'message' => 'File not found'];
    }

    // Security: ensure path is within dump/backup
    $real = realpath($full_path);
    $base_real = realpath($base);
    if (strpos($real, $base_real) !== 0) {
        return ['success' => false, 'message' => 'Invalid path'];
    }

    unlink($full_path);

    // Remove from index
    $index_file = $base . '/backup_index.json';
    if (file_exists($index_file)) {
        $index = json_decode(file_get_contents($index_file), true) ?: [];
        $index = array_values(array_filter($index, fn($e) => $e['file'] !== $rel_path));
        file_put_contents($index_file, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return ['success' => true, 'message' => 'Backup deleted'];
}

function format_backup_size($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}
