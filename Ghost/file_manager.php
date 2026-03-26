<?php
require_once 'includes/header.php';

// ---- Security: Only developer role ----
$base_path = '/var/www/html';

// ---- Allow fallback for local dev (non-Docker) ----
if (!is_dir($base_path)) {
    $base_path = realpath(__DIR__ . '/..') ?: '.';
}

// Blocked paths - never show or allow editing
$blocked = ['.git', 'vendor', 'node_modules', '.env'];

// ---- Handle AJAX actions ----
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'list':
            $dir = $_POST['path'] ?? '/';
            $full_path = realpath($base_path . '/' . $dir);
            
            // Prevent directory traversal
            if (!$full_path || strpos($full_path, realpath($base_path)) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            if (!is_dir($full_path)) {
                echo json_encode(['success' => false, 'error' => 'Not a directory']);
                exit;
            }
            
            $items = [];
            $scan = scandir($full_path);
            foreach ($scan as $item) {
                if ($item === '.' || $item === '..') continue;
                if (in_array($item, $blocked)) continue;
                
                $item_path = $full_path . DIRECTORY_SEPARATOR . $item;
                $rel_path = str_replace(realpath($base_path), '', $item_path);
                $rel_path = str_replace('\\', '/', $rel_path);
                
                $info = [
                    'name' => $item,
                    'path' => $rel_path,
                    'is_dir' => is_dir($item_path),
                    'size' => is_file($item_path) ? filesize($item_path) : 0,
                    'modified' => date('Y-m-d H:i:s', filemtime($item_path)),
                    'permissions' => substr(sprintf('%o', fileperms($item_path)), -4),
                    'writable' => is_writable($item_path)
                ];
                
                if (is_file($item_path)) {
                    $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                    $info['extension'] = $ext;
                    $info['editable'] = in_array($ext, ['php', 'html', 'css', 'js', 'json', 'txt', 'md', 'xml', 'yml', 'yaml', 'ini', 'conf', 'sh', 'bat', 'sql', 'htaccess', 'env']);
                }
                
                $items[] = $info;
            }
            
            // Sort: dirs first, then files
            usort($items, function($a, $b) {
                if ($a['is_dir'] !== $b['is_dir']) return $b['is_dir'] - $a['is_dir'];
                return strcasecmp($a['name'], $b['name']);
            });
            
            echo json_encode(['success' => true, 'items' => $items, 'path' => $dir]);
            exit;
            
        case 'read':
            $file = $_POST['path'] ?? '';
            $full_path = realpath($base_path . '/' . $file);
            
            if (!$full_path || strpos($full_path, realpath($base_path)) !== 0 || !is_file($full_path)) {
                echo json_encode(['success' => false, 'error' => 'File not found or access denied']);
                exit;
            }
            
            // Check file size (limit to 2MB for editor)
            if (filesize($full_path) > 2 * 1024 * 1024) {
                echo json_encode(['success' => false, 'error' => 'File too large (max 2MB for editor). Use download instead.']);
                exit;
            }
            
            $content = file_get_contents($full_path);
            $ext = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
            
            // Detect language for syntax highlighting
            $languages = [
                'php' => 'php', 'html' => 'html', 'css' => 'css',
                'js' => 'javascript', 'json' => 'json', 'sql' => 'sql',
                'xml' => 'xml', 'yml' => 'yaml', 'yaml' => 'yaml',
                'md' => 'markdown', 'sh' => 'bash', 'bat' => 'batch',
                'ini' => 'ini', 'conf' => 'apache', 'txt' => 'text'
            ];
            
            echo json_encode([
                'success' => true,
                'content' => $content,
                'language' => $languages[$ext] ?? 'text',
                'size' => filesize($full_path),
                'writable' => is_writable($full_path),
                'path' => $file
            ]);
            exit;
            
        case 'save':
            $file = $_POST['path'] ?? '';
            $content = $_POST['content'] ?? '';
            $full_path = realpath($base_path . '/' . $file);
            
            if (!$full_path || strpos($full_path, realpath($base_path)) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            // Check developer mode
            $dev_check = $conn->query("SELECT setting_value FROM developer_settings WHERE setting_key='developer_mode'");
            $dev_mode = $dev_check ? ($dev_check->fetch_row()[0] ?? 'false') : 'false';
            
            if ($dev_mode !== 'true') {
                echo json_encode(['success' => false, 'error' => 'Developer mode must be ON to save files. Enable it from Developer Mode page.']);
                exit;
            }
            
            if (!is_writable($full_path)) {
                echo json_encode(['success' => false, 'error' => 'File is not writable. Check permissions.']);
                exit;
            }
            
            // Create backup
            $backup_dir = $base_path . '/.ghost_backups/' . date('Y-m-d');
            if (!is_dir($backup_dir)) {
                @mkdir($backup_dir, 0775, true);
            }
            $backup_name = str_replace(['/', '\\'], '_', $file) . '.' . date('His') . '.bak';
            @copy($full_path, $backup_dir . '/' . $backup_name);
            
            // Save file
            $result = file_put_contents($full_path, $content);
            
            if ($result !== false) {
                // Log in audit
                $conn->query("INSERT INTO system_audit_log (user_id, action, details, ip_address) VALUES (
                    {$_SESSION['user_id']}, 'file_edit', 'Edited: $file', '{$_SERVER['REMOTE_ADDR']}'
                )");
                
                echo json_encode(['success' => true, 'message' => 'File saved successfully', 'bytes' => $result, 'backup' => $backup_name]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to save file']);
            }
            exit;
            
        case 'create_file':
            $dir = $_POST['dir'] ?? '/';
            $filename = $_POST['filename'] ?? '';
            
            if (empty($filename) || preg_match('/[\/\\\\<>:"|?*]/', $filename)) {
                echo json_encode(['success' => false, 'error' => 'Invalid filename']);
                exit;
            }
            
            $full_dir = realpath($base_path . '/' . $dir);
            if (!$full_dir || strpos($full_dir, realpath($base_path)) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            $full_path = $full_dir . '/' . $filename;
            if (file_exists($full_path)) {
                echo json_encode(['success' => false, 'error' => 'File already exists']);
                exit;
            }
            
            $result = file_put_contents($full_path, '');
            echo json_encode(['success' => $result !== false]);
            exit;
            
        case 'create_dir':
            // Check developer mode
            $dev_check = $conn->query("SELECT setting_value FROM developer_settings WHERE setting_key='developer_mode'");
            $dev_mode = $dev_check ? ($dev_check->fetch_row()[0] ?? 'false') : 'false';
            if ($dev_mode !== 'true') {
                echo json_encode(['success' => false, 'error' => 'Developer mode must be ON to create directories.']);
                exit;
            }
            
            $dir = $_POST['dir'] ?? '/';
            $dirname = $_POST['dirname'] ?? '';
            
            if (empty($dirname) || preg_match('/[\/\\<>:"|?*]/', $dirname)) {
                echo json_encode(['success' => false, 'error' => 'Invalid directory name']);
                exit;
            }
            
            $full_dir = realpath($base_path . '/' . $dir);
            if (!$full_dir || strpos($full_dir, realpath($base_path)) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            $full_path = $full_dir . '/' . $dirname;
            $result = @mkdir($full_path, 0775, true);
            if ($result) {
                $conn->query("INSERT INTO system_audit_log (user_id, action, details, ip_address) VALUES (
                    {$_SESSION['user_id']}, 'dir_create', 'Created dir: $dir/$dirname', '{$_SERVER['REMOTE_ADDR']}'
                )");
            }
            echo json_encode(['success' => $result]);
            exit;
            
        case 'delete':
            // Check developer mode
            $dev_check = $conn->query("SELECT setting_value FROM developer_settings WHERE setting_key='developer_mode'");
            $dev_mode = $dev_check ? ($dev_check->fetch_row()[0] ?? 'false') : 'false';
            if ($dev_mode !== 'true') {
                echo json_encode(['success' => false, 'error' => 'Developer mode must be ON to delete files.']);
                exit;
            }
            
            $file = $_POST['path'] ?? '';
            $full_path = realpath($base_path . '/' . $file);
            
            if (!$full_path || strpos($full_path, realpath($base_path)) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            // Don't allow deleting critical files
            $critical = ['index.php', 'login.php', 'includes/db_connect.php', 'docker-compose.yml', 'Dockerfile'];
            foreach ($critical as $c) {
                if (strpos($file, $c) !== false) {
                    echo json_encode(['success' => false, 'error' => 'Cannot delete critical system file']);
                    exit;
                }
            }
            
            if (is_dir($full_path)) {
                // Recursive delete for directories (be careful!)
                $result = @rmdir($full_path); // only empty dirs
            } else {
                $result = @unlink($full_path);
            }
            
            if ($result) {
                $conn->query("INSERT INTO system_audit_log (user_id, action, details, ip_address) VALUES (
                    {$_SESSION['user_id']}, 'file_delete', 'Deleted: $file', '{$_SERVER['REMOTE_ADDR']}'
                )");
            }
            
            echo json_encode(['success' => $result]);
            exit;

        case 'rename':
            // Check developer mode
            $dev_check = $conn->query("SELECT setting_value FROM developer_settings WHERE setting_key='developer_mode'");
            $dev_mode = $dev_check ? ($dev_check->fetch_row()[0] ?? 'false') : 'false';
            if ($dev_mode !== 'true') {
                echo json_encode(['success' => false, 'error' => 'Developer mode must be ON to rename files.']);
                exit;
            }
            
            $old_path = $_POST['path'] ?? '';
            $new_name = $_POST['new_name'] ?? '';
            
            if (empty($new_name) || preg_match('/[\/\\<>:"|?*]/', $new_name)) {
                echo json_encode(['success' => false, 'error' => 'Invalid name']);
                exit;
            }
            
            $full_old = realpath($base_path . '/' . $old_path);
            if (!$full_old || strpos($full_old, realpath($base_path)) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            $parent_dir = dirname($full_old);
            $full_new = $parent_dir . '/' . $new_name;
            
            if (file_exists($full_new)) {
                echo json_encode(['success' => false, 'error' => 'A file/folder with that name already exists']);
                exit;
            }
            
            $result = @rename($full_old, $full_new);
            if ($result) {
                $conn->query("INSERT INTO system_audit_log (user_id, action, details, ip_address) VALUES (
                    {$_SESSION['user_id']}, 'file_rename', 'Renamed: $old_path -> $new_name', '{$_SERVER['REMOTE_ADDR']}'
                )");
            }
            echo json_encode(['success' => $result]);
            exit;
        
        case 'upload':
            // Check developer mode
            $dev_check = $conn->query("SELECT setting_value FROM developer_settings WHERE setting_key='developer_mode'");
            $dev_mode = $dev_check ? ($dev_check->fetch_row()[0] ?? 'false') : 'false';
            if ($dev_mode !== 'true') {
                echo json_encode(['success' => false, 'error' => 'Developer mode must be ON to upload files.']);
                exit;
            }
            
            $dir = $_POST['dir'] ?? '/';
            $full_dir = realpath($base_path . '/' . $dir);
            
            if (!$full_dir || strpos($full_dir, realpath($base_path)) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            if (!isset($_FILES['upload_file']) || $_FILES['upload_file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'Upload failed (error: ' . ($_FILES['upload_file']['error'] ?? 'no file') . ')']);
                exit;
            }
            
            $upload = $_FILES['upload_file'];
            $dest = $full_dir . '/' . basename($upload['name']);
            
            // Prevent overwrite without confirmation
            if (file_exists($dest) && empty($_POST['overwrite'])) {
                echo json_encode(['success' => false, 'error' => 'File already exists. Set overwrite=1 to replace.', 'exists' => true]);
                exit;
            }
            
            $result = move_uploaded_file($upload['tmp_name'], $dest);
            if ($result) {
                $conn->query("INSERT INTO system_audit_log (user_id, action, details, ip_address) VALUES (
                    {$_SESSION['user_id']}, 'file_upload', 'Uploaded: $dir/" . basename($upload['name']) . "', '{$_SERVER['REMOTE_ADDR']}'
                )");
            }
            echo json_encode(['success' => $result, 'filename' => basename($upload['name']), 'size' => $upload['size']]);
            exit;
        
        case 'search':
            $query = trim($_POST['query'] ?? '');
            $search_path = $_POST['search_path'] ?? '/';
            
            if (strlen($query) < 2) {
                echo json_encode(['success' => false, 'error' => 'Search query must be at least 2 characters']);
                exit;
            }
            
            $full_base = realpath($base_path . '/' . $search_path);
            if (!$full_base || strpos($full_base, realpath($base_path)) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            $results = [];
            $maxResults = 100;
            $searchInContent = !empty($_POST['content_search']);
            
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($full_base, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                if (count($results) >= $maxResults) break;
                
                $rel_path = str_replace(realpath($base_path), '', $file->getPathname());
                $rel_path = str_replace('\\', '/', $rel_path);
                
                // Skip blocked dirs
                $skip = false;
                foreach ($blocked as $b) {
                    if (strpos($rel_path, '/' . $b . '/') !== false || strpos($rel_path, '/' . $b) === strrpos($rel_path, '/' . $b)) {
                        if (strpos($rel_path, '/' . $b) !== false) { $skip = true; break; }
                    }
                }
                if ($skip) continue;
                
                // Match filename
                if (stripos($file->getFilename(), $query) !== false) {
                    $results[] = [
                        'path' => $rel_path,
                        'name' => $file->getFilename(),
                        'is_dir' => $file->isDir(),
                        'size' => $file->isFile() ? $file->getSize() : 0,
                        'match_type' => 'filename'
                    ];
                    continue;
                }
                
                // Content search (only text files under 1MB)
                if ($searchInContent && $file->isFile() && $file->getSize() < 1024 * 1024) {
                    $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                    $textExts = ['php','html','css','js','json','txt','md','xml','yml','yaml','ini','conf','sh','bat','sql','htaccess','env'];
                    if (in_array($ext, $textExts)) {
                        $content = @file_get_contents($file->getPathname());
                        if ($content && stripos($content, $query) !== false) {
                            // Find line number
                            $lines = explode("\n", $content);
                            $matchLine = 0;
                            $matchContext = '';
                            foreach ($lines as $i => $line) {
                                if (stripos($line, $query) !== false) {
                                    $matchLine = $i + 1;
                                    $matchContext = trim(substr($line, 0, 200));
                                    break;
                                }
                            }
                            $results[] = [
                                'path' => $rel_path,
                                'name' => $file->getFilename(),
                                'is_dir' => false,
                                'size' => $file->getSize(),
                                'match_type' => 'content',
                                'line' => $matchLine,
                                'context' => $matchContext
                            ];
                        }
                    }
                }
            }
            
            echo json_encode(['success' => true, 'results' => $results, 'query' => $query, 'total' => count($results)]);
            exit;

        case 'download':
            $file = $_POST['path'] ?? '';
            $full_path = realpath($base_path . '/' . $file);
            
            if (!$full_path || strpos($full_path, realpath($base_path)) !== 0 || !is_file($full_path)) {
                echo json_encode(['success' => false, 'error' => 'File not found']);
                exit;
            }
            
            // Return base64 encoded content for JS-based download
            $content = file_get_contents($full_path);
            echo json_encode([
                'success' => true,
                'content' => base64_encode($content),
                'filename' => basename($full_path),
                'size' => filesize($full_path),
                'mime' => mime_content_type($full_path) ?: 'application/octet-stream'
            ]);
            exit;
    }
}

// ---- Direct download handler ----
if (isset($_GET['download'])) {
    $file = $_GET['download'];
    $full_path = realpath($base_path . '/' . $file);
    
    if ($full_path && strpos($full_path, realpath($base_path)) === 0 && is_file($full_path)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($full_path) . '"');
        header('Content-Length: ' . filesize($full_path));
        header('Cache-Control: must-revalidate');
        readfile($full_path);
        exit;
    }
    header('HTTP/1.1 404 Not Found');
    echo 'File not found';
    exit;
}
?>

<!-- File Manager UI -->
<div class="card" style="padding:0;">
    <div style="display:flex; height:calc(100vh - 140px); min-height:600px;">
        
        <!-- Left Panel: File Tree -->
        <div id="file-panel" style="width:350px; border-right:1px solid var(--border-color); display:flex; flex-direction:column; background:#fafbfc;">
            <!-- Toolbar -->
            <div style="padding:0.75rem; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:0.5rem; background:#f1f5f9;">
                <button onclick="loadDir('/')" class="btn btn-primary btn-sm" title="Root"><i class="fas fa-home"></i></button>
                <button onclick="goUp()" class="btn btn-primary btn-sm" title="Up" id="btn-up"><i class="fas fa-arrow-up"></i></button>
                <button onclick="loadDir(currentPath)" class="btn btn-primary btn-sm" title="Refresh"><i class="fas fa-sync"></i></button>
                <button onclick="showSearchDialog()" class="btn btn-primary btn-sm" title="Search Files"><i class="fas fa-search"></i></button>
                <div style="flex:1;"></div>
                <button onclick="showUploadDialog()" class="btn btn-success btn-sm" title="Upload File"><i class="fas fa-upload"></i> Upload</button>
                <button onclick="showNewFileDialog()" class="btn btn-success btn-sm" title="New File"><i class="fas fa-file-plus"></i> +File</button>
                <button onclick="showNewDirDialog()" class="btn btn-success btn-sm" title="New Folder"><i class="fas fa-folder-plus"></i> +Dir</button>
            </div>
            
            <!-- Breadcrumb -->
            <div id="breadcrumb" style="padding:0.5rem 0.75rem; font-size:0.8rem; color:var(--text-muted); background:#f8fafc; border-bottom:1px solid var(--border-color); word-break:break-all;">
                /
            </div>
            
            <!-- File List -->
            <div id="file-list" style="flex:1; overflow-y:auto; font-size:0.85rem;">
                <div style="padding:2rem; text-align:center; color:var(--text-muted);">
                    <i class="fas fa-spinner fa-spin"></i> Loading...
                </div>
            </div>
        </div>
        
        <!-- Right Panel: Editor -->
        <div style="flex:1; display:flex; flex-direction:column;">
            <!-- Editor Toolbar -->
            <div id="editor-toolbar" style="padding:0.5rem 0.75rem; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:0.5rem; background:#f1f5f9;">
                <span id="editor-filename" style="font-weight:600; font-size:0.9rem; color:var(--text-main);">No file selected</span>
                <span id="editor-language" class="badge badge-info" style="font-size:0.7rem; margin-left:0.5rem;"></span>
                <span id="editor-modified" class="badge badge-warning" style="display:none;">Modified</span>
                <div style="flex:1;"></div>
                <span id="editor-size" style="font-size:0.75rem; color:var(--text-muted);"></span>
                <button onclick="saveFile()" class="btn btn-success btn-sm" id="btn-save" disabled><i class="fas fa-save"></i> Save</button>
                <button onclick="downloadCurrentFile()" class="btn btn-primary btn-sm" id="btn-download" disabled><i class="fas fa-download"></i> Download</button>
            </div>
            
            <!-- Editor Area -->
            <div id="editor-area" style="flex:1; position:relative;">
                <div id="editor-welcome" style="display:flex; align-items:center; justify-content:center; height:100%; color:var(--text-muted); flex-direction:column; gap:1rem;">
                    <i class="fas fa-file-code" style="font-size:4rem; opacity:0.3;"></i>
                    <p>Select a file to edit</p>
                    <p style="font-size:0.8rem;">Click any file in the left panel to open it</p>
                </div>
                <textarea id="code-editor" style="display:none; width:100%; height:100%; border:none; padding:1rem; font-family:'Fira Code', 'Cascadia Code', monospace; font-size:0.85rem; line-height:1.6; resize:none; background:#1e1e1e; color:#d4d4d4; outline:none; tab-size:4;" spellcheck="false"></textarea>
            </div>
            
            <!-- Status Bar -->
            <div id="status-bar" style="padding:0.25rem 0.75rem; border-top:1px solid var(--border-color); font-size:0.75rem; color:var(--text-muted); background:#f8fafc; display:flex; justify-content:space-between;">
                <span id="status-left">Ready</span>
                <span id="status-right"></span>
            </div>
        </div>
    </div>
</div>

<style>
#file-list .file-item {
    display: flex;
    align-items: center;
    padding: 0.4rem 0.75rem;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    gap: 0.5rem;
    transition: background 0.15s;
}
#file-list .file-item:hover { background: #e2e8f0; }
#file-list .file-item.active { background: #dbeafe; border-left: 3px solid var(--primary); }
#file-list .file-item .icon { width: 20px; text-align: center; flex-shrink: 0; }
#file-list .file-item .name { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
#file-list .file-item .meta { font-size: 0.7rem; color: #94a3b8; white-space: nowrap; }
#file-list .file-item .actions { display: none; gap: 2px; }
#file-list .file-item:hover .actions { display: flex; }
#file-list .file-item:hover .meta { display: none; }
.file-item .actions button { background: none; border: none; cursor: pointer; padding: 2px 4px; border-radius: 3px; font-size: 0.75rem; }
.file-item .actions button:hover { background: rgba(0,0,0,0.1); }
.file-item .actions .del-btn { color: var(--danger); }
.file-item .actions .dl-btn { color: var(--primary); }
.file-item .actions .ren-btn { color: var(--success); }

/* Upload drop zone */
.upload-zone {
    border: 2px dashed var(--border-color);
    border-radius: 0.75rem;
    padding: 1.5rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 1rem;
}
.upload-zone:hover { border-color: var(--primary); background: #f8fafc; }
.upload-zone.dragover { border-color: var(--success); background: #dcfce7; }

/* Search results */
.search-result-item {
    display: flex;
    align-items: center;
    padding: 0.5rem 0.75rem;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    gap: 0.5rem;
    font-size: 0.85rem;
}
.search-result-item:hover { background: #f0f9ff; }

/* Dark editor theme */
#code-editor::selection { background: #264f78; }
#code-editor::-webkit-scrollbar { width: 10px; height: 10px; }
#code-editor::-webkit-scrollbar-track { background: #1e1e1e; }
#code-editor::-webkit-scrollbar-thumb { background: #424242; border-radius: 5px; }

/* Modal overlay */
.modal-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5); z-index: 1000;
    display: flex; align-items: center; justify-content: center;
}
.modal-box {
    background: white; border-radius: 12px; padding: 1.5rem;
    min-width: 350px; max-width: 500px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
}
</style>

<script>
let currentPath = '/';
let currentFile = null;
let originalContent = '';
let isModified = false;

function formatSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function getFileIcon(item) {
    if (item.is_dir) return '<i class="fas fa-folder" style="color:#f59e0b;"></i>';
    const icons = {
        'php': '<i class="fab fa-php" style="color:#777bb4;"></i>',
        'html': '<i class="fab fa-html5" style="color:#e34f26;"></i>',
        'css': '<i class="fab fa-css3-alt" style="color:#1572b6;"></i>',
        'js': '<i class="fab fa-js" style="color:#f7df1e;"></i>',
        'json': '<i class="fas fa-brackets-curly" style="color:#f59e0b;"></i>',
        'sql': '<i class="fas fa-database" style="color:#336791;"></i>',
        'md': '<i class="fab fa-markdown" style="color:#083fa1;"></i>',
        'sh': '<i class="fas fa-terminal" style="color:#4ade80;"></i>',
        'bat': '<i class="fas fa-terminal" style="color:#0078d4;"></i>',
        'yml': '<i class="fas fa-cog" style="color:#cb171e;"></i>',
        'yaml': '<i class="fas fa-cog" style="color:#cb171e;"></i>',
        'png': '<i class="fas fa-image" style="color:#ec4899;"></i>',
        'jpg': '<i class="fas fa-image" style="color:#ec4899;"></i>',
        'jpeg': '<i class="fas fa-image" style="color:#ec4899;"></i>',
        'gif': '<i class="fas fa-image" style="color:#ec4899;"></i>',
        'svg': '<i class="fas fa-image" style="color:#ec4899;"></i>',
        'pdf': '<i class="fas fa-file-pdf" style="color:#ef4444;"></i>',
        'txt': '<i class="fas fa-file-alt" style="color:#64748b;"></i>',
    };
    return icons[item.extension] || '<i class="fas fa-file" style="color:#94a3b8;"></i>';
}

function loadDir(path) {
    currentPath = path;
    document.getElementById('file-list').innerHTML = '<div style="padding:2rem; text-align:center; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    
    fetch('file_manager.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=list&path=${encodeURIComponent(path)}`
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            document.getElementById('file-list').innerHTML = `<div style="padding:1rem; color:var(--danger);">${data.error}</div>`;
            return;
        }
        
        // Update breadcrumb
        updateBreadcrumb(path);
        
        let html = '';
        if (path !== '/') {
            html += `<div class="file-item" ondblclick="goUp()">
                <span class="icon"><i class="fas fa-level-up-alt" style="color:#64748b;"></i></span>
                <span class="name" style="color:#64748b;">..</span>
            </div>`;
        }
        
        data.items.forEach(item => {
            const icon = getFileIcon(item);
            const size = item.is_dir ? '' : formatSize(item.size);
            const clickAction = item.is_dir 
                ? `ondblclick="loadDir('${item.path}')"` 
                : (item.editable ? `onclick="openFile('${item.path}')" ondblclick="openFile('${item.path}')"` : '');
            
            html += `<div class="file-item" ${clickAction} data-path="${item.path}">
                <span class="icon">${icon}</span>
                <span class="name">${item.name}</span>
                <span class="meta">${size}</span>
                <span class="actions">
                    <button class="ren-btn" onclick="event.stopPropagation(); renameFile('${item.path}', '${item.name}')" title="Rename"><i class="fas fa-pen"></i></button>
                    <button class="dl-btn" onclick="event.stopPropagation(); downloadFile('${item.path}')" title="Download"><i class="fas fa-download"></i></button>
                    <button class="del-btn" onclick="event.stopPropagation(); deleteFile('${item.path}', '${item.name}')" title="Delete"><i class="fas fa-trash"></i></button>
                </span>
            </div>`;
        });
        
        if (data.items.length === 0 && path === '/') {
            html = '<div style="padding:2rem; text-align:center; color:var(--text-muted);">Empty directory</div>';
        }
        
        document.getElementById('file-list').innerHTML = html;
        document.getElementById('status-left').textContent = `${data.items.length} items in ${path}`;
    })
    .catch(err => {
        document.getElementById('file-list').innerHTML = `<div style="padding:1rem; color:var(--danger);">Error: ${err.message}</div>`;
    });
}

function updateBreadcrumb(path) {
    const parts = path.split('/').filter(p => p);
    let html = '<a href="#" onclick="loadDir(\'/\'); return false;" style="color:var(--primary); text-decoration:none;">root</a>';
    let accumulated = '';
    parts.forEach(part => {
        accumulated += '/' + part;
        const p = accumulated;
        html += ` / <a href="#" onclick="loadDir('${p}'); return false;" style="color:var(--primary); text-decoration:none;">${part}</a>`;
    });
    document.getElementById('breadcrumb').innerHTML = html;
}

function goUp() {
    const parts = currentPath.split('/').filter(p => p);
    parts.pop();
    loadDir('/' + parts.join('/'));
}

function openFile(path) {
    // Check for unsaved changes
    if (isModified && !confirm('You have unsaved changes. Discard them?')) return;
    
    document.getElementById('status-left').textContent = 'Opening ' + path + '...';
    
    fetch('file_manager.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=read&path=${encodeURIComponent(path)}`
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            alert(data.error);
            return;
        }
        
        currentFile = path;
        originalContent = data.content;
        isModified = false;
        
        const editor = document.getElementById('code-editor');
        const welcome = document.getElementById('editor-welcome');
        
        welcome.style.display = 'none';
        editor.style.display = 'block';
        editor.value = data.content;
        
        document.getElementById('editor-filename').textContent = path.split('/').pop();
        document.getElementById('editor-language').textContent = data.language;
        document.getElementById('editor-size').textContent = formatSize(data.size);
        document.getElementById('editor-modified').style.display = 'none';
        document.getElementById('btn-save').disabled = !data.writable;
        document.getElementById('btn-download').disabled = false;
        
        document.getElementById('status-left').textContent = 'Opened: ' + path;
        document.getElementById('status-right').textContent = data.writable ? 'Writable' : 'Read-only';
        
        // Highlight active file in list
        document.querySelectorAll('.file-item').forEach(el => el.classList.remove('active'));
        const activeEl = document.querySelector(`.file-item[data-path="${path}"]`);
        if (activeEl) activeEl.classList.add('active');
    });
}

function saveFile() {
    if (!currentFile) return;
    
    const content = document.getElementById('code-editor').value;
    document.getElementById('status-left').textContent = 'Saving...';
    
    fetch('file_manager.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=save&path=${encodeURIComponent(currentFile)}&content=${encodeURIComponent(content)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            originalContent = content;
            isModified = false;
            document.getElementById('editor-modified').style.display = 'none';
            document.getElementById('status-left').textContent = `Saved: ${currentFile} (${data.bytes} bytes) | Backup: ${data.backup}`;
        } else {
            alert('Save failed: ' + data.error);
            document.getElementById('status-left').textContent = 'Save FAILED: ' + data.error;
        }
    });
}

function downloadFile(path) {
    // Direct download via GET
    window.location.href = 'file_manager.php?download=' + encodeURIComponent(path);
}

function downloadCurrentFile() {
    if (currentFile) downloadFile(currentFile);
}

function deleteFile(path, name) {
    if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;
    
    fetch('file_manager.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete&path=${encodeURIComponent(path)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadDir(currentPath);
            if (currentFile === path) {
                currentFile = null;
                document.getElementById('code-editor').style.display = 'none';
                document.getElementById('editor-welcome').style.display = 'flex';
            }
        } else {
            alert('Delete failed: ' + (data.error || 'Unknown error'));
        }
    });
}

