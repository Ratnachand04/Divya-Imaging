<?php
require_once 'includes/header.php';

// ---- Helper: Check dev mode ----
function isDevModeOn($conn) {
    $res = $conn->query("SELECT setting_value FROM developer_settings WHERE setting_key='developer_mode'");
    return $res && ($res->fetch_row()[0] ?? 'false') === 'true';
}

// ---- AJAX Handler ----
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        
        // ---- Run SQL Query ----
        case 'run_sql':
            $sql = trim($_POST['sql'] ?? '');
            if (empty($sql)) {
                echo json_encode(['success' => false, 'error' => 'Empty query']);
                exit;
            }
            
            // Check if it's a write query and dev mode is off
            $is_write = preg_match('/^\s*(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|RENAME|REPLACE)/i', $sql);
            if ($is_write && !isDevModeOn($conn)) {
                echo json_encode(['success' => false, 'error' => 'Developer mode must be ON for write operations. Enable it from Developer Mode page.']);
                exit;
            }
            
            // Log in audit
            $safe_sql = $conn->real_escape_string(substr($sql, 0, 500));
            $conn->query("INSERT INTO system_audit_log (user_id, action, details, ip_address) VALUES (
                {$_SESSION['user_id']}, 'sql_execute', 'SQL: {$safe_sql}', '{$_SERVER['REMOTE_ADDR']}'
            )");
            
            $results = [];
            $affected = 0;
            try {
                if ($conn->multi_query($sql)) {
                    do {
                        if ($result = $conn->store_result()) {
                            $fields = [];
                            foreach ($result->fetch_fields() as $f) {
                                $fields[] = $f->name;
                            }
                            $rows = [];
                            while ($row = $result->fetch_assoc()) {
                                $rows[] = $row;
                            }
                            $results[] = ['fields' => $fields, 'rows' => $rows, 'count' => count($rows)];
                            $result->free();
                        } else {
                            $affected += $conn->affected_rows;
                        }
                    } while ($conn->next_result());
                    
                    echo json_encode([
                        'success' => true,
                        'results' => $results,
                        'affected_rows' => $affected,
                        'message' => count($results) > 0 
                            ? count($results) . ' result set(s) returned' 
                            : $affected . ' row(s) affected'
                    ]);
                } else {
                    echo json_encode(['success' => false, 'error' => $conn->error]);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        
        // ---- List all tables with stats ----
        case 'list_tables':
            $tables = [];
            $res = $conn->query("SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, ENGINE, TABLE_COLLATION, AUTO_INCREMENT, CREATE_TIME, UPDATE_TIME 
                FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $tables[] = [
                        'name' => $row['TABLE_NAME'],
                        'rows' => (int)$row['TABLE_ROWS'],
                        'data_size' => (int)$row['DATA_LENGTH'],
                        'index_size' => (int)$row['INDEX_LENGTH'],
                        'total_size' => (int)$row['DATA_LENGTH'] + (int)$row['INDEX_LENGTH'],
                        'engine' => $row['ENGINE'],
                        'collation' => $row['TABLE_COLLATION'],
                        'auto_increment' => $row['AUTO_INCREMENT'],
                        'created' => $row['CREATE_TIME'],
                        'updated' => $row['UPDATE_TIME']
                    ];
                }
            }
            
            // Database stats
            $db_res = $conn->query("SELECT SUM(DATA_LENGTH) as data_size, SUM(INDEX_LENGTH) as index_size, COUNT(*) as table_count 
                FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
            $db_stats = $db_res ? $db_res->fetch_assoc() : [];
            
            echo json_encode([
                'success' => true,
                'tables' => $tables,
                'db_name' => $conn->query("SELECT DATABASE()")->fetch_row()[0],
                'db_stats' => $db_stats
            ]);
            exit;
        
        // ---- Browse table with pagination ----
        case 'browse_table':
            $table = $conn->real_escape_string($_POST['table'] ?? '');
            $page = max(1, (int)($_POST['page'] ?? 1));
            $per_page = min(200, max(10, (int)($_POST['per_page'] ?? 50)));
            $sort_col = $_POST['sort_col'] ?? '';
            $sort_dir = strtoupper($_POST['sort_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
            $search = trim($_POST['search'] ?? '');
            
            if (empty($table)) {
                echo json_encode(['success' => false, 'error' => 'No table specified']);
                exit;
            }
            
            // Get columns
            $columns = [];
            $pk_col = null;
            $col_res = $conn->query("SHOW COLUMNS FROM `$table`");
            if ($col_res) {
                while ($c = $col_res->fetch_assoc()) {
                    $columns[] = $c;
                    if ($c['Key'] === 'PRI' && !$pk_col) $pk_col = $c['Field'];
                }
            }
            
            // Build WHERE clause for search
            $where = '';
            if (!empty($search)) {
                $safe_search = $conn->real_escape_string($search);
                $clauses = [];
                foreach ($columns as $c) {
                    $clauses[] = "`{$c['Field']}` LIKE '%{$safe_search}%'";
                }
                $where = 'WHERE ' . implode(' OR ', $clauses);
            }
            
            // Count total
            $count_res = $conn->query("SELECT COUNT(*) FROM `$table` $where");
            $total = $count_res ? (int)$count_res->fetch_row()[0] : 0;
            
            // Build ORDER BY
            $order = '';
            if (!empty($sort_col)) {
                $safe_col = $conn->real_escape_string($sort_col);
                $order = "ORDER BY `$safe_col` $sort_dir";
            } elseif ($pk_col) {
                $order = "ORDER BY `$pk_col` ASC";
            }
            
            // Fetch rows
            $offset = ($page - 1) * $per_page;
            $rows = [];
            $result = $conn->query("SELECT * FROM `$table` $where $order LIMIT $per_page OFFSET $offset");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
            }
            
            echo json_encode([
                'success' => true,
                'table' => $table,
                'columns' => $columns,
                'pk_col' => $pk_col,
                'rows' => $rows,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page)
            ]);
            exit;
        
        // ---- View table structure ----
        case 'view_structure':
            $table = $conn->real_escape_string($_POST['table'] ?? '');
            
            $columns = [];
            $res = $conn->query("SHOW FULL COLUMNS FROM `$table`");
            if ($res) {
                while ($row = $res->fetch_assoc()) $columns[] = $row;
            }
            
            $indexes = [];
            $idx_res = $conn->query("SHOW INDEX FROM `$table`");
            if ($idx_res) {
                while ($row = $idx_res->fetch_assoc()) $indexes[] = $row;
            }
            
            $create_sql = '';
            $create_res = $conn->query("SHOW CREATE TABLE `$table`");
            if ($create_res) {
                $row = $create_res->fetch_row();
                $create_sql = $row[1] ?? '';
            }
            
            echo json_encode([
                'success' => true,
                'table' => $table,
                'columns' => $columns,
                'indexes' => $indexes,
                'create_sql' => $create_sql
            ]);
            exit;
        
        // ---- Insert row ----
        case 'insert_row':
            if (!isDevModeOn($conn)) {
                echo json_encode(['success' => false, 'error' => 'Developer mode must be ON to insert rows']);
                exit;
            }
            
            $table = $conn->real_escape_string($_POST['table'] ?? '');
            $data = json_decode($_POST['row_data'] ?? '{}', true);
            
            if (empty($table) || empty($data)) {
                echo json_encode(['success' => false, 'error' => 'Missing table or data']);
                exit;
            }
            
            $cols = [];
            $vals = [];
            $types = '';
            $bind_vals = [];
            foreach ($data as $col => $val) {
                if ($val === '' || $val === null) continue;
                $cols[] = "`" . $conn->real_escape_string($col) . "`";
                $vals[] = '?';
                $types .= 's';
                $bind_vals[] = $val;
            }
            
            if (empty($cols)) {
                echo json_encode(['success' => false, 'error' => 'No data provided']);
                exit;
            }
            
            $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$bind_vals);
                if ($stmt->execute()) {
                    $conn->query("INSERT INTO system_audit_log (user_id, action, details, ip_address) VALUES (
                        {$_SESSION['user_id']}, 'db_insert', 'Inserted row into: $table', '{$_SERVER['REMOTE_ADDR']}'
                    )");
                    echo json_encode(['success' => true, 'insert_id' => $stmt->insert_id]);
                } else {
                    echo json_encode(['success' => false, 'error' => $stmt->error]);
                }
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
            exit;
        
        // ---- Update row ----
        case 'update_row':
            if (!isDevModeOn($conn)) {
                echo json_encode(['success' => false, 'error' => 'Developer mode must be ON to update rows']);
                exit;
            }
            
            $table = $conn->real_escape_string($_POST['table'] ?? '');
            $pk_col = $conn->real_escape_string($_POST['pk_col'] ?? '');
            $pk_val = $_POST['pk_val'] ?? '';
            $col = $conn->real_escape_string($_POST['column'] ?? '');
            $val = $_POST['value'] ?? '';
            
            if (empty($table) || empty($pk_col) || empty($col)) {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                exit;
            }
            
            $stmt = $conn->prepare("UPDATE `$table` SET `$col` = ? WHERE `$pk_col` = ? LIMIT 1");
            $stmt->bind_param("ss", $val, $pk_val);
            if ($stmt->execute()) {
                $conn->query("INSERT INTO system_audit_log (user_id, action, details, ip_address) VALUES (
                    {$_SESSION['user_id']}, 'db_update', 'Updated $table.$col where $pk_col=$pk_val', '{$_SERVER['REMOTE_ADDR']}'
                )");
                echo json_encode(['success' => true, 'affected' => $stmt->affected_rows]);
            } else {
                echo json_encode(['success' => false, 'error' => $stmt->error]);
            }
            exit;
        
        // ---- Delete row ----
        case 'delete_row':
            if (!isDevModeOn($conn)) {
                echo json_encode(['success' => false, 'error' => 'Developer mode must be ON to delete rows']);
                exit;
            }
            
            $table = $conn->real_escape_string($_POST['table'] ?? '');
            $pk_col = $conn->real_escape_string($_POST['pk_col'] ?? '');
            $pk_val = $_POST['pk_val'] ?? '';
            
            $stmt = $conn->prepare("DELETE FROM `$table` WHERE `$pk_col` = ? LIMIT 1");
            $stmt->bind_param("s", $pk_val);
            if ($stmt->execute()) {
                $conn->query("INSERT INTO system_audit_log (user_id, action, details, ip_address) VALUES (
                    {$_SESSION['user_id']}, 'db_delete', 'Deleted from $table where $pk_col=$pk_val', '{$_SERVER['REMOTE_ADDR']}'
                )");
                echo json_encode(['success' => true, 'affected' => $stmt->affected_rows]);
            } else {
                echo json_encode(['success' => false, 'error' => $stmt->error]);
            }
            exit;
        
        // ---- Truncate table ----
        case 'truncate_table':
            if (!isDevModeOn($conn)) {
                echo json_encode(['success' => false, 'error' => 'Developer mode must be ON for destructive operations']);
                exit;
            }
            
            $table = $conn->real_escape_string($_POST['table'] ?? '');
            if ($conn->query("TRUNCATE TABLE `$table`")) {
                $conn->query("INSERT INTO system_audit_log (user_id, action, details, ip_address) VALUES (
                    {$_SESSION['user_id']}, 'db_truncate', 'Truncated: $table', '{$_SERVER['REMOTE_ADDR']}'
                )");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
            exit;
        
        // ---- Drop table ----
        case 'drop_table':
            if (!isDevModeOn($conn)) {
                echo json_encode(['success' => false, 'error' => 'Developer mode must be ON for destructive operations']);
                exit;
            }
            
            $table = $conn->real_escape_string($_POST['table'] ?? '');
            // Prevent dropping critical tables
            $protected = ['users', 'developer_settings', 'system_audit_log'];
            if (in_array($table, $protected)) {
                echo json_encode(['success' => false, 'error' => "Cannot drop protected system table: $table"]);
                exit;
            }
            
            if ($conn->query("DROP TABLE IF EXISTS `$table`")) {
                $conn->query("INSERT INTO system_audit_log (user_id, action, details, ip_address) VALUES (
                    {$_SESSION['user_id']}, 'db_drop', 'Dropped table: $table', '{$_SERVER['REMOTE_ADDR']}'
                )");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
            exit;
        
        // ---- Export table to SQL ----
        case 'export_table':
            $table = $conn->real_escape_string($_POST['table'] ?? '');
            $include_data = ($_POST['include_data'] ?? 'true') === 'true';
            
            $output = "-- Export of `$table` from " . date('Y-m-d H:i:s') . "\n";
            $output .= "-- Database: " . $conn->query("SELECT DATABASE()")->fetch_row()[0] . "\n\n";
            
            // Get CREATE TABLE
            $create_res = $conn->query("SHOW CREATE TABLE `$table`");
            if ($create_res) {
                $row = $create_res->fetch_row();
                $output .= "DROP TABLE IF EXISTS `$table`;\n";
                $output .= $row[1] . ";\n\n";
            }
            
            if ($include_data) {
                $data_res = $conn->query("SELECT * FROM `$table`");
                if ($data_res && $data_res->num_rows > 0) {
                    $fields = $data_res->fetch_fields();
                    $col_names = array_map(fn($f) => "`{$f->name}`", $fields);
                    
                    $output .= "LOCK TABLES `$table` WRITE;\n";
                    $output .= "INSERT INTO `$table` (" . implode(', ', $col_names) . ") VALUES\n";
                    
                    $row_strs = [];
                    while ($row = $data_res->fetch_row()) {
                        $vals = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $vals[] = 'NULL';
                            } else {
                                $vals[] = "'" . $conn->real_escape_string($val) . "'";
                            }
                        }
                        $row_strs[] = '(' . implode(', ', $vals) . ')';
                    }
                    $output .= implode(",\n", $row_strs) . ";\n";
                    $output .= "UNLOCK TABLES;\n";
                }
            }
            
            echo json_encode(['success' => true, 'sql' => $output, 'filename' => "{$table}_export_" . date('Y-m-d_His') . ".sql"]);
            exit;
        
        // ---- Export entire database ----
        case 'export_database':
            $include_data = ($_POST['include_data'] ?? 'true') === 'true';
            $db_name = $conn->query("SELECT DATABASE()")->fetch_row()[0];
            
            $output = "-- Full Database Export: $db_name\n";
            $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $output .= "-- Ghost Developer Console\n\n";
            $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
            
            $tables_res = $conn->query("SHOW TABLES");
            while ($t = $tables_res->fetch_row()) {
                $tbl = $t[0];
                $create_res = $conn->query("SHOW CREATE TABLE `$tbl`");
                if ($create_res) {
                    $row = $create_res->fetch_row();
                    $output .= "-- Table: $tbl\n";
                    $output .= "DROP TABLE IF EXISTS `$tbl`;\n";
                    $output .= $row[1] . ";\n\n";
                }
                
                if ($include_data) {
                    $data_res = $conn->query("SELECT * FROM `$tbl`");
                    if ($data_res && $data_res->num_rows > 0) {
                        $fields = $data_res->fetch_fields();
                        $col_names = array_map(fn($f) => "`{$f->name}`", $fields);
                        
                        $output .= "INSERT INTO `$tbl` (" . implode(', ', $col_names) . ") VALUES\n";
                        $row_strs = [];
                        while ($row = $data_res->fetch_row()) {
                            $vals = [];
                            foreach ($row as $val) {
                                $vals[] = ($val === null) ? 'NULL' : "'" . $conn->real_escape_string($val) . "'";
                            }
                            $row_strs[] = '(' . implode(', ', $vals) . ')';
                        }
                        $output .= implode(",\n", $row_strs) . ";\n\n";
                    }
                }
            }
            
            $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
            
            echo json_encode(['success' => true, 'sql' => $output, 'filename' => "{$db_name}_full_export_" . date('Y-m-d_His') . ".sql"]);
            exit;
        
        // ---- Import SQL ----
        case 'import_sql':
            if (!isDevModeOn($conn)) {
                echo json_encode(['success' => false, 'error' => 'Developer mode must be ON to import SQL']);
                exit;
            }
            
            $sql = $_POST['sql_content'] ?? '';
            if (empty(trim($sql))) {
                echo json_encode(['success' => false, 'error' => 'No SQL content provided']);
                exit;
            }
            
            try {
                $conn->query("SET FOREIGN_KEY_CHECKS=0");
                
                if ($conn->multi_query($sql)) {
                    $errors = [];
                    do {
                        if ($conn->error) $errors[] = $conn->error;
                        if ($result = $conn->store_result()) $result->free();
                    } while ($conn->next_result());
                    
                    $conn->query("SET FOREIGN_KEY_CHECKS=1");
                    
                    $conn->query("INSERT INTO system_audit_log (user_id, action, details, ip_address) VALUES (
                        {$_SESSION['user_id']}, 'db_import', 'SQL import executed', '{$_SERVER['REMOTE_ADDR']}'
                    )");
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'SQL import completed' . (count($errors) ? ' with ' . count($errors) . ' warning(s)' : ''),
                        'errors' => $errors
                    ]);
                } else {
                    $conn->query("SET FOREIGN_KEY_CHECKS=1");
                    echo json_encode(['success' => false, 'error' => $conn->error]);
                }
            } catch (Exception $e) {
                $conn->query("SET FOREIGN_KEY_CHECKS=1");
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        
        // ---- Create table ----
        case 'create_table':
            if (!isDevModeOn($conn)) {
                echo json_encode(['success' => false, 'error' => 'Developer mode must be ON to create tables']);
                exit;
            }
            
            $table_name = $conn->real_escape_string($_POST['table_name'] ?? '');
            $columns_json = json_decode($_POST['columns'] ?? '[]', true);
            
            if (empty($table_name) || empty($columns_json)) {
                echo json_encode(['success' => false, 'error' => 'Table name and at least one column required']);
                exit;
            }
            
            $col_defs = [];
            $pk_cols = [];
            foreach ($columns_json as $c) {
                $def = "`{$c['name']}` {$c['type']}";
                if (!empty($c['length'])) $def .= "({$c['length']})";
                if (($c['nullable'] ?? 'YES') === 'NO') $def .= ' NOT NULL';
                if (isset($c['default']) && $c['default'] !== '') $def .= " DEFAULT '{$c['default']}'";
                if (!empty($c['auto_increment'])) {
                    $def .= ' AUTO_INCREMENT';
                    $pk_cols[] = "`{$c['name']}`";
                }
                if (!empty($c['primary'])) $pk_cols[] = "`{$c['name']}`";
                $col_defs[] = $def;
            }
            
            $pk_cols = array_unique($pk_cols);
            if (!empty($pk_cols)) {
                $col_defs[] = "PRIMARY KEY (" . implode(',', $pk_cols) . ")";
            }
            
            $sql = "CREATE TABLE `$table_name` (\n  " . implode(",\n  ", $col_defs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            if ($conn->query($sql)) {
                $conn->query("INSERT INTO system_audit_log (user_id, action, details, ip_address) VALUES (
                    {$_SESSION['user_id']}, 'db_create_table', 'Created table: $table_name', '{$_SERVER['REMOTE_ADDR']}'
                )");
                echo json_encode(['success' => true, 'sql' => $sql]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error, 'sql' => $sql]);
            }
            exit;
    }
    exit;
}

