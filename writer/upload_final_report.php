<?php
$page_title = "Upload Final Report";
$required_role = "writer";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

header('Content-Type: application/json');

function respond_json($success, $message, $statusCode = 200, array $extra = []) {
    if ($statusCode !== 200) {
        http_response_code($statusCode);
    }
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(false, 'Invalid request method.', 405);
}

$billItemId = isset($_POST['bill_item_id']) ? (int)$_POST['bill_item_id'] : 0;
if ($billItemId <= 0) {
    respond_json(false, 'Invalid report reference provided.', 422);
}

$reporting_doctor = isset($_POST['reporting_doctor']) ? trim($_POST['reporting_doctor']) : '';
$allowed_doctors = [
    'Dr. G. Mamatha MD (RD)',
    'Dr. G. Sri Kanth DMRD',
    'Dr. P. Madhu Babu MD',
    'Dr. Sahithi Chowdary',
    'Dr. SVN. Vamsi Krishna MD(RD)',
    'Dr. T. Koushik MD(RD)',
    'Dr. T. Rajeshwar Rao MD DMRD',
];
if ($reporting_doctor !== '' && !in_array($reporting_doctor, $allowed_doctors, true)) {
    $reporting_doctor = ''; // strip invalid values silently
}

$normalize_doctor = static function (?string $doctor) use ($allowed_doctors): string {
    $doctor = trim((string)$doctor);
    return in_array($doctor, $allowed_doctors, true) ? $doctor : '';
};

if (!isset($_FILES['report_file'])) {
    respond_json(false, 'Please choose a file to upload.', 422);
}

$file = $_FILES['report_file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the allowed size.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the form size limit.',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server error: missing temporary directory.',
        UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by a PHP extension.'
    ];
    $message = $messages[$file['error']] ?? 'Unable to process the uploaded file.';
    respond_json(false, $message, 422);
}

$maxBytes = 15 * 1024 * 1024; // 15 MB limit
if ($file['size'] > $maxBytes) {
    respond_json(false, 'Files up to 15 MB are allowed.', 422);
}

$originalName = $file['name'] ?? 'report';
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowedExtensions = ['pdf', 'doc', 'docx'];
if (!in_array($extension, $allowedExtensions, true)) {
    respond_json(false, 'Only PDF, DOC, or DOCX files are allowed.', 422);
}

$allowedMimeTypes = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/zip',
    'application/octet-stream'
];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detectedMime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
if ($finfo) {
    finfo_close($finfo);
}
if ($detectedMime && !in_array($detectedMime, $allowedMimeTypes, true)) {
    respond_json(false, 'Unsupported file type uploaded.', 422);
}

$detailsStmt = $conn->prepare(
    "SELECT 
        bi.bill_id,
        bi.reporting_doctor AS saved_reporting_doctor,
        p.name AS patient_name,
        t.sub_test_name AS test_name,
        t.main_test_name
     FROM bill_items bi
     JOIN bills b ON bi.bill_id = b.id
     JOIN patients p ON b.patient_id = p.id
     JOIN tests t ON bi.test_id = t.id
     WHERE bi.id = ?"
);
if (!$detailsStmt) {
    respond_json(false, 'Unable to fetch report details.', 500);
}
$detailsStmt->bind_param('i', $billItemId);
$detailsStmt->execute();
$reportMeta = $detailsStmt->get_result()->fetch_assoc();
$detailsStmt->close();

if (!$reportMeta) {
    respond_json(false, 'Report item not found.', 404);
}

$savedReportingDoctor = $normalize_doctor($reportMeta['saved_reporting_doctor'] ?? '');
$postedReportingDoctor = $normalize_doctor($reporting_doctor);
$effectiveReportingDoctor = $savedReportingDoctor !== '' ? $savedReportingDoctor : $postedReportingDoctor;
$doctorLockedForWriter = $savedReportingDoctor !== '';

if ($effectiveReportingDoctor === '') {
    respond_json(false, 'Please select a reporting doctor while writing the report before uploading.', 422);
}

if ($savedReportingDoctor === '' && $effectiveReportingDoctor !== '') {
    $saveDoctorStmt = $conn->prepare('UPDATE bill_items SET reporting_doctor = ? WHERE id = ?');
    if ($saveDoctorStmt) {
        $saveDoctorStmt->bind_param('si', $effectiveReportingDoctor, $billItemId);
        $saveDoctorStmt->execute();
        $saveDoctorStmt->close();
    }
}

$baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'final_reports';
if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true)) {
    respond_json(false, 'Unable to access report storage path.', 500);
}

// ── Build structured path: final_reports/YYYY/MM/doctor/DD/main_test/file ──
function slugify_folder(string $value, string $fallback): string {
    // Remove characters not safe for directory names, collapse spaces/dashes to underscore
    $s = preg_replace('/[\\/:*?"<>|]+/', '', $value);   // strip filesystem-unsafe chars
    $s = preg_replace('/[\s.]+/', '_', trim($s));         // spaces/dots -> underscore
    $s = preg_replace('/_+/', '_', $s);                  // collapse multiple underscores
    $s = trim($s, '_');
    return $s !== '' ? $s : $fallback;
}

