<?php
$required_role = "writer";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

header('Content-Type: application/json');

function respond_json($success, $message, $status = 200, array $extra = []) {
    if ($status !== 200) {
        http_response_code($status);
    }
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$input = [];

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $input = $decoded;
            if (!$action && isset($decoded['action'])) {
                $action = $decoded['action'];
            }
        }
    }
}

if (!$action) {
    respond_json(false, 'Missing action.', 400);
}

function list_main_tests(mysqli $conn) {
    $tests = [];
    $sql = "SELECT DISTINCT main_test_name FROM tests WHERE main_test_name IS NOT NULL AND main_test_name != '' ORDER BY main_test_name ASC";
    $result = $conn->query($sql);
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $tests[] = $row['main_test_name'];
        }
        $result->free();
    }
    respond_json(true, 'Loaded main tests.', 200, ['tests' => $tests]);
}

function get_project_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $scriptDir = rtrim(dirname($scriptName), '/');
    $projectRoot = $scriptDir !== '' && $scriptDir !== '.' ? rtrim(dirname($scriptDir), '/') : '';
    if ($projectRoot === '.' || $projectRoot === '/') {
        $projectRoot = '';
    }
    $base = $scheme . '://' . $host;
    if ($projectRoot !== '') {
        $base .= $projectRoot;
    }
    return rtrim($base, '/');
}

function build_template_urls($documentPath) {
    if (!$documentPath) {
        return [null, null, null];
    }

    $absolute = writer_locate_template_file($documentPath);
    if (!$absolute || !is_file($absolute)) {
        return [null, null, null];
    }

    $projectRoot = dirname(__DIR__);
    $absoluteNormalized = str_replace('\\', '/', $absolute);
    $rootNormalized = str_replace('\\', '/', rtrim($projectRoot, '\\/'));

    if (strpos($absoluteNormalized, $rootNormalized . '/') === 0) {
        $normalized = substr($absoluteNormalized, strlen($rootNormalized) + 1);
    } else {
        $normalized = trim(str_replace(['../', '..\\'], '', str_replace('\\', '/', (string)$documentPath)), '/');
    }

    if ($normalized === '') {
        return [null, null, null];
    }

    $publicUrl = '../' . $normalized;
    $base = get_project_base_url();
    $absoluteUrl = $base . '/' . $normalized;
    $viewerUrl = 'https://view.officeapps.live.com/op/embed.aspx?src=' . rawurlencode($absoluteUrl);
    return [$publicUrl, $absolute, $viewerUrl];
}

if (!function_exists('writer_sanitize_template_segment')) {
    function writer_sanitize_template_segment($value) {
        $value = trim((string)$value);
        $value = preg_replace('/[^A-Za-z0-9]+/', '_', $value);
        $value = preg_replace('/_+/', '_', $value);
        $value = trim($value, '_');
        return $value === '' ? 'template' : $value;
    }
}

if (!function_exists('writer_build_template_storage_info')) {
    function writer_build_template_storage_info($mainTestName, $subTestName) {
        $baseSlug = writer_sanitize_template_segment($mainTestName);
        $subSlug = writer_sanitize_template_segment($subTestName);
        $projectRoot = dirname(__DIR__);
        $relativeDir = 'templates/report_templates/' . $baseSlug;
        $absoluteDir = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        $fileName = $subSlug . '.docx';
        return [
            'dir' => $absoluteDir,
            'relative' => $relativeDir . '/' . $fileName,
            'absolute' => $absoluteDir . DIRECTORY_SEPARATOR . $fileName,
        ];
    }
}

if (!function_exists('writer_locate_template_file')) {
    function writer_locate_template_file($documentPath) {
        $documentPath = (string)$documentPath;
        $normalized = trim(str_replace(['../', '..\\'], '', str_replace('\\', '/', $documentPath)), '/');
        if ($normalized === '') {
            return null;
        }

        $projectRoot = dirname(__DIR__);

        $directAbsolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        if (file_exists($directAbsolute)) {
            return $directAbsolute;
        }

        $relativeCandidates = [
            'templates/report_templates/' . $normalized,
            'uploads/report_templates/' . $normalized,
            'uploads/test_documents/' . basename($normalized),
        ];

        foreach ($relativeCandidates as $candidate) {
            $absolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $candidate);
            if (file_exists($absolute)) {
                return $absolute;
            }
        }

        $baseName = basename($normalized);
        if ($baseName !== '') {
            $globPatterns = [
                $projectRoot . '/templates/report_templates/*/' . $baseName,
                $projectRoot . '/uploads/report_templates/*/' . $baseName,
            ];

            foreach ($globPatterns as $pattern) {
                $matches = glob($pattern);
                if (!empty($matches)) {
                    return $matches[0];
                }
            }
        }

        return null;
    }
}