function showNewFileDialog() {
    const name = prompt('New file name:', 'new_file.php');
    if (!name) return;
    
    fetch('file_manager.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=create_file&dir=${encodeURIComponent(currentPath)}&filename=${encodeURIComponent(name)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadDir(currentPath);
        } else {
            alert('Error: ' + (data.error || 'Failed'));
        }
    });
}

function showNewDirDialog() {
    const name = prompt('New directory name:', 'new_folder');
    if (!name) return;
    
    fetch('file_manager.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=create_dir&dir=${encodeURIComponent(currentPath)}&dirname=${encodeURIComponent(name)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadDir(currentPath);
        } else {
            alert('Error: ' + (data.error || 'Failed'));
        }
    });
}

// ---- Editor Events ----
document.getElementById('code-editor').addEventListener('input', () => {
    const content = document.getElementById('code-editor').value;
    isModified = content !== originalContent;
    document.getElementById('editor-modified').style.display = isModified ? 'inline' : 'none';
});

// Ctrl+S to save
document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        if (currentFile && !document.getElementById('btn-save').disabled) {
            saveFile();
        }
    }
});

// Tab key inserts tab in editor
document.getElementById('code-editor').addEventListener('keydown', (e) => {
    if (e.key === 'Tab') {
        e.preventDefault();
        const editor = e.target;
        const start = editor.selectionStart;
        const end = editor.selectionEnd;
        editor.value = editor.value.substring(0, start) + '    ' + editor.value.substring(end);
        editor.selectionStart = editor.selectionEnd = start + 4;
        editor.dispatchEvent(new Event('input'));
    }
});

