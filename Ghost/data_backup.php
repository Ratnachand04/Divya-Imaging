<?php
/**
 * =============================================================
 * Data Backup Management - Ghost Developer Console
 * =============================================================
 * UI for creating, searching, browsing, and downloading backups.
 * Structure: data_backup/YEAR/MONTH/backup_*.sql
 * =============================================================
 */
require_once 'includes/header.php';
require_once __DIR__ . '/../data_backup/backup_engine.php';
require_once __DIR__ . '/../data_backup/search_backups.php';

// ---- Handle AJAX Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    switch ($_POST['action']) {
        // ---- Create backup now ----
        case 'create_backup':
            $force = ($_POST['force'] ?? 'false') === 'true';
            $result = run_monthly_backup($conn, $force);
            echo json_encode($result);
            exit;

        // ---- Search index (fast metadata search) ----
        case 'search_index':
            $filters = [
                'year'      => $_POST['year'] ?? '',
                'month'     => $_POST['month'] ?? '',
                'table'     => $_POST['table'] ?? '',
                'keyword'   => $_POST['keyword'] ?? '',
                'date_from' => $_POST['date_from'] ?? '',
                'date_to'   => $_POST['date_to'] ?? '',
            ];
            $results = search_backup_index($filters);
            echo json_encode(['success' => true, 'results' => $results, 'count' => count($results)]);
            exit;

        // ---- Deep search inside SQL files ----
        case 'deep_search':
            $term = $_POST['search_term'] ?? '';
            if (strlen($term) < 2) {
                echo json_encode(['success' => false, 'error' => 'Search term must be at least 2 characters']);
                exit;
            }
            $filters = [
                'year'  => $_POST['year'] ?? '',
                'month' => $_POST['month'] ?? '',
            ];
            $max = min((int)($_POST['max_results'] ?? 50), 200);
            $results = deep_search_backups($term, $filters, $max);
            echo json_encode(['success' => true, 'data' => $results]);
            exit;

        // ---- Get stats ----
        case 'get_stats':
            echo json_encode(['success' => true, 'stats' => get_backup_stats()]);
            exit;

        // ---- Delete backup ----
        case 'delete_backup':
            $file = $_POST['file'] ?? '';
            if (empty($file)) {
                echo json_encode(['success' => false, 'error' => 'No file specified']);
                exit;
            }
            $result = delete_backup($file);
            echo json_encode($result);
            exit;

        // ---- Download backup ----
        case 'download_backup':
            $file = $_POST['file'] ?? '';
            $base = realpath(__DIR__ . '/../data_backup');
            $full = realpath($base . '/' . $file);
            if (!$full || strpos($full, $base) !== 0 || !file_exists($full)) {
                echo json_encode(['success' => false, 'error' => 'File not found']);
                exit;
            }
            // Return path for download
            echo json_encode(['success' => true, 'download_url' => '../data_backup/' . $file]);
            exit;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            exit;
    }
}

// ---- Page Data ----
$stats = get_backup_stats();
$all_backups = search_backup_index();
$month_names = ['01'=>'January','02'=>'February','03'=>'March','04'=>'April','05'=>'May','06'=>'June',
                '07'=>'July','08'=>'August','09'=>'September','10'=>'October','11'=>'November','12'=>'December'];
?>

