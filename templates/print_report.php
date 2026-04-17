<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_package_management_schema($conn);

$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';
$test_packages_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'test_packages', 'tp') : '`test_packages` tp';
$referral_doctors_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd') : '`referral_doctors` rd';

// Basic security check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$viewer_role = $_SESSION['role'] ?? '';
if (!in_array($viewer_role, ['writer', 'manager', 'superadmin'], true)) {
    http_response_code(403);
    die('Forbidden: You do not have permission to view reports.');
}

if (!isset($_GET['item_id']) || !is_numeric($_GET['item_id'])) {
    die("Invalid Report Item ID.");
}

$item_id = (int)$_GET['item_id'];

// Fetch all necessary report details from the database
$stmt = $conn->prepare(
    "SELECT 
                bi.report_content, bi.updated_at as report_date, COALESCE(bi.report_status, 'Pending') as report_status,
                bi.report_docx_path, COALESCE(bi.report_copy_number, 1) AS report_copy_number,
        b.id as bill_id,
        p.name as patient_name, p.age, p.sex,
        t.main_test_name, t.sub_test_name,
          COALESCE(NULLIF(bi.package_name, ''), tp.package_name) AS package_name,
        rd.doctor_name as referral_doctor_name
    FROM {$bill_items_source}
    JOIN {$bills_source} ON bi.bill_id = b.id
    JOIN {$patients_source} ON b.patient_id = p.id
    JOIN {$tests_source} ON bi.test_id = t.id
     LEFT JOIN {$test_packages_source} ON tp.id = bi.package_id
    LEFT JOIN {$referral_doctors_source} ON b.referral_doctor_id = rd.id
     WHERE bi.id = ?"
);
$stmt->bind_param("i", $item_id);
$stmt->execute();
$report_result = $stmt->get_result();

if ($report_result->num_rows === 0) {
    die("Report not found.");
}
$report = $report_result->fetch_assoc();
$stmt->close();

if ($viewer_role === 'manager' && ($report['report_status'] ?? 'Pending') !== 'Completed') {
    http_response_code(403);
    die('Report is not uploaded yet. Manager access is available only after upload.');
}

$download_requested = isset($_GET['download']) && (string)$_GET['download'] === '1';
$can_download_docx = in_array($viewer_role, ['writer', 'manager', 'superadmin'], true);
$auto_print_requested = isset($_GET['print']) && (string)$_GET['print'] === '1';

if ($download_requested && $can_download_docx) {
    $docx_path_raw = trim((string)($report['report_docx_path'] ?? ''));
    if ($docx_path_raw === '') {
        http_response_code(404);
        die('Word report file is not available for this item.');
    }

    $absolute_docx_path = null;
    if (function_exists('data_storage_resolve_primary_or_mirror')) {
        $resolved = data_storage_resolve_primary_or_mirror($docx_path_raw);
        if (is_string($resolved) && $resolved !== '' && is_file($resolved)) {
            $absolute_docx_path = $resolved;
        }
    }

    if ($absolute_docx_path === null) {
        $relative = ltrim(str_replace(['..\\', '../'], '', str_replace('\\', '/', $docx_path_raw)), '/');
        if ($relative !== '') {
            $candidate = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_file($candidate)) {
                $absolute_docx_path = $candidate;
            }
        }
    }

    if ($absolute_docx_path === null || !is_file($absolute_docx_path)) {
        http_response_code(404);
        die('Word report file not found on disk.');
    }

    $conn->query("CREATE TABLE IF NOT EXISTS writer_report_print_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bill_item_id INT UNSIGNED NOT NULL,
        printed_by INT UNSIGNED NOT NULL,
        printed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_bill_item (bill_item_id),
        INDEX idx_printed_at (printed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $print_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($print_user_id > 0) {
        $log_stmt = $conn->prepare("INSERT INTO writer_report_print_logs (bill_item_id, printed_by) VALUES (?, ?)");
        if ($log_stmt) {
            $log_stmt->bind_param('ii', $item_id, $print_user_id);
            $log_stmt->execute();
            $log_stmt->close();
        }
    }

    $download_filename = trim((string)basename(str_replace('\\', '/', $docx_path_raw)));
    if ($download_filename === '' || !preg_match('/\.docx$/i', $download_filename)) {
        $download_filename = 'report_item_' . (int)$item_id . '.docx';
    }
    $download_filename = (string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $download_filename);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $download_filename . '"');
    header('Content-Length: ' . (string)filesize($absolute_docx_path));
    header('Cache-Control: private, must-revalidate');
    header('Pragma: public');
    readfile($absolute_docx_path);
    exit();
}

$back_link = '../writer/dashboard.php';
if ($viewer_role === 'manager') {
    $back_link = '../manager/print_reports.php';
} elseif ($viewer_role === 'superadmin') {
    $back_link = '../superadmin/dashboard.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report for <?php echo htmlspecialchars($report['patient_name']); ?> - <?php echo htmlspecialchars($report['sub_test_name']); ?></title>

    <style>
        body {
            font-family: "Times New Roman", serif;
            background-color: #f7f7f7;
            margin: 0;
            padding: 0;
            color: #222;
        }

        .print-controls {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #ffffff;
            padding: 10px 15px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 100;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .print-controls button,
        .print-controls a {
            background-color: #184a82;
            color: #fff;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .print-controls button:hover,
        .print-controls a:hover {
            background-color: #123861;
        }

        .report-content-wrap {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            background: #fff;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            padding: 1in;
            box-sizing: border-box;
        }

        .report-content {
            font-size: 12pt;
            line-height: 1.6;
            word-break: break-word;
        }

        .report-content p { margin: 0 0 1em 0; }
        .report-content strong { font-weight: 700; }
        .report-content em { font-style: italic; }
        .report-content ul, .report-content ol { padding-left: 20px; }

        @media print {
            body {
                background: none;
            }
            .print-controls {
                display: none;
            }
            .report-content-wrap {
                margin: 0;
                width: 100%;
                min-height: 0;
                box-shadow: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>

    <div class="print-controls">
        <button type="button" onclick="window.print()">Print Report</button>
        <?php if ($can_download_docx): ?>
            <a href="?item_id=<?php echo (int)$item_id; ?>&download=1">Download Word Report</a>
        <?php endif; ?>
        <a href="<?php echo htmlspecialchars($back_link); ?>">Back to Dashboard</a>
    </div>

    <div class="report-content-wrap">
        <div class="report-content">
            <?php echo $report['report_content']; ?>
        </div>
    </div>

</body>
<?php if ($auto_print_requested): ?>
<script>
window.addEventListener('load', function () {
    window.print();
});
</script>
<?php endif; ?>
</html>