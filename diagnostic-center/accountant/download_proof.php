<?php
$required_role = "accountant"; // Or add more roles if needed
require_once '../includes/auth_check.php';

if (!isset($_GET['file']) || empty($_GET['file'])) {
    die('Error: No file specified.');
}

$file_path = realpath('../' . ltrim($_GET['file'], '/'));
$base_dir = realpath(__DIR__ . '/../');

// Security check: ensure the file is within the project's base directory
if (!$file_path || strpos($file_path, $base_dir) !== 0 || !file_exists($file_path)) {
    die('Error: File not found or access denied.');
}

$original_filename = basename($file_path);
$zip_filename = pathinfo($original_filename, PATHINFO_FILENAME) . ".zip";
$zip_filepath = sys_get_temp_dir() . '/' . $zip_filename;

$zip = new ZipArchive();
if ($zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    $zip->addFile($file_path, $original_filename);
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