// Warn before leaving with unsaved changes
window.addEventListener('beforeunload', (e) => {
    if (isModified) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// ---- Rename ----
function renameFile(path, currentName) {
    const newName = prompt('Rename to:', currentName);
    if (!newName || newName === currentName) return;
    
    fetch('file_manager.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=rename&path=${encodeURIComponent(path)}&new_name=${encodeURIComponent(newName)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadDir(currentPath);
            if (currentFile === path) {
                // Update current file reference
                const parts = path.split('/');
                parts[parts.length - 1] = newName;
                currentFile = parts.join('/');
                document.getElementById('editor-filename').textContent = newName;
            }
        } else {
            alert('Rename failed: ' + (data.error || 'Unknown error'));
        }
    });
}

// ---- Upload ----
function showUploadDialog() {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'upload-modal';
    overlay.innerHTML = `
        <div class="modal-box" style="min-width:420px;">
            <h3 style="margin:0 0 1rem;"><i class="fas fa-upload"></i> Upload File</h3>
            <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:1rem;">Upload to: <strong>${currentPath}</strong></p>
            <div class="upload-zone" id="upload-drop-zone" onclick="document.getElementById('upload-input').click()">
                <i class="fas fa-cloud-upload-alt" style="font-size:2rem; color:var(--primary); opacity:0.5;"></i>
                <p style="margin:0.5rem 0 0; font-weight:600;">Drop file here or click to browse</p>
                <input type="file" id="upload-input" style="display:none;" onchange="handleUpload(this)">
            </div>
            <div id="upload-progress" style="display:none; margin-bottom:1rem;">
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <i class="fas fa-spinner fa-spin" style="color:var(--primary);"></i>
                    <span id="upload-status">Uploading...</span>
                </div>
            </div>
            <div style="text-align:right;">
                <button class="btn btn-danger btn-sm" onclick="document.getElementById('upload-modal').remove()"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    
    // Drag and drop
    const zone = document.getElementById('upload-drop-zone');
    zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
    zone.addEventListener('drop', (e) => {
        e.preventDefault();
        zone.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            document.getElementById('upload-input').files = e.dataTransfer.files;
            handleUpload(document.getElementById('upload-input'));
        }
    });
}

function handleUpload(input) {
    const file = input.files[0];
    if (!file) return;
    
    document.getElementById('upload-progress').style.display = 'block';
    document.getElementById('upload-status').textContent = `Uploading ${file.name} (${formatSize(file.size)})...`;
    
    const formData = new FormData();
    formData.append('action', 'upload');
    formData.append('dir', currentPath);
    formData.append('upload_file', file);
    
    fetch('file_manager.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('upload-status').innerHTML = `<span style="color:var(--success);"><i class="fas fa-check-circle"></i> ${data.filename} uploaded (${formatSize(data.size)})</span>`;
            loadDir(currentPath);
        } else if (data.exists) {
            if (confirm(`${file.name} already exists. Overwrite?`)) {
                const formData2 = new FormData();
                formData2.append('action', 'upload');
                formData2.append('dir', currentPath);
                formData2.append('upload_file', file);
                formData2.append('overwrite', '1');
                fetch('file_manager.php', { method: 'POST', body: formData2 })
                .then(r => r.json())
                .then(d => {
                    document.getElementById('upload-status').innerHTML = d.success
                        ? `<span style="color:var(--success);"><i class="fas fa-check-circle"></i> ${d.filename} uploaded</span>`
                        : `<span style="color:var(--danger);">${d.error}</span>`;
                    if (d.success) loadDir(currentPath);
                });
            } else {
                document.getElementById('upload-progress').style.display = 'none';
            }
        } else {
            document.getElementById('upload-status').innerHTML = `<span style="color:var(--danger);"><i class="fas fa-times-circle"></i> ${data.error}</span>`;
        }
    });
}

// ---- Search ----
function showSearchDialog() {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'search-modal';
    overlay.innerHTML = `
        <div class="modal-box" style="min-width:550px; max-height:80vh; display:flex; flex-direction:column;">
            <h3 style="margin:0 0 1rem;"><i class="fas fa-search"></i> Search Files</h3>
            <div style="display:flex; gap:0.5rem; margin-bottom:0.75rem;">
                <input type="text" id="search-query" placeholder="Search for files or content..." 
                    style="flex:1; padding:0.5rem 0.75rem; border:1px solid var(--border-color); border-radius:8px; font-size:0.9rem;"
                    onkeydown="if(event.key==='Enter') performSearch()">
                <label style="display:flex; align-items:center; gap:0.25rem; font-size:0.8rem; white-space:nowrap;">
                    <input type="checkbox" id="search-content" checked> Content
                </label>
                <button class="btn btn-primary btn-sm" onclick="performSearch()"><i class="fas fa-search"></i></button>
            </div>
            <div id="search-results" style="flex:1; overflow-y:auto; max-height:400px; border:1px solid var(--border-color); border-radius:8px;">
                <div style="padding:2rem; text-align:center; color:var(--text-muted);">Type a query and press Enter</div>
            </div>
            <div style="text-align:right; margin-top:0.75rem;">
                <button class="btn btn-danger btn-sm" onclick="document.getElementById('search-modal').remove()"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    document.getElementById('search-query').focus();
}