<!-- Page Content -->
<div class="card">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <div>
            <h2 style="margin:0;"><i class="fas fa-database"></i> Data Backup Manager</h2>
            <p style="margin:0.25rem 0 0; color:var(--text-muted); font-size:0.9rem;">
                Monthly SQL backups &bull; Folder: <code>data_backup/YEAR/MONTH/</code>
            </p>
        </div>
        <div style="display:flex; gap:8px;">
            <button class="btn btn-success" onclick="createBackup(false)" id="btnBackup">
                <i class="fas fa-plus-circle"></i> Create Monthly Backup
            </button>
            <button class="btn btn-primary" onclick="createBackup(true)">
                <i class="fas fa-redo"></i> Force New Backup
            </button>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="stats-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card stat-primary">
        <div class="stat-icon"><i class="fas fa-archive"></i></div>
        <div class="stat-content">
            <h3><?php echo $stats['total_backups']; ?></h3>
            <p>Total Backups</p>
        </div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-icon"><i class="fas fa-hdd"></i></div>
        <div class="stat-content">
            <h3><?php echo $stats['size_human'] ?? '0 bytes'; ?></h3>
            <p>Total Size</p>
        </div>
    </div>
    <div class="stat-card stat-warning">
        <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
        <div class="stat-content">
            <h3><?php echo count($stats['years']); ?></h3>
            <p>Years Covered</p>
        </div>
    </div>
    <div class="stat-card" style="border-color:#8b5cf6;">
        <div class="stat-icon" style="background:#ede9fe; color:#7c3aed;"><i class="fas fa-clock"></i></div>
        <div class="stat-content">
            <h3 style="font-size:0.95rem;"><?php echo $stats['latest'] ? $stats['latest']['created_at'] : 'None'; ?></h3>
            <p>Latest Backup</p>
        </div>
    </div>
</div>

<!-- Search & Filter Section -->
<div class="card">
    <h3><i class="fas fa-search"></i> Search Backups</h3>
    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:1rem;">
        <select id="filterYear" class="form-input" style="width:120px;">
            <option value="">All Years</option>
            <?php foreach ($stats['years'] as $y => $months): ?>
            <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
            <?php endforeach; ?>
        </select>
        <select id="filterMonth" class="form-input" style="width:140px;">
            <option value="">All Months</option>
            <?php foreach ($month_names as $num => $name): ?>
            <option value="<?php echo $num; ?>"><?php echo $name; ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="filterKeyword" class="form-input" placeholder="Search keyword..." style="flex:1; min-width:200px;"
               onkeyup="if(event.key==='Enter') searchIndex()">
        <button class="btn btn-primary" onclick="searchIndex()"><i class="fas fa-search"></i> Search Index</button>
    </div>

    <!-- Deep Search -->
    <div style="display:flex; gap:10px; flex-wrap:wrap; padding:12px; background:#f8fafc; border-radius:8px; border:1px dashed #cbd5e1;">
        <input type="text" id="deepSearchTerm" class="form-input" placeholder="Search inside SQL files..." style="flex:1; min-width:200px;"
               onkeyup="if(event.key==='Enter') deepSearch()">
        <button class="btn btn-warning" onclick="deepSearch()" style="color:#fff;">
            <i class="fas fa-file-code"></i> Deep Search
        </button>
        <span style="font-size:0.8rem; color:var(--text-muted); align-self:center;">
            Streams through files &mdash; no extra memory used
        </span>
    </div>
</div>

<!-- Results Area -->
<div class="card" id="resultsCard" style="display:none;">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h3 id="resultsTitle"><i class="fas fa-list"></i> Results</h3>
        <span id="resultsCount" style="font-size:0.85rem; color:var(--text-muted);"></span>
    </div>
    <div id="resultsBody" style="max-height:500px; overflow-y:auto;"></div>
</div>

