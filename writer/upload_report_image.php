<?php
$required_role = ["writer", "superadmin"];
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

function respond_json(bool $success, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(false, 'POST request required.', [], 405);
}

if (!isset($_FILES['report_image']) || !is_array($_FILES['report_image'])) {
    respond_json(false, 'Please choose an image file to upload.', [], 422);
}

$file = $_FILES['report_image'];
$upload_error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($upload_error !== UPLOAD_ERR_OK) {
    $error_message = 'Image upload failed. Please try again.';
    if ($upload_error === UPLOAD_ERR_NO_FILE) {
        $error_message = 'No image file was uploaded.';
    } elseif ($upload_error === UPLOAD_ERR_INI_SIZE || $upload_error === UPLOAD_ERR_FORM_SIZE) {
        $error_message = 'The uploaded image is larger than the server upload limit.';
    }
    respond_json(false, $error_message, [], 422);
}

$file_size = (int)($file['size'] ?? 0);
$max_file_size = 25 * 1024 * 1024; // 25MB
if ($file_size <= 0 || $file_size > $max_file_size) {
    respond_json(false, 'Please upload an image up to 25MB.', [], 422);
}

$tmp_name = (string)($file['tmp_name'] ?? '');
if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
    respond_json(false, 'Uploaded image could not be verified.', [], 422);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = $finfo ? (string)finfo_file($finfo, $tmp_name) : '';
if ($finfo) {
    finfo_close($finfo);
}

$allowed_mime_map = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];

if (!isset($allowed_mime_map[$mime_type])) {
    respond_json(false, 'Unsupported image format. Use JPG, PNG, WEBP, or GIF.', [], 422);
}

$image_size = @getimagesize($tmp_name);
if (!$image_size || empty($image_size[0]) || empty($image_size[1])) {
    respond_json(false, 'Uploaded file is not a valid image.', [], 422);
}

$width = (int)$image_size[0];
$height = (int)$image_size[1];
if ($width < 10 || $height < 10) {
    respond_json(false, 'Image dimensions are too small.', [], 422);
}
if ($width > 12000 || $height > 12000) {
    respond_json(false, 'Image dimensions are too large (max 12000px).', [], 422);
}

$item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
$reporting_doctor = 'unassigned_radiologist';
$main_category = 'uncategorized';

if ($item_id > 0) {
    $meta_stmt = $conn->prepare(
        "SELECT bi.reporting_doctor,
                COALESCE(NULLIF(t.main_test_name, ''), 'uncategorized') AS main_category
         FROM bill_items bi
         LEFT JOIN tests t ON t.id = bi.test_id
         WHERE bi.id = ?
         LIMIT 1"
    );
    if ($meta_stmt) {
        $meta_stmt->bind_param('i', $item_id);
        if ($meta_stmt->execute()) {
            $meta_row = $meta_stmt->get_result()->fetch_assoc();
            if ($meta_row) {
                $reporting_doctor = trim((string)($meta_row['reporting_doctor'] ?? '')) ?: $reporting_doctor;
                $main_category = trim((string)($meta_row['main_category'] ?? '')) ?: $main_category;
            }
        }
        $meta_stmt->close();
    }
}

try {
    if (function_exists('data_storage_reports_directory')) {
        $report_path_meta = data_storage_reports_directory($reporting_doctor, $main_category);
        $relative_dir = $report_path_meta['relative_path'];
        $absolute_dir = $report_path_meta['absolute_path'];
    } else {
        $year = date('Y');
        $month = date('m');
        $day = date('d');
        $relative_dir = 'data/reports/' . $year . '/' . $month . '/uncategorized/' . $day . '/reports';
        $absolute_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_dir);
        if (!is_dir($absolute_dir) && !mkdir($absolute_dir, 0775, true) && !is_dir($absolute_dir)) {
            throw new RuntimeException('Unable to create reports directory.');
        }
    }
} catch (Throwable $e) {
    respond_json(false, 'Unable to prepare image storage directory.', [], 500);
}

$extension = $allowed_mime_map[$mime_type];
try {
    $file_name = 'report_img_' . bin2hex(random_bytes(12)) . '.' . $extension;
} catch (Throwable $e) {
    $file_name = 'report_img_' . uniqid('', true) . '.' . $extension;
}

$absolute_path = $absolute_dir . DIRECTORY_SEPARATOR . $file_name;
if (!move_uploaded_file($tmp_name, $absolute_path)) {
    respond_json(false, 'Unable to save uploaded image.', [], 500);
}

if (function_exists('data_storage_copy_absolute_file_to_mirror')) {
    data_storage_copy_absolute_file_to_mirror($absolute_path);
}

$relative_path = $relative_dir . '/' . $file_name;
$public_url = '/file_proxy.php?path=' . rawurlencode($relative_path);

respond_json(true, 'Image uploaded successfully.', [
    'url' => $public_url,
    'mime' => $mime_type,
    'width' => $width,
    'height' => $height,
    'size' => $file_size,
    'originalName' => basename((string)($file['name'] ?? 'image')),
]);