// ---- Check dev mode for UI ----
$dev_mode = isDevModeOn($conn);
?>

<!-- Database Manager UI - Full AJAX -->
<style>
.db-hero {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    border-radius: 1rem;
    padding: 1.5rem 2rem;
    color: white;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 2rem;
}
.db-hero .stats {
    display: flex;
    gap: 2rem;
    flex: 1;
}
.db-hero .stat-box {
    text-align: center;
}
.db-hero .stat-box .num {
    font-size: 1.8rem;
    font-weight: 700;
    line-height: 1;
}
.db-hero .stat-box .label {
    font-size: 0.75rem;
    opacity: 0.7;
    margin-top: 0.25rem;
}
.db-actions-bar {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

/* SQL Editor */
.sql-editor-wrap {
    position: relative;
    border: 2px solid var(--border-color);
    border-radius: 0.75rem;
    overflow: hidden;
    margin-bottom: 1rem;
}
.sql-editor-wrap textarea {
    width: 100%;
    min-height: 120px;
    border: none;
    padding: 1rem;
    font-family: 'Fira Code', 'Cascadia Code', monospace;
    font-size: 0.9rem;
    line-height: 1.5;
    background: #1e1e1e;
    color: #d4d4d4;
    resize: vertical;
    outline: none;
    tab-size: 4;
}
.sql-editor-wrap textarea:focus {
    border-color: var(--primary);
}
.sql-toolbar {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: #2d2d2d;
    border-top: 1px solid #404040;
}

/* Table browser */
.table-browser {
    overflow-x: auto;
    max-height: 70vh;
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
}
.table-browser table {
    border-collapse: collapse;
    width: 100%;
    font-size: 0.85rem;
}
.table-browser th {
    position: sticky;
    top: 0;
    background: #f1f5f9;
    padding: 0.5rem 0.75rem;
    text-align: left;
    border-bottom: 2px solid var(--border-color);
    cursor: pointer;
    white-space: nowrap;
    user-select: none;
    z-index: 5;
}
.table-browser th:hover { background: #e2e8f0; }
.table-browser th .sort-icon { margin-left: 4px; opacity: 0.4; }
.table-browser th.sorted .sort-icon { opacity: 1; color: var(--primary); }
.table-browser td {
    padding: 0.4rem 0.75rem;
    border-bottom: 1px solid #f1f5f9;
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.table-browser tr:hover td { background: #f8fafc; }
.table-browser td.editable { cursor: pointer; }
.table-browser td.editable:hover { background: #dbeafe; outline: 1px dashed var(--primary); }
.table-browser td.editing {
    padding: 2px;
    background: white;
}
.table-browser td.editing input {
    width: 100%;
    border: 2px solid var(--primary);
    padding: 0.3rem 0.5rem;
    font-size: 0.85rem;
    border-radius: 4px;
    outline: none;
    font-family: monospace;
}
.table-browser .row-actions {
    white-space: nowrap;
    width: 80px;
}
.table-browser .row-actions button {
    background: none;
    border: none;
    cursor: pointer;
    padding: 2px 4px;
    border-radius: 3px;
    font-size: 0.8rem;
}
.table-browser .row-actions button:hover { background: rgba(0,0,0,0.1); }

/* Tabs */
.db-tabs {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--border-color);
    margin-bottom: 1rem;
}
.db-tab {
    padding: 0.6rem 1.2rem;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    font-weight: 500;
    color: var(--text-muted);
    transition: all 0.2s;
    font-size: 0.9rem;
}
.db-tab:hover { color: var(--text-main); background: #f8fafc; }
.db-tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    font-weight: 600;
}

/* Pagination */
.pagination {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 0;
    justify-content: space-between;
}
.pagination .page-info { color: var(--text-muted); font-size: 0.85rem; }
.pagination .page-btns { display: flex; gap: 4px; }
.pagination .page-btn {
    padding: 4px 10px;
    border: 1px solid var(--border-color);
    background: white;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.8rem;
}
.pagination .page-btn:hover { background: #f1f5f9; }
.pagination .page-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
.pagination .page-btn:disabled { opacity: 0.4; cursor: default; }

/* Table list items */
.tbl-list-item {
    display: flex;
    align-items: center;
    padding: 0.6rem 1rem;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    transition: background 0.15s;
    gap: 0.75rem;
}
.tbl-list-item:hover { background: #f8fafc; }
.tbl-list-item.active { background: #dbeafe; border-left: 3px solid var(--primary); }
.tbl-list-item .tbl-name { font-weight: 600; font-family: monospace; flex: 1; }
.tbl-list-item .tbl-rows { font-size: 0.75rem; color: var(--text-muted); }
.tbl-list-item .tbl-size { font-size: 0.7rem; color: #94a3b8; }

/* Dev mode banner */
.dev-banner {
    padding: 0.5rem 1rem;
    border-radius: 0.5rem;
    font-size: 0.85rem;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.dev-banner.on { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.dev-banner.off { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }

/* Results panel */
.result-panel {
    margin-top: 1rem;
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    overflow: hidden;
}
.result-panel .result-header {
    padding: 0.5rem 1rem;
    background: #f1f5f9;
    font-weight: 600;
    font-size: 0.85rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.result-panel .result-body {
    overflow-x: auto;
    max-height: 400px;
}
.result-panel table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}
.result-panel th {
    position: sticky;
    top: 0;
    background: #e2e8f0;
    padding: 0.4rem 0.75rem;
    text-align: left;
    font-weight: 600;
}
.result-panel td {
    padding: 0.35rem 0.75rem;
    border-bottom: 1px solid #f1f5f9;
    max-width: 250px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Create table form */
.create-table-form .col-row {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
    align-items: center;
}
.create-table-form input, .create-table-form select {
    padding: 0.35rem 0.5rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.85rem;
}
.create-table-form .col-name { width: 150px; }
.create-table-form .col-type { width: 120px; }
.create-table-form .col-len { width: 70px; }
.create-table-form .col-def { width: 100px; }

/* Import area */
.import-area {
    border: 2px dashed var(--border-color);
    border-radius: 0.75rem;
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 1rem;
}
.import-area:hover { border-color: var(--primary); background: #f8fafc; }
.import-area.dragover { border-color: var(--success); background: #dcfce7; }
</style>

<!-- Dev Mode Banner -->
<div class="dev-banner <?php echo $dev_mode ? 'on' : 'off'; ?>" id="dev-mode-banner">
    <i class="fas fa-<?php echo $dev_mode ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
    <?php if ($dev_mode): ?>
        Developer Mode is <strong>ON</strong> — All database operations are available
    <?php else: ?>
        Developer Mode is <strong>OFF</strong> — Read-only mode. <a href="developer_mode.php" style="color:#92400e; font-weight:600;">Enable it</a> to make changes.
    <?php endif; ?>
</div>

<!-- Hero Section -->
<div class="db-hero" id="db-hero">
    <div>
        <h2 style="margin:0; font-size:1.3rem;"><i class="fas fa-database"></i> Database Manager</h2>
        <p style="margin:0.25rem 0 0; opacity:0.7; font-size:0.85rem;" id="db-name">Loading...</p>
    </div>
    <div class="stats" id="db-stats">
        <div class="stat-box">
            <div class="num" id="stat-tables">-</div>
            <div class="label">Tables</div>
        </div>
        <div class="stat-box">
            <div class="num" id="stat-size">-</div>
            <div class="label">Total Size</div>
        </div>
    </div>
    <div class="db-actions-bar">
        <button class="btn btn-primary btn-sm" onclick="showExportDb()"><i class="fas fa-download"></i> Export DB</button>
        <button class="btn btn-success btn-sm" onclick="switchTab('import')"><i class="fas fa-upload"></i> Import SQL</button>
        <button class="btn btn-primary btn-sm" onclick="switchTab('create')"><i class="fas fa-plus"></i> New Table</button>
    </div>
</div>

<!-- Main Layout: Sidebar + Content -->
<div style="display:flex; gap:1.5rem; min-height:500px;">
    
    <!-- Sidebar: Table List -->
    <div style="width:280px; flex-shrink:0;">
        <div class="card" style="padding:0; height:100%;">
            <div style="padding:0.75rem; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:0.5rem; background:#f1f5f9;">
                <input type="text" id="table-search" placeholder="Filter tables..." style="flex:1; padding:0.35rem 0.75rem; border:1px solid var(--border-color); border-radius:6px; font-size:0.85rem;" oninput="filterTables(this.value)">
                <button class="btn btn-primary btn-sm" onclick="loadTables()" title="Refresh"><i class="fas fa-sync"></i></button>
            </div>
            <div id="table-list" style="overflow-y:auto; max-height:calc(100vh - 300px);">
                <div style="padding:2rem; text-align:center; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i></div>
            </div>
        </div>
    </div>
    
    <!-- Main Content Area -->
    <div style="flex:1; min-width:0;">
        
        <!-- Tabs -->
        <div class="db-tabs" id="main-tabs">
            <div class="db-tab active" data-tab="sql" onclick="switchTab('sql')"><i class="fas fa-terminal"></i> SQL Editor</div>
            <div class="db-tab" data-tab="browse" onclick="switchTab('browse')"><i class="fas fa-table"></i> Browse</div>
            <div class="db-tab" data-tab="structure" onclick="switchTab('structure')"><i class="fas fa-sitemap"></i> Structure</div>
            <div class="db-tab" data-tab="import" onclick="switchTab('import')"><i class="fas fa-upload"></i> Import</div>
            <div class="db-tab" data-tab="create" onclick="switchTab('create')"><i class="fas fa-plus-circle"></i> Create Table</div>
        </div>
        
        <!-- SQL Editor Tab -->
        <div id="tab-sql" class="tab-content">
            <div class="sql-editor-wrap">
                <textarea id="sql-editor" placeholder="SELECT * FROM users LIMIT 10;&#10;&#10;-- Write your SQL here. Multiple statements separated by ;&#10;-- Write operations require Developer Mode ON" spellcheck="false"></textarea>
                <div class="sql-toolbar">
                    <button class="btn btn-success btn-sm" onclick="runSQL()" id="btn-run-sql">
                        <i class="fas fa-play"></i> Run (Ctrl+Enter)
                    </button>
                    <button class="btn btn-primary btn-sm" onclick="clearSQL()"><i class="fas fa-eraser"></i> Clear</button>
                    <div style="flex:1;"></div>
                    <span style="color:#999; font-size:0.75rem;" id="sql-status">Ready</span>
                </div>
            </div>
            <div id="sql-results"></div>
        </div>
        
        <!-- Browse Tab -->
        <div id="tab-browse" class="tab-content" style="display:none;">
            <div id="browse-toolbar" style="display:flex; align-items:center; gap:0.5rem; margin-bottom:1rem;">
                <h3 style="margin:0; flex:1;" id="browse-title">Select a table from the sidebar</h3>
                <input type="text" id="browse-search" placeholder="Search rows..." style="padding:0.35rem 0.75rem; border:1px solid var(--border-color); border-radius:6px; font-size:0.85rem; width:200px;" oninput="debounceSearch()">
                <select id="browse-per-page" style="padding:0.35rem 0.5rem; border:1px solid var(--border-color); border-radius:6px; font-size:0.85rem;" onchange="browsePage(1)">
                    <option value="25">25 rows</option>
                    <option value="50" selected>50 rows</option>
                    <option value="100">100 rows</option>
                    <option value="200">200 rows</option>
                </select>
                <button class="btn btn-success btn-sm" onclick="showInsertRow()" id="btn-insert"><i class="fas fa-plus"></i> Insert</button>
                <button class="btn btn-primary btn-sm" onclick="exportCurrentTable()"><i class="fas fa-download"></i> Export</button>
            </div>
            <div id="browse-content">
                <div style="padding:3rem; text-align:center; color:var(--text-muted);">
                    <i class="fas fa-table" style="font-size:3rem; opacity:0.3;"></i>
                    <p>Click a table in the sidebar to browse its data</p>
                </div>
            </div>
            <div id="browse-pagination"></div>
            
            <!-- Insert Row Form (hidden by default) -->
            <div id="insert-form" style="display:none;" class="card">
                <h4 style="margin:0 0 1rem;"><i class="fas fa-plus"></i> Insert New Row</h4>
                <div id="insert-fields"></div>
                <div style="display:flex; gap:0.5rem; margin-top:1rem;">
                    <button class="btn btn-success btn-sm" onclick="submitInsert()"><i class="fas fa-check"></i> Insert</button>
                    <button class="btn btn-danger btn-sm" onclick="hideInsertRow()"><i class="fas fa-times"></i> Cancel</button>
                </div>
            </div>
        </div>
        
        <!-- Structure Tab -->
        <div id="tab-structure" class="tab-content" style="display:none;">
            <div id="structure-content">
                <div style="padding:3rem; text-align:center; color:var(--text-muted);">
                    <i class="fas fa-sitemap" style="font-size:3rem; opacity:0.3;"></i>
                    <p>Click a table in the sidebar to view its structure</p>
                </div>
            </div>
        </div>
        
        <!-- Import Tab -->
        <div id="tab-import" class="tab-content" style="display:none;">
            <h3><i class="fas fa-upload"></i> Import SQL</h3>
            <p style="color:var(--text-muted); margin-bottom:1rem;">Upload a .sql file or paste SQL directly. Requires Developer Mode ON.</p>
            
            <div class="import-area" id="import-drop-zone" onclick="document.getElementById('import-file-input').click()">
                <i class="fas fa-cloud-upload-alt" style="font-size:2.5rem; color:var(--primary); opacity:0.5;"></i>
                <p style="margin:0.5rem 0 0; font-weight:600;">Drop .sql file here or click to browse</p>
                <p style="margin:0.25rem 0 0; font-size:0.8rem; color:var(--text-muted);">Max size: 10MB</p>
                <input type="file" id="import-file-input" accept=".sql" style="display:none;" onchange="handleImportFile(this)">
            </div>
            
            <p style="text-align:center; color:var(--text-muted); font-weight:600;">— OR paste SQL below —</p>
            
            <div class="sql-editor-wrap" style="margin-top:1rem;">
                <textarea id="import-sql-editor" placeholder="-- Paste SQL statements here..." style="min-height:200px;" spellcheck="false"></textarea>
            </div>
            <button class="btn btn-success" onclick="runImport()" id="btn-import" style="margin-top:0.5rem;">
                <i class="fas fa-upload"></i> Execute Import
            </button>
            <div id="import-result" style="margin-top:1rem;"></div>
        </div>
        
        <!-- Create Table Tab -->
        <div id="tab-create" class="tab-content" style="display:none;">
            <h3><i class="fas fa-plus-circle"></i> Create New Table</h3>
            <p style="color:var(--text-muted); margin-bottom:1rem;">Design a new table visually. Requires Developer Mode ON.</p>
            
            <div style="margin-bottom:1rem;">
                <label style="font-weight:600; font-size:0.9rem;">Table Name</label>
                <input type="text" id="new-table-name" placeholder="my_new_table" style="display:block; margin-top:0.25rem; padding:0.5rem 0.75rem; border:1px solid var(--border-color); border-radius:8px; font-size:0.9rem; width:300px; font-family:monospace;">
            </div>
            
            <label style="font-weight:600; font-size:0.9rem;">Columns</label>
            <div class="create-table-form" id="create-columns">
                <div style="display:flex; gap:0.5rem; margin-bottom:0.25rem; font-size:0.75rem; color:var(--text-muted); font-weight:600;">
                    <span style="width:150px;">Name</span>
                    <span style="width:120px;">Type</span>
                    <span style="width:70px;">Length</span>
                    <span style="width:55px;">Null</span>
                    <span style="width:100px;">Default</span>
                    <span style="width:40px;">PK</span>
                    <span style="width:40px;">AI</span>
                    <span style="width:30px;"></span>
                </div>
            </div>
            <div style="display:flex; gap:0.5rem; margin-top:0.5rem;">
                <button class="btn btn-primary btn-sm" onclick="addColumnRow()"><i class="fas fa-plus"></i> Add Column</button>
                <button class="btn btn-success" onclick="submitCreateTable()"><i class="fas fa-check"></i> Create Table</button>
            </div>
            <div id="create-result" style="margin-top:1rem;"></div>
            
            <!-- Quick Templates -->
            <div class="card" style="margin-top:1.5rem; background:#f8fafc;">
                <h4 style="margin:0 0 0.75rem;"><i class="fas fa-magic"></i> Quick Templates</h4>
                <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                    <button class="btn btn-primary btn-sm" onclick="loadTemplate('basic')">Basic (id, name, created)</button>
                    <button class="btn btn-primary btn-sm" onclick="loadTemplate('users')">User Table</button>
                    <button class="btn btn-primary btn-sm" onclick="loadTemplate('logs')">Log Table</button>
                    <button class="btn btn-primary btn-sm" onclick="loadTemplate('settings')">Key-Value Settings</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ---- State ----
let allTables = [];
let currentTable = null;
let currentTab = 'sql';
let browseState = { page: 1, sort_col: '', sort_dir: 'ASC', search: '' };
let browseColumns = [];
let browsePkCol = null;
let searchTimer = null;

// ---- Format helpers ----
function formatSize(bytes) {
    if (!bytes || bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

// ---- Tab switching ----
function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.db-tab').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tab).style.display = 'block';
    const tabBtn = document.querySelector(`.db-tab[data-tab="${tab}"]`);
    if (tabBtn) tabBtn.classList.add('active');
}

// ---- Load tables ----
function loadTables() {
    fetch('manage_database.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=list_tables'
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) return;
        allTables = data.tables;
        
        // Update hero
        document.getElementById('db-name').textContent = data.db_name;
        document.getElementById('stat-tables').textContent = data.tables.length;
        const totalSize = data.tables.reduce((s, t) => s + t.total_size, 0);
        document.getElementById('stat-size').textContent = formatSize(totalSize);
        
        renderTableList(allTables);
    });
}

function renderTableList(tables) {
    let html = '';
    tables.forEach(t => {
        const active = currentTable === t.name ? ' active' : '';
        html += `<div class="tbl-list-item${active}" onclick="selectTable('${t.name}')">
            <i class="fas fa-table" style="color:var(--text-muted); font-size:0.85rem;"></i>
            <span class="tbl-name">${t.name}</span>
            <span class="tbl-rows">${t.rows}</span>
            <span class="tbl-size">${formatSize(t.total_size)}</span>
        </div>`;
    });
    if (tables.length === 0) html = '<div style="padding:1.5rem; text-align:center; color:var(--text-muted);">No tables found</div>';
    document.getElementById('table-list').innerHTML = html;
}

function filterTables(q) {
    q = q.toLowerCase();
    renderTableList(allTables.filter(t => t.name.toLowerCase().includes(q)));
}

function selectTable(name) {
    currentTable = name;
    renderTableList(allTables.filter(t => {
        const q = document.getElementById('table-search').value.toLowerCase();
        return !q || t.name.toLowerCase().includes(q);
    }));
    
    // Auto-switch to browse if on sql or other tabs
    if (currentTab === 'sql' || currentTab === 'import' || currentTab === 'create') {
        switchTab('browse');
    }
    
    if (currentTab === 'browse') {
        browseState = { page: 1, sort_col: '', sort_dir: 'ASC', search: '' };
        document.getElementById('browse-search').value = '';
        loadBrowse();
    } else if (currentTab === 'structure') {
        loadStructure();
    }
}

// ---- Browse table ----
function loadBrowse() {
    if (!currentTable) return;
    
    document.getElementById('browse-title').textContent = currentTable;
    document.getElementById('browse-content').innerHTML = '<div style="padding:2rem; text-align:center;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    
    const perPage = document.getElementById('browse-per-page').value;
    
    fetch('manage_database.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=browse_table&table=${encodeURIComponent(currentTable)}&page=${browseState.page}&per_page=${perPage}&sort_col=${encodeURIComponent(browseState.sort_col)}&sort_dir=${browseState.sort_dir}&search=${encodeURIComponent(browseState.search)}`
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            document.getElementById('browse-content').innerHTML = `<div style="color:var(--danger); padding:1rem;">${data.error}</div>`;
            return;
        }
        
        browseColumns = data.columns;
        browsePkCol = data.pk_col;
        
        if (data.rows.length === 0) {
            document.getElementById('browse-content').innerHTML = '<div style="padding:2rem; text-align:center; color:var(--text-muted);">No rows found</div>';
            document.getElementById('browse-pagination').innerHTML = '';
            return;
        }
        
        // Build table
        let html = '<div class="table-browser"><table><thead><tr>';
        if (browsePkCol) html += '<th style="width:80px;">Actions</th>';
        
        data.columns.forEach(c => {
            const sorted = browseState.sort_col === c.Field;
            const icon = sorted ? (browseState.sort_dir === 'ASC' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
            html += `<th class="${sorted ? 'sorted' : ''}" onclick="sortBrowse('${c.Field}')">
                ${c.Field} <i class="fas ${icon} sort-icon"></i>
                <div style="font-size:0.65rem; font-weight:400; color:#94a3b8;">${c.Type}</div>
            </th>`;
        });
        html += '</tr></thead><tbody>';
        
        data.rows.forEach(row => {
            html += '<tr>';
            if (browsePkCol) {
                const pkVal = row[browsePkCol];
                html += `<td class="row-actions">
                    <button onclick="deleteRow('${encodeURIComponent(pkVal)}')" title="Delete" style="color:var(--danger);"><i class="fas fa-trash"></i></button>
                </td>`;
            }
            data.columns.forEach(c => {
                const val = row[c.Field];
                const display = val === null ? '<em style="color:#94a3b8;">NULL</em>' : escapeHtml(truncate(String(val), 120));
                const editable = browsePkCol ? ' editable' : '';
                const editAction = browsePkCol ? ` ondblclick="startEdit(this, '${c.Field}', '${encodeURIComponent(row[browsePkCol])}')"` : '';
                html += `<td class="${editable}" title="${escapeHtml(String(val ?? ''))}"${editAction}>${display}</td>`;
            });
            html += '</tr>';
        });
        
        html += '</tbody></table></div>';
        document.getElementById('browse-content').innerHTML = html;
        
        // Pagination
        renderPagination(data.page, data.total_pages, data.total);
    });
}

function renderPagination(page, totalPages, total) {
    if (totalPages <= 1) {
        document.getElementById('browse-pagination').innerHTML = `<div class="pagination"><span class="page-info">${total} rows total</span></div>`;
        return;
    }
    
    let html = '<div class="pagination"><span class="page-info">';
    html += `Page ${page} of ${totalPages} (${total} rows)`;
    html += '</span><div class="page-btns">';
    
    html += `<button class="page-btn" onclick="browsePage(1)" ${page === 1 ? 'disabled' : ''}><i class="fas fa-angle-double-left"></i></button>`;
    html += `<button class="page-btn" onclick="browsePage(${page - 1})" ${page === 1 ? 'disabled' : ''}><i class="fas fa-angle-left"></i></button>`;
    
    const start = Math.max(1, page - 2);
    const end = Math.min(totalPages, page + 2);
    for (let i = start; i <= end; i++) {
        html += `<button class="page-btn ${i === page ? 'active' : ''}" onclick="browsePage(${i})">${i}</button>`;
    }
    
    html += `<button class="page-btn" onclick="browsePage(${page + 1})" ${page === totalPages ? 'disabled' : ''}><i class="fas fa-angle-right"></i></button>`;
    html += `<button class="page-btn" onclick="browsePage(${totalPages})" ${page === totalPages ? 'disabled' : ''}><i class="fas fa-angle-double-right"></i></button>`;
    html += '</div></div>';
    
    document.getElementById('browse-pagination').innerHTML = html;
}

function browsePage(p) {
    browseState.page = p;
    loadBrowse();
}

function sortBrowse(col) {
    if (browseState.sort_col === col) {
        browseState.sort_dir = browseState.sort_dir === 'ASC' ? 'DESC' : 'ASC';
    } else {
        browseState.sort_col = col;
        browseState.sort_dir = 'ASC';
    }
    browseState.page = 1;
    loadBrowse();
}

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        browseState.search = document.getElementById('browse-search').value;
        browseState.page = 1;
        loadBrowse();
    }, 400);
}

// ---- Inline editing ----
function startEdit(td, col, pkValEncoded) {
    if (td.classList.contains('editing')) return;
    
    const currentVal = td.textContent.trim();
    const isNull = td.innerHTML.includes('NULL');
    td.classList.add('editing');
    td.innerHTML = `<input type="text" value="${escapeHtml(isNull ? '' : currentVal)}" 
        onblur="finishEdit(this, '${col}', '${pkValEncoded}')" 
        onkeydown="if(event.key==='Enter') this.blur(); if(event.key==='Escape') cancelEdit(this, '${escapeHtml(isNull ? '' : currentVal)}');">`;
    td.querySelector('input').focus();
    td.querySelector('input').select();
}

function finishEdit(input, col, pkValEncoded) {
    const td = input.parentElement;
    const newVal = input.value;
    const pkVal = decodeURIComponent(pkValEncoded);
    
    td.classList.remove('editing');
    td.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:var(--primary);"></i>';
    
    fetch('manage_database.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=update_row&table=${encodeURIComponent(currentTable)}&pk_col=${encodeURIComponent(browsePkCol)}&pk_val=${encodeURIComponent(pkVal)}&column=${encodeURIComponent(col)}&value=${encodeURIComponent(newVal)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            td.innerHTML = escapeHtml(truncate(newVal, 120));
            td.style.background = '#dcfce7';
            setTimeout(() => td.style.background = '', 1500);
        } else {
            td.innerHTML = `<span style="color:var(--danger);">${data.error}</span>`;
            setTimeout(() => loadBrowse(), 2000);
        }
    });
}

function cancelEdit(input, originalVal) {
    const td = input.parentElement;
    td.classList.remove('editing');
    td.innerHTML = escapeHtml(originalVal) || '<em style="color:#94a3b8;">NULL</em>';
}

// ---- Delete row ----
function deleteRow(pkValEncoded) {
    const pkVal = decodeURIComponent(pkValEncoded);
    if (!confirm(`Delete row where ${browsePkCol} = ${pkVal}?`)) return;
    
    fetch('manage_database.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete_row&table=${encodeURIComponent(currentTable)}&pk_col=${encodeURIComponent(browsePkCol)}&pk_val=${encodeURIComponent(pkVal)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadBrowse();
            loadTables();
        } else {
            alert('Delete failed: ' + data.error);
        }
    });
}

// ---- Insert row ----
function showInsertRow() {
    if (!currentTable || !browseColumns.length) return;
    
    const form = document.getElementById('insert-form');
    form.style.display = 'block';
    
    let html = '';
    browseColumns.forEach(c => {
        const isAI = c.Extra.includes('auto_increment');
        html += `<div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem;">
            <label style="width:150px; font-weight:600; font-size:0.85rem; font-family:monospace;">${c.Field}</label>
            <input type="text" class="insert-field" data-col="${c.Field}" 
                placeholder="${isAI ? 'AUTO' : c.Type}" 
                ${isAI ? 'disabled' : ''}
                style="flex:1; padding:0.35rem 0.75rem; border:1px solid var(--border-color); border-radius:6px; font-size:0.85rem; font-family:monospace;">
            <span style="font-size:0.7rem; color:#94a3b8; width:80px;">${c.Type}</span>
        </div>`;
    });
    document.getElementById('insert-fields').innerHTML = html;
}

function hideInsertRow() {
    document.getElementById('insert-form').style.display = 'none';
}

function submitInsert() {
    const data = {};
    document.querySelectorAll('.insert-field:not(:disabled)').forEach(input => {
        if (input.value.trim() !== '') {
            data[input.dataset.col] = input.value;
        }
    });
    
    fetch('manage_database.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=insert_row&table=${encodeURIComponent(currentTable)}&row_data=${encodeURIComponent(JSON.stringify(data))}`
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            hideInsertRow();
            loadBrowse();
            loadTables();
        } else {
            alert('Insert failed: ' + result.error);
        }
    });
}

// ---- Structure view ----
function loadStructure() {
    if (!currentTable) return;
    
    document.getElementById('structure-content').innerHTML = '<div style="padding:2rem; text-align:center;"><i class="fas fa-spinner fa-spin"></i></div>';
    
    fetch('manage_database.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=view_structure&table=${encodeURIComponent(currentTable)}`
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) return;
        
        let html = `<h3 style="margin:0 0 1rem;">Structure: <span style="font-family:monospace;">${data.table}</span></h3>`;
        
        // Columns table
        html += '<div class="table-browser"><table><thead><tr><th>Field</th><th>Type</th><th>Collation</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th><th>Comment</th></tr></thead><tbody>';
        data.columns.forEach(c => {
            html += `<tr>
                <td style="font-family:monospace; font-weight:600;">${c.Field}</td>
                <td style="font-family:monospace;">${c.Type}</td>
                <td style="font-size:0.8rem;">${c.Collation || '-'}</td>
                <td>${c.Null === 'YES' ? '<span style="color:#f59e0b;">YES</span>' : '<span style="color:#94a3b8;">NO</span>'}</td>
                <td>${c.Key ? '<span class="badge badge-info">' + c.Key + '</span>' : '-'}</td>
                <td style="font-family:monospace; font-size:0.85rem;">${c.Default !== null ? c.Default : '<em style="color:#94a3b8;">NULL</em>'}</td>
                <td style="font-size:0.85rem;">${c.Extra || '-'}</td>
                <td style="font-size:0.8rem; color:var(--text-muted);">${c.Comment || ''}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        
        // Indexes
        if (data.indexes.length > 0) {
            html += '<h4 style="margin:1.5rem 0 0.5rem;">Indexes</h4>';
            html += '<div class="table-browser"><table><thead><tr><th>Key Name</th><th>Column</th><th>Unique</th><th>Seq</th><th>Collation</th><th>Cardinality</th></tr></thead><tbody>';
            data.indexes.forEach(idx => {
                html += `<tr>
                    <td style="font-family:monospace; font-weight:600;">${idx.Key_name}</td>
                    <td style="font-family:monospace;">${idx.Column_name}</td>
                    <td>${idx.Non_unique === '0' ? '<span class="badge badge-active">YES</span>' : 'NO'}</td>
                    <td>${idx.Seq_in_index}</td>
                    <td>${idx.Collation || '-'}</td>
                    <td>${idx.Cardinality || '-'}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
        }
        
        // CREATE TABLE SQL
        html += '<h4 style="margin:1.5rem 0 0.5rem;">CREATE TABLE SQL</h4>';
        html += `<pre style="background:#1e1e1e; color:#d4d4d4; padding:1rem; border-radius:0.75rem; overflow-x:auto; font-size:0.85rem; line-height:1.5;">${escapeHtml(data.create_sql)}</pre>`;
        
        // Table actions
        html += `<div style="margin-top:1.5rem; display:flex; gap:0.5rem;">
            <button class="btn btn-primary btn-sm" onclick="exportCurrentTable()"><i class="fas fa-download"></i> Export Table</button>
            <button class="btn btn-warning btn-sm" onclick="truncateTable()"><i class="fas fa-eraser"></i> Truncate</button>
            <button class="btn btn-danger btn-sm" onclick="dropTable()"><i class="fas fa-trash"></i> Drop Table</button>
        </div>`;
        
        document.getElementById('structure-content').innerHTML = html;
    });
}

// ---- Table actions ----
function truncateTable() {
    if (!currentTable) return;
    if (!confirm(`CLEAR ALL DATA from "${currentTable}"? This resets row count to 0.`)) return;
    if (!confirm(`SECOND CONFIRMATION: Truncate "${currentTable}"? THIS CANNOT BE UNDONE.`)) return;
    
    fetch('manage_database.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=truncate_table&table=${encodeURIComponent(currentTable)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadBrowse();
            loadTables();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

function dropTable() {
    if (!currentTable) return;
    if (!confirm(`DROP TABLE "${currentTable}"? The table and ALL data will be permanently deleted!`)) return;
    if (!confirm(`FINAL WARNING: Drop "${currentTable}"? THIS CANNOT BE UNDONE!`)) return;
    
    fetch('manage_database.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=drop_table&table=${encodeURIComponent(currentTable)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            currentTable = null;
            switchTab('sql');
            loadTables();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

// ---- SQL Editor ----
function runSQL() {
    const sql = document.getElementById('sql-editor').value.trim();
    if (!sql) return;
    
    document.getElementById('sql-status').textContent = 'Executing...';
    document.getElementById('btn-run-sql').disabled = true;
    
    fetch('manage_database.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=run_sql&sql=${encodeURIComponent(sql)}`
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('btn-run-sql').disabled = false;
        
        if (data.success) {
            document.getElementById('sql-status').textContent = data.message;
            
            let html = '';
            if (data.results && data.results.length > 0) {
                data.results.forEach((rs, i) => {
                    html += `<div class="result-panel">
                        <div class="result-header">
                            <span>Result Set ${i + 1} (${rs.count} rows)</span>
                            <button class="btn btn-primary btn-sm" onclick="copyResults(${i})" style="padding:2px 8px; font-size:0.75rem;"><i class="fas fa-copy"></i></button>
                        </div>
                        <div class="result-body"><table><thead><tr>`;
                    rs.fields.forEach(f => html += `<th>${f}</th>`);
                    html += '</tr></thead><tbody>';
                    rs.rows.forEach(row => {
                        html += '<tr>';
                        rs.fields.forEach(f => {
                            const val = row[f];
                            html += `<td>${val === null ? '<em style="color:#94a3b8;">NULL</em>' : escapeHtml(truncate(String(val), 150))}</td>`;
                        });
                        html += '</tr>';
                    });
                    html += '</tbody></table></div></div>';
                });
            } else {
                html = `<div style="padding:1rem; background:#dcfce7; border-radius:0.75rem; color:#166534; margin-top:1rem;">
                    <i class="fas fa-check-circle"></i> ${data.message}
                </div>`;
            }
            
            document.getElementById('sql-results').innerHTML = html;
            loadTables();
        } else {
            document.getElementById('sql-status').textContent = 'Error';
            document.getElementById('sql-results').innerHTML = `<div style="padding:1rem; background:#fee2e2; border-radius:0.75rem; color:#991b1b; margin-top:1rem;">
                <i class="fas fa-times-circle"></i> <strong>Error:</strong> ${escapeHtml(data.error)}
            </div>`;
        }
    })
    .catch(err => {
        document.getElementById('btn-run-sql').disabled = false;
        document.getElementById('sql-status').textContent = 'Network error';
    });
}

function clearSQL() {
    document.getElementById('sql-editor').value = '';
    document.getElementById('sql-results').innerHTML = '';
    document.getElementById('sql-status').textContent = 'Ready';
}

// ---- Export ----
function exportCurrentTable() {
    if (!currentTable) return;
    
    fetch('manage_database.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=export_table&table=${encodeURIComponent(currentTable)}&include_data=true`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            downloadText(data.sql, data.filename);
        } else {
            alert('Export failed: ' + data.error);
        }
    });
}

function showExportDb() {
    if (!confirm('Export entire database? This may take a moment for large databases.')) return;
    
    fetch('manage_database.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=export_database&include_data=true'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            downloadText(data.sql, data.filename);
        } else {
            alert('Export failed: ' + (data.error || 'Unknown error'));
        }
    });
}

