<?php
$required_role = ['accountant', 'manager', 'superadmin', 'platform_admin', 'developer'];
require_once '../includes/auth_check.php';
require_once '../includes/functions.php';

$request_path = trim((string)($_GET['file'] ?? ''));
if ($request_path === '') {
    die('Error: No file specified.');
}

$base_dir = realpath(__DIR__ . '/../');
$file_path = null;

if (function_exists('data_storage_resolve_primary_or_mirror')) {
    $resolved = data_storage_resolve_primary_or_mirror($request_path);
    if (is_string($resolved) && $resolved !== '') {
        $file_path = $resolved;
    }
}

if ($file_path === null) {
    $fallback_relative = ltrim(str_replace('\\', '/', $request_path), '/');
    $fallback_path = realpath('../' . $fallback_relative);
    if ($fallback_path !== false) {
        $file_path = $fallback_path;
    }
}

$file_real = $file_path ? realpath($file_path) : false;
$base_real = $base_dir ? str_replace('\\', '/', $base_dir) : '';
$file_real_norm = $file_real ? str_replace('\\', '/', $file_real) : '';

// Security check: ensure resolved path is inside project root.
if (!$base_real || !$file_real || strpos($file_real_norm, $base_real . '/') !== 0 || !is_file($file_real)) {
    die('Error: File not found or access denied.');
}

$original_filename = basename($file_real);
$zip_filename = pathinfo($original_filename, PATHINFO_FILENAME) . ".zip";
$zip_filepath = sys_get_temp_dir() . '/' . $zip_filename;

$zip = new ZipArchive();
if ($zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    $zip->addFile($file_real, $original_filename);
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
    header('Content-Length: ' . filesize($zip_filepath));
    
    readfile($zip_filepath);

    unlink($zip_filepath);
    exit();
} else {
    die('Failed to create the zip file.');
}
?>