if (!function_exists('writer_delete_template_file')) {
    function writer_delete_template_file($documentPath) {
        $absolute = writer_locate_template_file($documentPath);
        if (!$absolute) {
            return;
        }

        $normalized = str_replace('\\', '/', $absolute);
        $isNewStructure = strpos($normalized, '/templates/report_templates/') !== false
            || strpos($normalized, '/uploads/report_templates/') !== false;
        if ($isNewStructure) {
            @unlink($absolute);
        }
    }
}

function list_subtests(mysqli $conn, $mainTest) {
    if (!$mainTest) {
        respond_json(false, 'Main test is required.', 422);
    }
    $stmt = $conn->prepare("SELECT id, sub_test_name, document FROM tests WHERE main_test_name = ? ORDER BY sub_test_name ASC");
    if (!$stmt) {
        respond_json(false, 'Unable to prepare query.', 500);
    }
    $stmt->bind_param('s', $mainTest);
    $stmt->execute();
    $result = $stmt->get_result();
    $subtests = [];
    while ($row = $result->fetch_assoc()) {
        [$publicUrl, $absolute, $viewerUrl] = build_template_urls($row['document']);
        $subtests[] = [
            'sub_test_id' => (int)$row['id'],
            'sub_test_name' => $row['sub_test_name'],
            'template_exists' => $publicUrl !== null,
            'template_label' => $publicUrl ? basename($row['document']) : 'No template uploaded',
            'preview_url' => $publicUrl,
            'viewer_url' => $viewerUrl,
            'download_url' => $publicUrl,
        ];
    }
    $stmt->close();
    respond_json(true, 'Loaded sub-tests.', 200, ['subtests' => $subtests]);
}

function upload_template(mysqli $conn) {
    $subTestId = isset($_POST['sub_test_id']) ? (int)$_POST['sub_test_id'] : 0;
    if ($subTestId <= 0) {
        respond_json(false, 'Invalid sub-test reference.', 422);
    }
    if (!isset($_FILES['template_file'])) {
        respond_json(false, 'Select a DOCX template to upload.', 422);
    }
    $file = $_FILES['template_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        respond_json(false, 'Upload failed. Please try again.', 422);
    }
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'docx') {
        respond_json(false, 'Only DOCX templates are supported.', 422);
    }
    $stmt = $conn->prepare("SELECT main_test_name, sub_test_name, document FROM tests WHERE id = ? LIMIT 1");
    if (!$stmt) {
        respond_json(false, 'Unable to load sub-test info.', 500);
    }
    $stmt->bind_param('i', $subTestId);
    $stmt->execute();
    $meta = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$meta) {
        respond_json(false, 'Sub-test not found.', 404);
    }
    $storageInfo = writer_build_template_storage_info($meta['main_test_name'], $meta['sub_test_name']);
    if (!is_dir($storageInfo['dir']) && !mkdir($storageInfo['dir'], 0775, true)) {
        respond_json(false, 'Unable to prepare template directory.', 500);
    }
    if (file_exists($storageInfo['absolute']) && !@unlink($storageInfo['absolute'])) {
        respond_json(false, 'Unable to replace the previous template.', 500);
    }
    if (!move_uploaded_file($file['tmp_name'], $storageInfo['absolute'])) {
        respond_json(false, 'Unable to store uploaded template.', 500);
    }
    $relativePath = $storageInfo['relative'];
    $previousDocument = $meta['document'] ?? null;
    $update = $conn->prepare("UPDATE tests SET document = ? WHERE id = ?");
    if (!$update) {
        @unlink($storageInfo['absolute']);
        respond_json(false, 'Unable to update template path.', 500);
    }
    $update->bind_param('si', $relativePath, $subTestId);
    if (!$update->execute()) {
        @unlink($storageInfo['absolute']);
        respond_json(false, 'Failed to link template to test.', 500);
    }
    if ($previousDocument && $previousDocument !== $relativePath) {
        writer_delete_template_file($previousDocument);
    }
    respond_json(true, 'Template uploaded successfully.', 200, [
        'file_path' => $relativePath,
    ]);
}

switch ($action) {
    case 'list_main_tests':
        list_main_tests($conn);
        break;
    case 'list_subtests':
        $mainTest = $input['main_test'] ?? ($_POST['main_test'] ?? null);
        list_subtests($conn, $mainTest);
        break;
    case 'upload_template':
        if ($method !== 'POST') {
            respond_json(false, 'POST required for template upload.', 405);
        }
        upload_template($conn);
        break;
    default:
        respond_json(false, 'Unsupported action.', 400);
    }