function downloadText(content, filename) {
    const blob = new Blob([content], {type: 'text/sql'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

// ---- Import ----
function handleImportFile(input) {
    const file = input.files[0];
    if (!file) return;
    
    if (file.size > 10 * 1024 * 1024) {
        alert('File too large (max 10MB)');
        return;
    }
    
    const reader = new FileReader();
    reader.onload = (e) => {
        document.getElementById('import-sql-editor').value = e.target.result;
    };
    reader.readAsText(file);
}

function runImport() {
    const sql = document.getElementById('import-sql-editor').value.trim();
    if (!sql) { alert('No SQL to import'); return; }
    if (!confirm('Execute SQL import? This may modify your database.')) return;
    
    document.getElementById('btn-import').disabled = true;
    document.getElementById('btn-import').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
    
    fetch('manage_database.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=import_sql&sql_content=${encodeURIComponent(sql)}`
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('btn-import').disabled = false;
        document.getElementById('btn-import').innerHTML = '<i class="fas fa-upload"></i> Execute Import';
        
        const el = document.getElementById('import-result');
        if (data.success) {
            el.innerHTML = `<div style="padding:1rem; background:#dcfce7; border-radius:0.75rem; color:#166534;">
                <i class="fas fa-check-circle"></i> ${data.message}
                ${data.errors && data.errors.length ? '<br><small>Warnings: ' + data.errors.join('; ') + '</small>' : ''}
            </div>`;
            loadTables();
        } else {
            el.innerHTML = `<div style="padding:1rem; background:#fee2e2; border-radius:0.75rem; color:#991b1b;">
                <i class="fas fa-times-circle"></i> ${data.error}
            </div>`;
        }
    });
}

// Drag & drop
const dropZone = document.getElementById('import-drop-zone');
dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    if (e.dataTransfer.files.length) {
        document.getElementById('import-file-input').files = e.dataTransfer.files;
        handleImportFile(document.getElementById('import-file-input'));
    }
});