<!-- Backup List (Tree View) -->
<div class="card">
    <h3><i class="fas fa-folder-open"></i> Backup Archive</h3>
    <?php if (empty($all_backups)): ?>
        <p style="color:var(--text-muted); text-align:center; padding:2rem;">
            <i class="fas fa-inbox" style="font-size:2rem; display:block; margin-bottom:0.5rem;"></i>
            No backups yet. Click "Create Monthly Backup" to get started.
        </p>
    <?php else: ?>
        <?php
        // Group by year > month
        $tree = [];
        foreach ($all_backups as $b) {
            $tree[$b['year']][$b['month']][] = $b;
        }
        krsort($tree); // newest year first
        ?>
        <?php foreach ($tree as $year => $months): ?>
        <div style="margin-bottom:1rem;">
            <div style="font-weight:600; font-size:1.1rem; margin-bottom:0.5rem; cursor:pointer;" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display==='none'?'block':'none'">
                <i class="fas fa-folder" style="color:#f59e0b;"></i> <?php echo $year; ?>
                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:400;">(<?php echo count($months); ?> months)</span>
            </div>
            <div style="padding-left:1.5rem;">
                <?php krsort($months); foreach ($months as $month => $backups): ?>
                <div style="margin-bottom:0.5rem;">
                    <div style="font-weight:500; cursor:pointer;" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display==='none'?'block':'none'">
                        <i class="fas fa-folder-open" style="color:#3b82f6;"></i>
                        <?php echo $month_names[$month] ?? $month; ?>
                        <span style="font-size:0.8rem; color:var(--text-muted);">(<?php echo count($backups); ?> backup<?php echo count($backups)>1?'s':''; ?>)</span>
                    </div>
                    <div style="padding-left:1.5rem;">
                        <?php foreach ($backups as $b): ?>
                        <div style="display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:0.9rem;">
                            <i class="fas fa-file-code" style="color:#6b7280;"></i>
                            <span style="flex:1;"><?php echo htmlspecialchars(basename($b['file'])); ?></span>
                            <span style="color:var(--text-muted); font-size:0.8rem;">
                                <?php echo $b['size_human'] ?? ''; ?>
                                &bull; <?php echo ($b['total_rows'] ?? 0); ?> rows
                                &bull; <?php echo count($b['tables'] ?? []); ?> tables
                            </span>
                            <button class="btn btn-sm btn-primary" onclick="downloadBackup('<?php echo htmlspecialchars($b['file'], ENT_QUOTES); ?>')" title="Download">
                                <i class="fas fa-download"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="deleteBackup('<?php echo htmlspecialchars($b['file'], ENT_QUOTES); ?>')" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Status message -->
<div id="statusMsg" style="display:none; position:fixed; bottom:20px; right:20px; padding:12px 20px; border-radius:8px; color:white; font-weight:500; z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,0.15);"></div>

<script>
function showStatus(msg, type = 'success') {
    const el = document.getElementById('statusMsg');
    el.style.background = type === 'success' ? '#22c55e' : type === 'error' ? '#ef4444' : '#3b82f6';
    el.textContent = msg;
    el.style.display = 'block';
    setTimeout(() => el.style.display = 'none', 4000);
}

function createBackup(force) {
    const btn = document.getElementById('btnBackup');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';

    fetch('data_backup.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=create_backup&force=' + (force ? 'true' : 'false')
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plus-circle"></i> Create Monthly Backup';
        if (data.success) {
            showStatus(data.message);
            if (!data.skipped) setTimeout(() => location.reload(), 1500);
        } else {
            showStatus(data.message, 'error');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plus-circle"></i> Create Monthly Backup';
        showStatus('Error: ' + err.message, 'error');
    });
}