function performSearch() {
    const query = document.getElementById('search-query').value.trim();
    if (query.length < 2) return;
    
    const contentSearch = document.getElementById('search-content').checked;
    document.getElementById('search-results').innerHTML = '<div style="padding:1.5rem; text-align:center;"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
    
    fetch('file_manager.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=search&query=${encodeURIComponent(query)}&search_path=${encodeURIComponent(currentPath)}&content_search=${contentSearch ? '1' : ''}`
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            document.getElementById('search-results').innerHTML = `<div style="padding:1rem; color:var(--danger);">${data.error}</div>`;
            return;
        }
        
        if (data.results.length === 0) {
            document.getElementById('search-results').innerHTML = '<div style="padding:1.5rem; text-align:center; color:var(--text-muted);">No results found</div>';
            return;
        }
        
        let html = `<div style="padding:0.5rem 0.75rem; font-size:0.8rem; color:var(--text-muted); background:#f8fafc; border-bottom:1px solid var(--border-color);">${data.total} result(s)</div>`;
        data.results.forEach(r => {
            const icon = r.is_dir ? '<i class="fas fa-folder" style="color:#f59e0b;"></i>' : '<i class="fas fa-file" style="color:#94a3b8;"></i>';
            const action = r.is_dir 
                ? `onclick="document.getElementById('search-modal').remove(); loadDir('${r.path}')"` 
                : `onclick="document.getElementById('search-modal').remove(); openFile('${r.path}')"`;
            
            html += `<div class="search-result-item" ${action}>
                ${icon}
                <div style="flex:1; min-width:0;">
                    <div style="font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${r.name}</div>
                    <div style="font-size:0.75rem; color:#94a3b8;">${r.path}</div>
                    ${r.match_type === 'content' ? `<div style="font-size:0.75rem; color:var(--primary);">Line ${r.line}: ${escapeHtml(r.context || '').substring(0, 100)}</div>` : ''}
                </div>
                <span style="font-size:0.75rem; color:#94a3b8;">${r.match_type === 'content' ? '<i class="fas fa-file-code"></i>' : '<i class="fas fa-font"></i>'}</span>
            </div>`;
        });
        document.getElementById('search-results').innerHTML = html;
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Load root directory on page load
loadDir('/');
</script>

<?php require_once 'includes/footer.php'; ?>