// ---- Create Table ----
let columnCount = 0;

function addColumnRow(name = '', type = 'VARCHAR', length = '', nullable = 'YES', def = '', pk = false, ai = false) {
    columnCount++;
    const id = columnCount;
    const div = document.createElement('div');
    div.className = 'col-row';
    div.id = 'col-row-' + id;
    div.innerHTML = `
        <input type="text" class="col-name" value="${name}" placeholder="column_name" data-id="${id}">
        <select class="col-type" data-id="${id}">
            <option ${type==='INT'?'selected':''}>INT</option>
            <option ${type==='BIGINT'?'selected':''}>BIGINT</option>
            <option ${type==='VARCHAR'?'selected':''}>VARCHAR</option>
            <option ${type==='TEXT'?'selected':''}>TEXT</option>
            <option ${type==='MEDIUMTEXT'?'selected':''}>MEDIUMTEXT</option>
            <option ${type==='LONGTEXT'?'selected':''}>LONGTEXT</option>
            <option ${type==='TINYINT'?'selected':''}>TINYINT</option>
            <option ${type==='DECIMAL'?'selected':''}>DECIMAL</option>
            <option ${type==='FLOAT'?'selected':''}>FLOAT</option>
            <option ${type==='DATE'?'selected':''}>DATE</option>
            <option ${type==='DATETIME'?'selected':''}>DATETIME</option>
            <option ${type==='TIMESTAMP'?'selected':''}>TIMESTAMP</option>
            <option ${type==='ENUM'?'selected':''}>ENUM</option>
            <option ${type==='BOOLEAN'?'selected':''}>BOOLEAN</option>
            <option ${type==='BLOB'?'selected':''}>BLOB</option>
        </select>
        <input type="text" class="col-len" value="${length}" placeholder="len" data-id="${id}">
        <select class="col-null" data-id="${id}" style="width:55px;">
            <option value="YES" ${nullable==='YES'?'selected':''}>Yes</option>
            <option value="NO" ${nullable==='NO'?'selected':''}>No</option>
        </select>
        <input type="text" class="col-def" value="${def}" placeholder="default" data-id="${id}">
        <input type="checkbox" class="col-pk" ${pk?'checked':''} data-id="${id}" style="width:40px;">
        <input type="checkbox" class="col-ai" ${ai?'checked':''} data-id="${id}" style="width:40px;">
        <button onclick="document.getElementById('col-row-${id}').remove()" style="background:none; border:none; cursor:pointer; color:var(--danger); width:30px;"><i class="fas fa-times"></i></button>
    `;
    document.getElementById('create-columns').appendChild(div);
}