function searchIndex() {
    const year = document.getElementById('filterYear').value;
    const month = document.getElementById('filterMonth').value;
    const keyword = document.getElementById('filterKeyword').value;

    fetch('data_backup.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=search_index&year=${encodeURIComponent(year)}&month=${encodeURIComponent(month)}&keyword=${encodeURIComponent(keyword)}`
    })
    .then(r => r.json())
    .then(data => {
        const card = document.getElementById('resultsCard');
        const body = document.getElementById('resultsBody');
        const title = document.getElementById('resultsTitle');
        const count = document.getElementById('resultsCount');

        card.style.display = 'block';
        title.innerHTML = '<i class="fas fa-list"></i> Index Search Results';
        count.textContent = data.count + ' backup(s) found';

        if (!data.results || data.results.length === 0) {
            body.innerHTML = '<p style="color:var(--text-muted); text-align:center; padding:1rem;">No backups match your criteria.</p>';
            return;
        }

        let html = '<table style="width:100%; font-size:0.85rem; border-collapse:collapse;">';
        html += '<tr style="background:#f8fafc; font-weight:600;"><td style="padding:8px;">File</td><td>Date</td><td>Tables</td><td>Rows</td><td>Size</td><td>Actions</td></tr>';
        data.results.forEach(b => {
            html += `<tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:8px;"><i class="fas fa-file-code"></i> ${b.file}</td>
                <td>${b.created_at}</td>
                <td>${(b.tables||[]).length}</td>
                <td>${b.total_rows || 0}</td>
                <td>${b.size_human || ''}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="downloadBackup('${b.file}')"><i class="fas fa-download"></i></button>
                    <button class="btn btn-sm btn-danger" onclick="deleteBackup('${b.file}')"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`;
        });
        html += '</table>';
        body.innerHTML = html;
    });
}

function deepSearch() {
    const term = document.getElementById('deepSearchTerm').value.trim();
    if (term.length < 2) { showStatus('Enter at least 2 characters', 'error'); return; }

    const year = document.getElementById('filterYear').value;
    const month = document.getElementById('filterMonth').value;

    showStatus('Searching SQL files...', 'info');

    fetch('data_backup.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=deep_search&search_term=${encodeURIComponent(term)}&year=${encodeURIComponent(year)}&month=${encodeURIComponent(month)}`
    })
    .then(r => r.json())
    .then(data => {
        const card = document.getElementById('resultsCard');
        const body = document.getElementById('resultsBody');
        const title = document.getElementById('resultsTitle');
        const countEl = document.getElementById('resultsCount');

        card.style.display = 'block';
        title.innerHTML = '<i class="fas fa-file-code"></i> Deep Search Results';

        if (!data.success) {
            body.innerHTML = `<p style="color:var(--danger); padding:1rem;">${data.error}</p>`;
            return;
        }

        const d = data.data;
        countEl.textContent = `${d.total_matches} match(es) in ${d.files_searched} file(s)`;

        if (d.results.length === 0) {
            body.innerHTML = '<p style="color:var(--text-muted); text-align:center; padding:1rem;">No matches found in SQL files.</p>';
            showStatus('No matches found', 'info');
            return;
        }

        let html = '';
        d.results.forEach((m, i) => {
            const escapedLine = m.line.replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const highlighted = escapedLine.replace(new RegExp(term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'), '<mark>$&</mark>');
            html += `<div style="margin-bottom:10px; padding:10px; background:#f8fafc; border-radius:6px; border-left:3px solid #3b82f6; font-family:monospace; font-size:0.8rem;">
                <div style="color:var(--text-muted); margin-bottom:4px; font-family:sans-serif;">
                    <i class="fas fa-file"></i> ${m.backup_file} &bull; Line ${m.line_number} &bull; ${m.backup_date}
                </div>
                <div style="white-space:pre-wrap; word-break:break-all; max-height:120px; overflow-y:auto;">${highlighted}</div>
            </div>`;
        });
        body.innerHTML = html;
        showStatus(`Found ${d.total_matches} matches`, 'success');
    })
    .catch(err => showStatus('Search error: ' + err.message, 'error'));
}

function downloadBackup(file) {
    // Direct download via link
    const a = document.createElement('a');
    a.href = '../data_backup/' + file;
    a.download = file.split('/').pop();
    document.body.appendChild(a);
    a.click();
    a.remove();
}

function deleteBackup(file) {
    if (!confirm('Delete backup: ' + file + '?\nThis cannot be undone.')) return;

    fetch('data_backup.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete_backup&file=' + encodeURIComponent(file)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showStatus('Backup deleted');
            setTimeout(() => location.reload(), 1000);
        } else {
            showStatus(data.message || 'Delete failed', 'error');
        }
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
