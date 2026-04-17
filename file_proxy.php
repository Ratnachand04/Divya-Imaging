<?php
$required_role = ['receptionist', 'writer', 'manager', 'accountant', 'superadmin', 'platform_admin', 'developer'];
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/functions.php';

$request_path = trim((string)($_GET['path'] ?? ''));
if ($request_path === '') {
    http_response_code(400);
    echo 'Missing file path.';
    exit;
}

$resolved = data_storage_resolve_primary_or_mirror($request_path);
if (!is_string($resolved) || $resolved === '' || !is_file($resolved)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$base_dir = realpath(__DIR__);
$file_real = realpath($resolved);
if (!$base_dir || !$file_real) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$base_norm = str_replace('\\', '/', $base_dir);
$file_norm = str_replace('\\', '/', $file_real);
if (strpos($file_norm, $base_norm . '/') !== 0) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$mime_type = 'application/octet-stream';
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo) {
    $detected = finfo_file($finfo, $file_real);
    if (is_string($detected) && $detected !== '') {
        $mime_type = $detected;
    }
    finfo_close($finfo);
}

header('Content-Type: ' . $mime_type);
header('Content-Length: ' . (string)filesize($file_real));
header('Content-Disposition: inline; filename="' . basename($file_real) . '"');
readfile($file_real);
exit;