function submitCreateTable() {
    const tableName = document.getElementById('new-table-name').value.trim();
    if (!tableName) { alert('Enter a table name'); return; }
    
    const columns = [];
    document.querySelectorAll('.col-row').forEach(row => {
        const id = row.querySelector('.col-name').dataset.id;
        const name = row.querySelector('.col-name').value.trim();
        if (!name) return;
        columns.push({
            name: name,
            type: row.querySelector('.col-type').value,
            length: row.querySelector('.col-len').value,
            nullable: row.querySelector('.col-null').value,
            default: row.querySelector('.col-def').value,
            primary: row.querySelector('.col-pk').checked,
            auto_increment: row.querySelector('.col-ai').checked
        });
    });
    
    if (columns.length === 0) { alert('Add at least one column'); return; }
    
    fetch('manage_database.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=create_table&table_name=${encodeURIComponent(tableName)}&columns=${encodeURIComponent(JSON.stringify(columns))}`
    })
    .then(r => r.json())
    .then(data => {
        const el = document.getElementById('create-result');
        if (data.success) {
            el.innerHTML = `<div style="padding:1rem; background:#dcfce7; border-radius:0.75rem; color:#166534;">
                <i class="fas fa-check-circle"></i> Table "${tableName}" created successfully!
                <pre style="margin:0.5rem 0 0; font-size:0.8rem; background:rgba(0,0,0,0.05); padding:0.5rem; border-radius:4px;">${escapeHtml(data.sql)}</pre>
            </div>`;
            loadTables();
        } else {
            el.innerHTML = `<div style="padding:1rem; background:#fee2e2; border-radius:0.75rem; color:#991b1b;">
                <i class="fas fa-times-circle"></i> ${escapeHtml(data.error)}
                ${data.sql ? '<pre style="margin:0.5rem 0 0; font-size:0.8rem;">' + escapeHtml(data.sql) + '</pre>' : ''}
            </div>`;
        }
    });
}