$year      = date('Y');
$month     = date('m');
$day       = date('d');

$doctorFolder   = slugify_folder($effectiveReportingDoctor, 'Unassigned');
$mainTestFolder = slugify_folder($reportMeta['main_test_name'] ?? '', 'General');

// Full path: final_reports / YYYY / MM / Doctor_Name / DD / Main_Test /
$targetDir = implode(DIRECTORY_SEPARATOR, [
    $baseDir, $year, $month, $doctorFolder, $day, $mainTestFolder
]);
if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) {
    respond_json(false, 'Unable to prepare dated report folder.', 500);
}

$slugParts = [
    'BI' . $billItemId,
    preg_replace('/[^A-Za-z0-9]+/', '_', $reportMeta['patient_name'] ?? ''),
    preg_replace('/[^A-Za-z0-9]+/', '_', $reportMeta['test_name'] ?? '')
];
$slug = preg_replace('/_+/', '_', trim(implode('_', array_filter($slugParts, 'strlen')), '_'));
if ($slug === '') {
    $slug = 'report_' . $billItemId;
}
$finalFileName = $slug . '.' . $extension;
$counter = 1;
while (file_exists($targetDir . DIRECTORY_SEPARATOR . $finalFileName)) {
    $finalFileName = $slug . '_' . $counter . '.' . $extension;
    $counter++;
}

$absolutePath = $targetDir . DIRECTORY_SEPARATOR . $finalFileName;
if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
    respond_json(false, 'Failed to store the uploaded file.', 500);
}

// Relative path stored in DB uses forward slashes for portability
$relativePath = implode('/', [
    'final_reports', $year, $month, $doctorFolder, $day, $mainTestFolder, $finalFileName
]);

$createSql = "CREATE TABLE IF NOT EXISTS writer_final_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bill_item_id INT UNSIGNED NOT NULL UNIQUE,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    reporting_doctor VARCHAR(150) DEFAULT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
// Ensure reporting_doctor column exists on older tables
$conn->query("ALTER TABLE writer_final_reports ADD COLUMN IF NOT EXISTS reporting_doctor VARCHAR(150) DEFAULT NULL AFTER uploaded_by");
if (!$conn->query($createSql)) {
    @unlink($absolutePath);
    respond_json(false, 'Unable to initialise report log table.', 500);
}

$existingPath = null;
$existingStmt = $conn->prepare('SELECT file_path FROM writer_final_reports WHERE bill_item_id = ?');
if ($existingStmt) {
    $existingStmt->bind_param('i', $billItemId);
    $existingStmt->execute();
    $existingStmt->bind_result($existingPath);
    $existingStmt->fetch();
    $existingStmt->close();
}

$insertSql = "INSERT INTO writer_final_reports (bill_item_id, file_path, original_name, stored_name, uploaded_by, reporting_doctor, uploaded_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        file_path = VALUES(file_path),
        original_name = VALUES(original_name),
        stored_name = VALUES(stored_name),
        uploaded_by = VALUES(uploaded_by),
        reporting_doctor = VALUES(reporting_doctor),
        uploaded_at = NOW()";
$insertStmt = $conn->prepare($insertSql);
if (!$insertStmt) {
    @unlink($absolutePath);
    respond_json(false, 'Unable to save upload details.', 500);
}

$storedName = $finalFileName;
$uploadedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$rdValue = $effectiveReportingDoctor;
$insertStmt->bind_param('isssis', $billItemId, $relativePath, $originalName, $storedName, $uploadedBy, $rdValue);
if (!$insertStmt->execute()) {
    $insertStmt->close();
    @unlink($absolutePath);
    respond_json(false, 'Failed to record the uploaded file.', 500);
}
$insertStmt->close();

if ($existingPath) {
    $previousAbsolute = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $existingPath);
    if (is_file($previousAbsolute)) {
        @unlink($previousAbsolute);
    }
}

$humanTimestamp = date('d M Y, h:i A');
$responseMessage = 'Report uploaded successfully.';
if ($doctorLockedForWriter && $postedReportingDoctor !== '' && $postedReportingDoctor !== $savedReportingDoctor) {
    $responseMessage = 'Report uploaded successfully. Saved reporting doctor was kept unchanged.';
}

respond_json(true, 'Report uploaded successfully.', 200, [
    'file_path' => $relativePath,
    'uploaded_at' => date('c'),
    'statusLabel' => 'Uploaded on ' . $humanTimestamp,
    'message' => $responseMessage,
    'reporting_doctor' => $effectiveReportingDoctor,
    'doctor_locked' => $doctorLockedForWriter,
]);
