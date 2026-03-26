<?php
$required_role = "writer";
require_once '../includes/auth_check.php';

if (!isset($_GET['file']) || empty($_GET['file'])) {
    die('Error: No file specified.');
}

$file_path = realpath('../' . ltrim($_GET['file'], '/'));
$base_dir = realpath(__DIR__ . '/../');

// Security check
if (!$file_path || strpos($file_path, $base_dir) !== 0 || !file_exists($file_path)) {
    die('Error: File not found or access denied.');
}

$original_filename = basename($file_path);

// Check if ZipArchive is available
if (class_exists('ZipArchive')) {
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
    }
}

// Fallback: Download the file directly if ZipArchive is missing or fails
// Determine content type
$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$ctype = 'application/octet-stream';
if ($ext == 'docx') $ctype = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
elseif ($ext == 'doc') $ctype = 'application/msword';
elseif ($ext == 'pdf') $ctype = 'application/pdf';

header('Content-Description: File Transfer');
header('Content-Type: ' . $ctype);
header('Content-Disposition: attachment; filename="' . $original_filename . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($file_path));
readfile($file_path);
exit();

?>