function loadTemplate(type) {
    // Clear existing columns
    document.querySelectorAll('.col-row').forEach(r => r.remove());
    columnCount = 0;
    
    switch (type) {
        case 'basic':
            document.getElementById('new-table-name').value = 'my_table';
            addColumnRow('id', 'INT', '11', 'NO', '', true, true);
            addColumnRow('name', 'VARCHAR', '255', 'NO');
            addColumnRow('created_at', 'TIMESTAMP', '', 'YES', 'CURRENT_TIMESTAMP');
            break;
        case 'users':
            document.getElementById('new-table-name').value = 'custom_users';
            addColumnRow('id', 'INT', '11', 'NO', '', true, true);
            addColumnRow('username', 'VARCHAR', '100', 'NO');
            addColumnRow('email', 'VARCHAR', '255', 'NO');
            addColumnRow('password_hash', 'VARCHAR', '255', 'NO');
            addColumnRow('role', 'VARCHAR', '50', 'YES', 'user');
            addColumnRow('is_active', 'TINYINT', '1', 'YES', '1');
            addColumnRow('created_at', 'TIMESTAMP', '', 'YES', 'CURRENT_TIMESTAMP');
            break;
        case 'logs':
            document.getElementById('new-table-name').value = 'activity_log';
            addColumnRow('id', 'BIGINT', '20', 'NO', '', true, true);
            addColumnRow('user_id', 'INT', '11', 'YES');
            addColumnRow('action', 'VARCHAR', '100', 'NO');
            addColumnRow('details', 'TEXT', '', 'YES');
            addColumnRow('ip_address', 'VARCHAR', '45', 'YES');
            addColumnRow('created_at', 'TIMESTAMP', '', 'YES', 'CURRENT_TIMESTAMP');
            break;
        case 'settings':
            document.getElementById('new-table-name').value = 'app_settings';
            addColumnRow('id', 'INT', '11', 'NO', '', true, true);
            addColumnRow('setting_key', 'VARCHAR', '100', 'NO');
            addColumnRow('setting_value', 'TEXT', '', 'YES');
            addColumnRow('updated_at', 'TIMESTAMP', '', 'YES', 'CURRENT_TIMESTAMP');
            break;
    }
}

// ---- Utility ----
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function truncate(str, max) {
    return str.length > max ? str.substring(0, max) + '...' : str;
}

function copyResults(index) {
    const tables = document.querySelectorAll('.result-panel table');
    if (!tables[index]) return;
    
    let text = '';
    tables[index].querySelectorAll('tr').forEach(tr => {
        const cells = [];
        tr.querySelectorAll('th, td').forEach(cell => cells.push(cell.textContent.trim()));
        text += cells.join('\t') + '\n';
    });
    
    navigator.clipboard.writeText(text);
}

// ---- Ctrl+Enter to run SQL ----
document.getElementById('sql-editor').addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        runSQL();
    }
    if (e.key === 'Tab') {
        e.preventDefault();
        const start = e.target.selectionStart;
        const end = e.target.selectionEnd;
        e.target.value = e.target.value.substring(0, start) + '    ' + e.target.value.substring(end);
        e.target.selectionStart = e.target.selectionEnd = start + 4;
    }
});

// ---- Initialize ----
loadTables();
addColumnRow('id', 'INT', '11', 'NO', '', true, true);
addColumnRow('', 'VARCHAR', '255', 'YES');
</script>

<?php require_once 'includes/footer.php'; ?>
