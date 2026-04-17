<?php
$page_title = "Word Report Workspace";
$required_role = ["writer", "superadmin"];
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$current_role = $_SESSION['role'] ?? 'writer';
$cancel_link = ($current_role === 'superadmin') ? '../superadmin/detailed_report.php' : 'dashboard.php';

$item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
if (!$item_id) {
    $fallback = ($current_role === 'superadmin') ? '../superadmin/detailed_report.php' : 'dashboard.php';
    header("Location: " . $fallback);
    exit();
}

// ── Ensure reporting_doctor column exists on bill_items and its overflow shards ──
if (function_exists('table_scale_apply_alter_to_all_physical_tables')) {
    table_scale_apply_alter_to_all_physical_tables($conn, 'bill_items', "ADD COLUMN IF NOT EXISTS reporting_doctor VARCHAR(150) DEFAULT NULL");
} else {
    $conn->query("ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS reporting_doctor VARCHAR(150) DEFAULT NULL");
}

// ── Ensure report_docx_path column exists on bill_items and its overflow shards ──
if (function_exists('table_scale_apply_alter_to_all_physical_tables')) {
    table_scale_apply_alter_to_all_physical_tables($conn, 'bill_items', "ADD COLUMN IF NOT EXISTS report_docx_path VARCHAR(600) DEFAULT NULL");
} else {
    $conn->query("ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS report_docx_path VARCHAR(600) DEFAULT NULL");
}

// ── Ensure report_copy_number column exists to support immutable uploaded copies ──
if (function_exists('table_scale_apply_alter_to_all_physical_tables')) {
    table_scale_apply_alter_to_all_physical_tables($conn, 'bill_items', "ADD COLUMN IF NOT EXISTS report_copy_number INT NOT NULL DEFAULT 1");
} else {
    $conn->query("ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS report_copy_number INT NOT NULL DEFAULT 1");
}

try {
    if (function_exists('ensure_writer_saved_bills_stage_table')) {
        ensure_writer_saved_bills_stage_table($conn);
    }
} catch (Throwable $e) {
    // Staging setup is retried during save flow; avoid blocking editor load.
}

function writer_xml_escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function writer_build_docx_body_from_html(string $html): string {
    $normalized = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
    $normalized = preg_replace('/<\/(p|div|h[1-6]|li|tr|table)>/i', "$0\n", (string)$normalized);

    $plain_text = html_entity_decode(strip_tags((string)$normalized), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain_text = str_replace(["\r\n", "\r"], "\n", $plain_text);
    $lines = preg_split('/\n+/', $plain_text) ?: [];

    $paragraphs = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        $paragraphs[] = '<w:p><w:r><w:t xml:space="preserve">' . writer_xml_escape($line) . '</w:t></w:r></w:p>';
    }

    if (empty($paragraphs)) {
        $paragraphs[] = '<w:p><w:r><w:t xml:space="preserve"></w:t></w:r></w:p>';
    }

    return implode('', $paragraphs);
}

function writer_create_report_docx_file(string $absolute_path, string $html_content, array $meta = []): bool {
    if (!class_exists('ZipArchive')) {
        return false;
    }

    $target_dir = dirname($absolute_path);
    if (!is_dir($target_dir) && !mkdir($target_dir, 0775, true) && !is_dir($target_dir)) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($absolute_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    $saved_at = gmdate('c');
    $title = trim((string)($meta['title'] ?? 'Radiology Report'));
    $radiologist = trim((string)($meta['radiologist'] ?? ''));
    $bill_item_id = trim((string)($meta['bill_item_id'] ?? ''));
    $report_main_test = trim((string)($meta['main_test'] ?? ''));

    $document_body = writer_build_docx_body_from_html($html_content);
    $encoded_html = base64_encode($html_content);

    $content_types_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '  <Default Extension="xml" ContentType="application/xml"/>'
        . '  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $relationships_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '  <w:body>'
        . $document_body
        . '    <w:sectPr>'
        . '      <w:pgSz w:w="12240" w:h="15840"/>'
        . '      <w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/>'
        . '    </w:sectPr>'
        . '  </w:body>'
        . '</w:document>';

    $core_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '  <dc:title>' . writer_xml_escape($title) . '</dc:title>'
        . '  <dc:creator>Diagnostic Center</dc:creator>'
        . '  <cp:lastModifiedBy>Diagnostic Center</cp:lastModifiedBy>'
        . '  <dcterms:created xsi:type="dcterms:W3CDTF">' . writer_xml_escape($saved_at) . '</dcterms:created>'
        . '  <dcterms:modified xsi:type="dcterms:W3CDTF">' . writer_xml_escape($saved_at) . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $app_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '  <Application>Diagnostic Center Writer</Application>'
        . '</Properties>';

    $custom_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<writerReport xmlns="https://diagnostic-center.local/schemas/writer-report">'
        . '  <savedAt>' . writer_xml_escape($saved_at) . '</savedAt>'
        . '  <radiologist>' . writer_xml_escape($radiologist) . '</radiologist>'
        . '  <billItemId>' . writer_xml_escape($bill_item_id) . '</billItemId>'
        . '  <mainTest>' . writer_xml_escape($report_main_test) . '</mainTest>'
        . '  <html encoding="base64">' . $encoded_html . '</html>'
        . '</writerReport>';

    $zip->addFromString('[Content_Types].xml', $content_types_xml);
    $zip->addFromString('_rels/.rels', $relationships_xml);
    $zip->addFromString('word/document.xml', $document_xml);
    $zip->addFromString('docProps/core.xml', $core_xml);
    $zip->addFromString('docProps/app.xml', $app_xml);
    $zip->addFromString('customXml/item1.xml', $custom_xml);

    return $zip->close();
}

function writer_resolve_saved_docx_absolute_path(string $relative_path): ?string {
    $normalized_path = null;
    if (function_exists('data_storage_normalize_relative_path')) {
        $normalized_path = data_storage_normalize_relative_path($relative_path);
    } else {
        $candidate = trim(str_replace('\\', '/', $relative_path));
        if ($candidate !== '' && strpos($candidate, '..') === false) {
            $normalized_path = ltrim($candidate, '/');
        }
    }

    if (!is_string($normalized_path) || $normalized_path === '') {
        return null;
    }
    if (!preg_match('/\.docx$/i', $normalized_path)) {
        return null;
    }

    if (function_exists('data_storage_resolve_primary_or_mirror')) {
        $resolved = data_storage_resolve_primary_or_mirror($normalized_path);
        if (is_string($resolved) && $resolved !== '' && is_file($resolved)) {
            return $resolved;
        }
    }

    $project_root = function_exists('data_storage_project_root_path')
        ? data_storage_project_root_path()
        : dirname(__DIR__);

    $absolute_path = $project_root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized_path);
    if (!is_file($absolute_path)) {
        return null;
    }

    return $absolute_path;
}

function writer_extract_html_from_saved_docx(string $absolute_docx_path): ?string {
    if (!class_exists('ZipArchive') || !is_file($absolute_docx_path)) {
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($absolute_docx_path) !== true) {
        return null;
    }

    $custom_xml = $zip->getFromName('customXml/item1.xml');
    if ($custom_xml === false || trim((string)$custom_xml) === '') {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry_name = (string)$zip->getNameIndex($i);
            if (preg_match('#^customXml/item\\d+\\.xml$#', $entry_name)) {
                $candidate = $zip->getFromName($entry_name);
                if ($candidate !== false && trim((string)$candidate) !== '') {
                    $custom_xml = $candidate;
                    break;
                }
            }
        }
    }

    $zip->close();

    if ($custom_xml === false || trim((string)$custom_xml) === '') {
        return null;
    }

    $xml = @simplexml_load_string((string)$custom_xml);
    if (!$xml) {
        return null;
    }

    $html_nodes = $xml->xpath('//*[local-name()="html"]');
    if (!$html_nodes || !isset($html_nodes[0])) {
        return null;
    }

    $html_node = $html_nodes[0];
    $encoding = '';
    $attributes = $html_node->attributes();
    if ($attributes && isset($attributes['encoding'])) {
        $encoding = strtolower(trim((string)$attributes['encoding']));
    }

    $payload = (string)$html_node;
    if ($payload === '') {
        return null;
    }

    if ($encoding === 'base64') {
        $decoded = base64_decode($payload, true);
        return ($decoded === false) ? null : $decoded;
    }

    return $payload;
}

$radiologist_list = get_reporting_radiologist_list();

$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';
$referral_doctors_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd') : '`referral_doctors` rd';
$patient_uid_expression = function_exists('get_patient_identifier_expression') ? get_patient_identifier_expression($conn, 'p') : 'CAST(p.id AS CHAR)';

$fetch_report_details = static function (mysqli $conn, int $item_id, string $bill_items_source, string $bills_source, string $patients_source, string $tests_source, string $referral_doctors_source, string $patient_uid_expression): ?array {
    $stmt_fetch = $conn->prepare(
        "SELECT
            bi.report_content,
            bi.report_docx_path,
            COALESCE(bi.report_status, 'Pending') AS report_status,
            COALESCE(bi.report_copy_number, 1) AS report_copy_number,
            bi.reporting_doctor,
            b.id AS bill_id,
            p.name AS patient_name,
            {$patient_uid_expression} AS patient_uid,
            p.age,
            p.sex,
            b.created_at AS bill_date,
            t.main_test_name,
            t.sub_test_name,
            rd.doctor_name AS referring_doctor_name,
            b.referral_source_other,
            b.referral_type
            FROM {$bill_items_source}
            JOIN {$bills_source} ON bi.bill_id = b.id
            JOIN {$patients_source} ON b.patient_id = p.id
            JOIN {$tests_source} ON bi.test_id = t.id
            LEFT JOIN {$referral_doctors_source} ON b.referral_doctor_id = rd.id
         WHERE bi.id = ?"
    );
    if (!$stmt_fetch) {
        return null;
    }

    $stmt_fetch->bind_param('i', $item_id);
    $stmt_fetch->execute();
    $result = $stmt_fetch->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt_fetch->close();

    return $row ?: null;
};

$report_details = $fetch_report_details($conn, $item_id, $bill_items_source, $bills_source, $patients_source, $tests_source, $referral_doctors_source, $patient_uid_expression);
if (!$report_details) {
    die("Report details not found.");
}

$current_copy_number = max(1, (int)($report_details['report_copy_number'] ?? 1));
$report_status_raw = trim((string)($report_details['report_status'] ?? 'Pending'));
$is_completed_report = ($report_status_raw === 'Completed');
$new_copy_requested = isset($_GET['new_copy']) && (string)$_GET['new_copy'] === '1';
$is_new_copy_workspace = $is_completed_report && $new_copy_requested;
$is_locked_workspace = $is_completed_report && !$is_new_copy_workspace;
$active_copy_number = $is_new_copy_workspace ? ($current_copy_number + 1) : $current_copy_number;

$flash_success = '';
$flash_error = '';
if (!empty($_SESSION['writer_report_success'])) {
    $flash_success = (string)$_SESSION['writer_report_success'];
    unset($_SESSION['writer_report_success']);
}

// Handle form submission to save the report
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $report_content = trim((string)($_POST['report_content'] ?? ''));
    $save_mode = isset($_POST['save_mode']) && $_POST['save_mode'] === 'complete' ? 'complete' : 'draft';
    $target_status = ($save_mode === 'complete') ? 'Completed' : 'Pending';
    $requested_copy_number = isset($_POST['copy_number']) ? max(1, (int)$_POST['copy_number']) : $active_copy_number;
    $target_copy_number = max(1, $requested_copy_number);

    if ($is_completed_report && !$is_new_copy_workspace) {
        $flash_error = 'This uploaded report copy is locked. Create a new copy to submit changes.';
    }

    if ($is_completed_report && $save_mode !== 'complete') {
        $flash_error = 'Draft save is disabled for uploaded reports. Submit a new copy directly.';
    }

    if ($is_completed_report && $requested_copy_number <= $current_copy_number) {
        $flash_error = 'This uploaded report copy is locked. Start a new copy (Copy-' . ($current_copy_number + 1) . ') to continue.';
    }

    if ($is_new_copy_workspace) {
        $target_copy_number = max($current_copy_number + 1, $requested_copy_number);
    } elseif (!$is_completed_report) {
        $target_copy_number = $current_copy_number;
    }

    if ($report_content === '') {
        $flash_error = 'Report content cannot be empty.';
    }

    $reporting_doctor = isset($_POST['reporting_doctor']) ? trim($_POST['reporting_doctor']) : '';
    $valid_reporting_doctor = in_array($reporting_doctor, $radiologist_list, true) ? $reporting_doctor : null;

    // Keep previously selected valid doctor when saving with empty selector.
    if ($valid_reporting_doctor === null && !empty($report_details['reporting_doctor']) && in_array($report_details['reporting_doctor'], $radiologist_list, true)) {
        $valid_reporting_doctor = $report_details['reporting_doctor'];
    }

    if ($valid_reporting_doctor === null) {
        $flash_error = ($save_mode === 'complete')
            ? 'Please choose a reporting radiologist before uploading this report.'
            : 'Please choose a reporting radiologist before saving this report.';
    }

    $report_docx_relative_path = null;
    $report_docx_absolute_path = null;

    if ($flash_error === '') {
        $main_test_name = trim((string)($report_details['main_test_name'] ?? 'uncategorized'));
        $patient_name_raw = trim((string)($report_details['patient_name'] ?? ''));
        $patient_uid_raw = trim((string)($report_details['patient_uid'] ?? ''));
        $test_name_raw = trim((string)($report_details['sub_test_name'] ?? ''));
        if ($test_name_raw === '') {
            $test_name_raw = $main_test_name;
        }

        try {
            if (function_exists('data_storage_reports_directory')) {
                $storage_meta = data_storage_reports_directory($valid_reporting_doctor, $main_test_name);
                $storage_relative_dir = $storage_meta['relative_path'];
                $storage_absolute_dir = $storage_meta['absolute_path'];
            } else {
                $year = date('Y');
                $month = date('m');
                $day = date('d');
                $storage_relative_dir = 'data/reports/' . $year . '/' . $month . '/uncategorized/' . $day . '/reports';
                $storage_absolute_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storage_relative_dir);
                if (!is_dir($storage_absolute_dir) && !mkdir($storage_absolute_dir, 0775, true) && !is_dir($storage_absolute_dir)) {
                    throw new RuntimeException('Unable to create reports directory.');
                }
            }

            if (function_exists('data_storage_safe_segment')) {
                $patient_name_segment = data_storage_safe_segment($patient_name_raw, 'patient');
                $patient_uid_segment = data_storage_safe_segment($patient_uid_raw, 'uid');
                $test_name_segment = data_storage_safe_segment($test_name_raw, 'test');
            } else {
                $patient_name_segment = preg_replace('/[^A-Za-z0-9_-]+/', '_', $patient_name_raw ?: 'patient');
                $patient_uid_segment = preg_replace('/[^A-Za-z0-9_-]+/', '_', $patient_uid_raw ?: 'uid');
                $test_name_segment = preg_replace('/[^A-Za-z0-9_-]+/', '_', $test_name_raw ?: 'test');
            }

            $patient_name_segment = trim((string)$patient_name_segment, '_');
            $patient_uid_segment = trim((string)$patient_uid_segment, '_');
            $test_name_segment = trim((string)$test_name_segment, '_');
            if ($patient_name_segment === '') {
                $patient_name_segment = 'patient';
            }
            if ($patient_uid_segment === '') {
                $patient_uid_segment = 'uid';
            }
            if ($test_name_segment === '') {
                $test_name_segment = 'test';
            }

            $copy_suffix = ($target_copy_number > 1) ? '_copy-' . $target_copy_number : '';
            $report_file_name = $patient_name_segment . '_' . $patient_uid_segment . '_' . $test_name_segment . $copy_suffix . '.docx';
            $report_docx_relative_path = $storage_relative_dir . '/' . $report_file_name;
            $report_docx_absolute_path = $storage_absolute_dir . DIRECTORY_SEPARATOR . $report_file_name;
        } catch (Throwable $e) {
            $flash_error = 'Unable to prepare the radiologist reports folder right now. Please try again.';
        }
    }

    if ($flash_error === '' && is_string($report_docx_absolute_path)) {
        $docx_saved = writer_create_report_docx_file($report_docx_absolute_path, $report_content, [
            'title' => 'Bill #' . (string)($report_details['bill_id'] ?? ''),
            'radiologist' => $valid_reporting_doctor,
            'bill_item_id' => (string)$item_id,
            'main_test' => (string)($report_details['main_test_name'] ?? ''),
        ]);

        if (!$docx_saved) {
            $flash_error = 'Unable to write the Word document in reports storage. Please try again.';
        } elseif (function_exists('data_storage_copy_absolute_file_to_mirror')) {
            data_storage_copy_absolute_file_to_mirror($report_docx_absolute_path);
        }
    }

    if ($flash_error === '' && is_string($report_docx_relative_path)) {
        $bill_items_write_table = 'bill_items';
        if (function_exists('table_scale_find_physical_table_by_id')) {
            $resolved_table = table_scale_find_physical_table_by_id($conn, 'bill_items', $item_id, 'id');
            if (is_string($resolved_table) && preg_match('/^[A-Za-z0-9_]+$/', $resolved_table)) {
                $bill_items_write_table = $resolved_table;
            }
        }

        $stmt_update = $conn->prepare("UPDATE `{$bill_items_write_table}` SET report_content = ?, report_docx_path = ?, report_status = ?, reporting_doctor = ?, report_copy_number = ?, updated_at = NOW() WHERE id = ?");
        if ($stmt_update) {
            $stmt_update->bind_param("ssssii", $report_content, $report_docx_relative_path, $target_status, $valid_reporting_doctor, $target_copy_number, $item_id);
            $saved_to_db = false;
            if ($stmt_update->execute()) {
                $saved_to_db = true;
                $stage_sync_ok = true;
                $_SESSION['writer_report_success'] = $save_mode === 'complete'
                    ? "Copy-{$target_copy_number} for Bill #{$report_details['bill_id']} uploaded successfully. It is now available to manager and superadmin."
                    : "Draft for Copy-{$target_copy_number} of Bill #{$report_details['bill_id']} saved in reports storage.";
                $stmt_update->close();

                if (function_exists('ensure_writer_saved_bills_stage_table')) {
                    try {
                        ensure_writer_saved_bills_stage_table($conn);

                        if ($save_mode === 'draft') {
                            $stage_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
                            $stage_stmt = $conn->prepare("INSERT INTO writer_saved_bills_stage (bill_item_id, saved_by, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE updated_by = VALUES(updated_by), updated_at = NOW()");
                            if (!$stage_stmt) {
                                throw new RuntimeException('Unable to prepare stage upsert.');
                            }
                            $stage_stmt->bind_param('iii', $item_id, $stage_user_id, $stage_user_id);
                            if (!$stage_stmt->execute()) {
                                $stage_stmt->close();
                                throw new RuntimeException('Unable to sync draft in Saved Bills.');
                            }
                            $stage_stmt->close();
                        } else {
                            $stage_delete = $conn->prepare("DELETE FROM writer_saved_bills_stage WHERE bill_item_id = ?");
                            if ($stage_delete) {
                                $stage_delete->bind_param('i', $item_id);
                                $stage_delete->execute();
                                $stage_delete->close();
                            }
                        }
                    } catch (Throwable $e) {
                        $stage_sync_ok = false;
                    }
                }

                if (!$stage_sync_ok) {
                    $flash_error = 'Report document saved, but we could not move it to Saved Bills staging. Please click Save Document once again.';
                    $_SESSION['writer_report_success'] = '';
                    unset($_SESSION['writer_report_success']);
                } else {
                $success_redirect = "fill_report.php?item_id=" . $item_id;
                if ($save_mode === 'draft') {
                    $success_redirect = ($current_role === 'superadmin') ? '../superadmin/detailed_report.php' : 'dashboard.php';
                }
                header("Location: " . $success_redirect);
                exit();
                }
            }
            if (!$saved_to_db) {
                $flash_error = 'Unable to save the report right now. Please try again.';
                $stmt_update->close();
            }
        } else {
            $flash_error = 'Unable to prepare the save operation right now. Please try again.';
        }
    }
}

$existing_report_html_value = '';
$saved_report_docx_path = trim((string)($report_details['report_docx_path'] ?? ''));
if ($saved_report_docx_path !== '' && !$is_new_copy_workspace) {
    $saved_docx_absolute = writer_resolve_saved_docx_absolute_path($saved_report_docx_path);
    if ($saved_docx_absolute !== null) {
        $loaded_html = writer_extract_html_from_saved_docx($saved_docx_absolute);
        if (is_string($loaded_html) && trim($loaded_html) !== '') {
            $existing_report_html_value = $loaded_html;
        }
    }
}

if ($existing_report_html_value === '' && !$is_new_copy_workspace) {
    $existing_report_html_value = (string)($report_details['report_content'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flash_error !== '') {
    $posted_report_html = (string)($_POST['report_content'] ?? '');
    if (trim($posted_report_html) !== '') {
        $existing_report_html_value = $posted_report_html;
    }
}

$existing_doctor = trim((string)($report_details['reporting_doctor'] ?? ''));
$report_status = $report_status_raw;
if ($is_new_copy_workspace) {
    $report_status_label = 'Preparing Copy-' . $active_copy_number;
} elseif ($report_status === 'Completed') {
    $report_status_label = 'Uploaded (Copy-' . $current_copy_number . ')';
} else {
    $report_status_label = 'Draft (Copy-' . max(1, $current_copy_number) . ')';
}

$referring_doctor = trim((string)($report_details['referring_doctor_name'] ?? ''));
if (($report_details['referral_type'] ?? '') === 'Other' && !empty($report_details['referral_source_other'])) {
    $referring_doctor = trim((string)$report_details['referral_source_other']);
}
if ($referring_doctor === '') {
    $referring_doctor = 'Self';
} elseif (stripos($referring_doctor, 'Dr.') !== 0) {
    $referring_doctor = 'Dr. ' . $referring_doctor;
}

$patient_uid = trim((string)($report_details['patient_uid'] ?? ''));
if ($patient_uid === '') {
    $patient_uid = 'N/A';
}

require_once '../includes/header.php';

$tiptap_importmap_json = '{"imports":{}}';
$tiptap_importmap_path = dirname(__DIR__) . '/assets/vendor/tiptap/importmap.json';
if (is_file($tiptap_importmap_path)) {
    $import_map_contents = file_get_contents($tiptap_importmap_path);
    if ($import_map_contents !== false && trim($import_map_contents) !== '') {
        $tiptap_importmap_json = $import_map_contents;
    }
}
?>

<script type="importmap"><?php echo $tiptap_importmap_json; ?></script>

<style>
.tiptap-report-shell {
    border: 1px solid rgba(233, 30, 99, 0.2);
    border-radius: 14px;
    overflow: hidden;
    background: #fff;
}

.tiptap-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    padding: 0.8rem;
    border-bottom: 1px solid rgba(233, 30, 99, 0.16);
    background: linear-gradient(135deg, rgba(255, 244, 249, 0.94), rgba(250, 236, 244, 0.9));
}

.tiptap-toolbar-group {
    display: inline-flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.45rem;
}

.tiptap-divider {
    width: 1px;
    height: 28px;
    background: rgba(198, 84, 135, 0.35);
}

.tiptap-btn {
    border: 1px solid rgba(233, 30, 99, 0.24);
    background: #fff;
    color: #7b1d48;
    border-radius: 999px;
    padding: 0.33rem 0.76rem;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
}

.tiptap-select {
    border: 1px solid rgba(233, 30, 99, 0.24);
    background: #fff;
    color: #7b1d48;
    border-radius: 999px;
    padding: 0.33rem 0.75rem;
    font-size: 0.84rem;
    font-weight: 600;
    min-height: 32px;
}

.tiptap-select:focus {
    outline: 2px solid rgba(233, 30, 99, 0.2);
    outline-offset: 1px;
}

.tiptap-btn:hover {
    border-color: rgba(233, 30, 99, 0.45);
    background: rgba(233, 30, 99, 0.07);
}

.tiptap-btn.is-active {
    color: #fff;
    border-color: rgba(194, 24, 91, 0.95);
    background: linear-gradient(135deg, #f06292, #c2185b);
}

.tiptap-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.tiptap-color {
    width: 36px;
    height: 32px;
    border: 1px solid rgba(233, 30, 99, 0.3);
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    padding: 2px;
}

.tiptap-image-tools {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    border: 1px dashed rgba(194, 24, 91, 0.35);
    border-radius: 10px;
    padding: 0.28rem 0.55rem;
    background: rgba(255, 255, 255, 0.72);
}

.tiptap-image-label,
.tiptap-image-value {
    font-size: 0.8rem;
    font-weight: 700;
    color: #8a2954;
}

#tiptap-image-width-range {
    width: 130px;
}

.tiptap-shortcuts-toggle {
    margin-left: auto;
}

.tiptap-shortcuts-panel {
    margin-top: 0.6rem;
    border: 1px solid rgba(233, 30, 99, 0.2);
    border-radius: 10px;
    background: rgba(255, 246, 250, 0.75);
    padding: 0.65rem 0.8rem;
    font-size: 0.82rem;
    color: #6d2145;
    line-height: 1.45;
}

.tiptap-editor-area {
    min-height: 700px;
    max-height: 76vh;
    overflow: auto;
    background: #fff;
}

.tiptap-editor-area .ProseMirror {
    min-height: 700px;
    padding: 1in;
    margin: 0 auto;
    max-width: 8.5in;
    font-family: "Times New Roman", serif;
    font-size: 12pt;
    line-height: 1.45;
    outline: none;
}

.tiptap-editor-area .ProseMirror p {
    margin: 0 0 0.55rem;
}

.tiptap-editor-area .ProseMirror p.is-editor-empty:first-child::before {
    content: attr(data-placeholder);
    color: #b992a5;
    float: left;
    height: 0;
    pointer-events: none;
}

.tiptap-editor-area .ProseMirror table {
    width: 100%;
    border-collapse: collapse;
}

.tiptap-editor-area .ProseMirror td,
.tiptap-editor-area .ProseMirror th {
    border: 1px solid #d9c9d1;
    padding: 0.45rem;
    vertical-align: top;
}

.tiptap-editor-area .ProseMirror a {
    color: #9c174f;
    text-decoration: underline;
}

.tiptap-editor-area .ProseMirror ul[data-type="taskList"] {
    list-style: none;
    padding-left: 0.25rem;
}

.tiptap-editor-area .ProseMirror ul[data-type="taskList"] li {
    display: flex;
    align-items: flex-start;
    gap: 0.55rem;
    margin: 0.15rem 0;
}

.tiptap-editor-area .ProseMirror ul[data-type="taskList"] li > label {
    margin-top: 0.15rem;
}

.tiptap-editor-area .ProseMirror ul[data-type="taskList"] li > div {
    flex: 1;
}

.tiptap-editor-area .ProseMirror img {
    display: block;
    max-width: 100%;
    height: auto;
    margin: 0.7rem auto;
    border-radius: 8px;
    box-shadow: 0 3px 12px rgba(15, 23, 42, 0.12);
}

.tiptap-editor-area .ProseMirror img.ProseMirror-selectednode {
    outline: 3px solid rgba(233, 30, 99, 0.38);
}
</style>

<div class="form-container">
    <h1>Word Report Workspace: <?php echo htmlspecialchars($report_details['sub_test_name']); ?><?php echo $is_new_copy_workspace ? ' (Copy-' . (int)$active_copy_number . ')' : ''; ?></h1>

    <?php if ($flash_success !== ''): ?>
        <div class="reports-alert" style="margin-bottom:12px;"><?php echo htmlspecialchars($flash_success); ?></div>
    <?php endif; ?>
    <?php if ($flash_error !== ''): ?>
        <div class="reports-alert is-warning" style="margin-bottom:12px;"><?php echo htmlspecialchars($flash_error); ?></div>
    <?php endif; ?>

    <div class="patient-details-header">
        <strong>Patient:</strong> <span id="patient-name"><?php echo htmlspecialchars($report_details['patient_name']); ?></span> | 
        <strong>UID:</strong> <span id="patient-uid"><?php echo htmlspecialchars($patient_uid); ?></span> | 
        <strong>Age/Gender:</strong> <span id="patient-age"><?php echo $report_details['age']; ?></span>/<span id="patient-sex"><?php echo $report_details['sex']; ?></span> | 
        <strong>Referred By:</strong> <span id="patient-ref-doctor"><?php echo htmlspecialchars($referring_doctor); ?></span> |
        <strong>Bill No:</strong> <span id="bill-id"><?php echo $report_details['bill_id']; ?></span> |
        <strong>Status:</strong> <span style="font-weight:700;color:<?php echo $is_locked_workspace ? '#1f6d47' : ($is_new_copy_workspace ? '#9c4221' : ($report_status === 'Completed' ? '#1f6d47' : '#9c4221')); ?>;"><?php echo htmlspecialchars($report_status_label); ?></span>
    </div>
    <p class="description" style="margin-top:8px;">
        <?php if ($is_locked_workspace): ?>
            Uploaded reports are locked and cannot be edited directly. Use "Create Copy-<?php echo (int)($current_copy_number + 1); ?>" to draft a new version and submit again.
        <?php elseif ($is_new_copy_workspace): ?>
            You are preparing Copy-<?php echo (int)$active_copy_number; ?> as a new version. Submit to replace the active report with this uploaded copy.
        <?php else: ?>
            Save writes the report into Word storage. Send to Manager submits it as Copy-<?php echo (int)$active_copy_number; ?>.
        <?php endif; ?>
    </p>

    <?php if ($is_locked_workspace): ?>
        <div class="reports-alert" style="margin-bottom:12px;">
            Copy-<?php echo (int)$current_copy_number; ?> is locked after upload. You can still open a fresh copy workspace and submit Copy-<?php echo (int)($current_copy_number + 1); ?>.
        </div>
    <?php endif; ?>

     <div id="report-data"
                data-upload-image-url="upload_report_image.php"
                data-item-id="<?php echo htmlspecialchars((string)$item_id); ?>"
            data-is-locked="<?php echo $is_locked_workspace ? '1' : '0'; ?>"
            data-is-new-copy-workspace="<?php echo $is_new_copy_workspace ? '1' : '0'; ?>"
            style="display: none;">
    </div>
    <textarea id="existing_report_html" style="display:none;"><?php echo htmlspecialchars($existing_report_html_value); ?></textarea>

    <form action="fill_report.php?item_id=<?php echo $item_id; ?>" method="POST">
        <input type="hidden" name="save_mode" id="saveModeInput" value="<?php echo ($report_status === 'Completed' || $is_new_copy_workspace) ? 'complete' : 'draft'; ?>">
        <input type="hidden" name="copy_number" id="copyNumberInput" value="<?php echo (int)$active_copy_number; ?>">

        <!-- ── Reporting Doctor Selection ───────────────────────────────── -->
        <div class="fill-report-doctor-bar">
            <div class="fill-report-doctor-inner">
                <label for="reporting_doctor_select" class="fill-report-doctor-label">
                    <i class="fas fa-user-md"></i>
                    Reporting Radiologist
                </label>
                <select id="reporting_doctor_select" name="reporting_doctor" class="fill-report-doctor-select">
                    <option value="">-- Select Radiologist --</option>
                    <?php foreach ($radiologist_list as $doc): ?>
                        <option value="<?php echo htmlspecialchars($doc); ?>"
                            <?php echo ($existing_doctor === $doc) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($doc); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($existing_doctor): ?>
                    <span class="fill-report-doctor-saved">
                        <i class="fas fa-check-circle"></i> Previously saved: <?php echo htmlspecialchars($existing_doctor); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <!-- ─────────────────────────────────────────────────────────────── -->

        <input type="hidden" id="report_content" name="report_content" value="">
        <input type="file" id="tiptap-image-input" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;">
        <div class="tiptap-report-shell">
            <div class="tiptap-toolbar" id="tiptap-toolbar">
                <div class="tiptap-toolbar-group">
                    <button type="button" class="tiptap-btn" data-cmd="undo" title="Undo (Ctrl/Cmd+Z)">Undo</button>
                    <button type="button" class="tiptap-btn" data-cmd="redo" title="Redo (Ctrl/Cmd+Y)">Redo</button>
                    <button type="button" class="tiptap-btn" data-cmd="clearFormatting" title="Clear formatting">Clear</button>
                </div>

                <div class="tiptap-divider"></div>

                <div class="tiptap-toolbar-group">
                    <select id="tiptap-font-family" class="tiptap-select" title="Font family">
                        <option value="">Font</option>
                        <option value="Times New Roman">Times New Roman</option>
                        <option value="Calibri">Calibri</option>
                        <option value="Arial">Arial</option>
                        <option value="Georgia">Georgia</option>
                        <option value="Verdana">Verdana</option>
                        <option value="Tahoma">Tahoma</option>
                        <option value="Courier New">Courier New</option>
                    </select>

                    <select id="tiptap-font-size" class="tiptap-select" title="Font size">
                        <option value="">Size</option>
                        <option value="10pt">10</option>
                        <option value="11pt">11</option>
                        <option value="12pt">12</option>
                        <option value="14pt">14</option>
                        <option value="16pt">16</option>
                        <option value="18pt">18</option>
                        <option value="22pt">22</option>
                        <option value="28pt">28</option>
                        <option value="36pt">36</option>
                    </select>

                    <select id="tiptap-line-height" class="tiptap-select" title="Line height">
                        <option value="1.1">Line 1.1</option>
                        <option value="1.2">Line 1.2</option>
                        <option value="1.3">Line 1.3</option>
                        <option value="1.4">Line 1.4</option>
                        <option value="1.45" selected>Line 1.45</option>
                        <option value="1.5">Line 1.5</option>
                        <option value="1.8">Line 1.8</option>
                        <option value="2">Line 2.0</option>
                    </select>

                    <button type="button" class="tiptap-btn" data-cmd="clearTypography" title="Reset font family, size, and line height">Reset Type</button>
                </div>

                <div class="tiptap-divider"></div>

                <div class="tiptap-toolbar-group">
                    <button type="button" class="tiptap-btn" data-cmd="bold" title="Bold (Ctrl/Cmd+B)">Bold</button>
                    <button type="button" class="tiptap-btn" data-cmd="italic" title="Italic (Ctrl/Cmd+I)">Italic</button>
                    <button type="button" class="tiptap-btn" data-cmd="underline" title="Underline (Ctrl/Cmd+U)">Underline</button>
                    <button type="button" class="tiptap-btn" data-cmd="strike" title="Strike (Ctrl/Cmd+Shift+X)">Strike</button>
                    <button type="button" class="tiptap-btn" data-cmd="highlight" title="Highlight">Highlight</button>
                    <button type="button" class="tiptap-btn" data-cmd="subscript" title="Subscript">Sub</button>
                    <button type="button" class="tiptap-btn" data-cmd="superscript" title="Superscript">Sup</button>
                    <input id="tiptap-color-picker" class="tiptap-color" type="color" value="#111111" title="Text color">
                    <button type="button" class="tiptap-btn" data-cmd="setColor" title="Apply selected text color">Color</button>
                    <button type="button" class="tiptap-btn" data-cmd="clearColor" title="Clear text color">No Color</button>
                </div>

                <div class="tiptap-divider"></div>

                <div class="tiptap-toolbar-group">
                    <button type="button" class="tiptap-btn" data-cmd="h1" title="Heading 1 (Ctrl/Cmd+Alt+1)">H1</button>
                    <button type="button" class="tiptap-btn" data-cmd="h2" title="Heading 2 (Ctrl/Cmd+Alt+2)">H2</button>
                    <button type="button" class="tiptap-btn" data-cmd="h3" title="Heading 3 (Ctrl/Cmd+Alt+3)">H3</button>
                    <button type="button" class="tiptap-btn" data-cmd="paragraph" title="Normal text">Text</button>
                    <button type="button" class="tiptap-btn" data-cmd="blockquote" title="Blockquote">Quote</button>
                    <button type="button" class="tiptap-btn" data-cmd="codeBlock" title="Code block">Code</button>
                </div>

                <div class="tiptap-divider"></div>

                <div class="tiptap-toolbar-group">
                    <button type="button" class="tiptap-btn" data-cmd="bulletList" title="Bullet list (Ctrl/Cmd+Shift+8)">Bullets</button>
                    <button type="button" class="tiptap-btn" data-cmd="orderedList" title="Numbered list (Ctrl/Cmd+Shift+7)">Numbers</button>
                    <button type="button" class="tiptap-btn" data-cmd="taskList" title="Checklist">Checklist</button>
                    <button type="button" class="tiptap-btn" data-cmd="alignLeft" title="Align left (Ctrl/Cmd+Shift+L)">Left</button>
                    <button type="button" class="tiptap-btn" data-cmd="alignCenter" title="Align center (Ctrl/Cmd+Shift+E)">Center</button>
                    <button type="button" class="tiptap-btn" data-cmd="alignRight" title="Align right (Ctrl/Cmd+Shift+R)">Right</button>
                    <button type="button" class="tiptap-btn" data-cmd="alignJustify" title="Justify (Ctrl/Cmd+Shift+J)">Justify</button>
                </div>

                <div class="tiptap-divider"></div>

                <div class="tiptap-toolbar-group">
                    <button type="button" class="tiptap-btn" data-cmd="setLink" title="Insert link (Ctrl/Cmd+K)">Link</button>
                    <button type="button" class="tiptap-btn" data-cmd="unsetLink" title="Remove link (Ctrl/Cmd+Shift+K)">Unlink</button>
                    <button type="button" class="tiptap-btn" data-cmd="insertRule" title="Horizontal rule">Rule</button>
                    <button type="button" class="tiptap-btn" data-cmd="insertImage" title="Insert image">Image</button>
                </div>

                <div class="tiptap-image-tools" id="tiptap-image-tools" hidden>
                    <span class="tiptap-image-label">Image Size</span>
                    <button type="button" class="tiptap-btn" data-cmd="imageSmaller" title="Make selected image smaller">-</button>
                    <input id="tiptap-image-width-range" type="range" min="20" max="100" step="5" value="100">
                    <button type="button" class="tiptap-btn" data-cmd="imageLarger" title="Make selected image larger">+</button>
                    <span id="tiptap-image-width-label" class="tiptap-image-value">100%</span>
                    <button type="button" class="tiptap-btn" data-cmd="imageReset" title="Reset selected image size">Reset</button>
                </div>

                <button type="button" class="tiptap-btn tiptap-shortcuts-toggle" id="tiptap-shortcuts-toggle" aria-expanded="false">Shortcuts</button>
            </div>
            <div class="tiptap-editor-area" id="report_content_editor"></div>
        </div>
        <div class="tiptap-shortcuts-panel" id="tiptap-shortcuts-panel" hidden>
            <strong>Keyboard Shortcuts:</strong>
            Ctrl/Cmd+B Bold, Ctrl/Cmd+I Italic, Ctrl/Cmd+U Underline, Ctrl/Cmd+Shift+X Strike,
            Ctrl/Cmd+K Link, Ctrl/Cmd+Shift+K Unlink,
            Ctrl/Cmd+Alt+1/2/3 Headings,
            Ctrl/Cmd+Shift+7 Numbered List, Ctrl/Cmd+Shift+8 Bullet List,
            Ctrl/Cmd+Shift+L/E/R/J Alignment,
            Ctrl/Cmd+Shift+I Insert Image,
            Ctrl/Cmd+Z Undo, Ctrl/Cmd+Y Redo,
            Tab/Shift+Tab increase/decrease list level.
        </div>
        <div style="margin-top:20px; text-align: right;">
            <?php if ($is_locked_workspace): ?>
                <a href="<?php echo htmlspecialchars($cancel_link); ?>" class="btn-cancel">Back</a>
                <a href="fill_report.php?item_id=<?php echo (int)$item_id; ?>&new_copy=1" class="btn-submit">Create Copy-<?php echo (int)($current_copy_number + 1); ?></a>
            <?php elseif ($is_new_copy_workspace): ?>
                <a href="fill_report.php?item_id=<?php echo (int)$item_id; ?>" class="btn-cancel">Back to Locked Copy-<?php echo (int)$current_copy_number; ?></a>
                <button type="submit" class="btn-submit" data-save-mode="complete">Submit Copy-<?php echo (int)$active_copy_number; ?></button>
            <?php else: ?>
                <a href="<?php echo htmlspecialchars($cancel_link); ?>" class="btn-cancel">Cancel</a>
                <button type="submit" class="btn-secondary" data-save-mode="draft">Save Document</button>
                <button type="submit" class="btn-submit" data-save-mode="complete">Send to Manager</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<script type="module">
import { Editor, Extension } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';
import Placeholder from '@tiptap/extension-placeholder';
import Image from '@tiptap/extension-image';
import TaskList from '@tiptap/extension-task-list';
import TaskItem from '@tiptap/extension-task-item';
import Highlight from '@tiptap/extension-highlight';
import TextStyle from '@tiptap/extension-text-style';
import Color from '@tiptap/extension-color';
import Superscript from '@tiptap/extension-superscript';
import Subscript from '@tiptap/extension-subscript';

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

const normalizeFontFamily = (rawValue) => {
    if (rawValue === null || rawValue === undefined) {
        return '';
    }

    const value = String(rawValue).trim().replace(/^['"]+|['"]+$/g, '');
    return value;
};

const normalizeFontSize = (rawValue) => {
    if (rawValue === null || rawValue === undefined) {
        return '';
    }

    const value = String(rawValue).trim().toLowerCase();
    if (value === '') {
        return '';
    }

    if (/^\d+(\.\d+)?pt$/.test(value)) {
        const size = clamp(parseFloat(value), 8, 72);
        return `${Math.round(size)}pt`;
    }

    if (/^\d+(\.\d+)?px$/.test(value) || /^\d+(\.\d+)?$/.test(value)) {
        const size = clamp(parseFloat(value), 8, 72);
        return `${Math.round(size)}pt`;
    }

    return '';
};

const normalizeLineHeight = (rawValue) => {
    if (rawValue === null || rawValue === undefined) {
        return '';
    }

    const value = String(rawValue).trim();
    if (value === '') {
        return '';
    }

    if (/^\d+(\.\d+)?$/.test(value)) {
        const numeric = clamp(parseFloat(value), 1, 3);
        return `${numeric}`;
    }

    return '';
};

const normalizeImageWidth = (rawValue) => {
    if (rawValue === null || rawValue === undefined) {
        return '';
    }

    const value = String(rawValue).trim();
    if (value === '') {
        return '';
    }

    if (/^\d+(\.\d+)?%$/.test(value)) {
        return `${clamp(Math.round(parseFloat(value)), 20, 100)}%`;
    }

    if (/^\d+(\.\d+)?px$/.test(value)) {
        return `${Math.max(80, Math.round(parseFloat(value)))}px`;
    }

    if (/^\d+(\.\d+)?$/.test(value)) {
        return `${Math.max(80, Math.round(parseFloat(value)))}px`;
    }

    return '';
};

const toHexColor = (rawValue) => {
    if (!rawValue) {
        return '#111111';
    }

    const value = String(rawValue).trim().toLowerCase();
    if (/^#[0-9a-f]{6}$/.test(value)) {
        return value;
    }

    if (/^#[0-9a-f]{3}$/.test(value)) {
        return `#${value[1]}${value[1]}${value[2]}${value[2]}${value[3]}${value[3]}`;
    }

    const rgb = value.match(/^rgb\((\d{1,3}),\s*(\d{1,3}),\s*(\d{1,3})\)$/);
    if (rgb) {
        const r = clamp(parseInt(rgb[1], 10), 0, 255).toString(16).padStart(2, '0');
        const g = clamp(parseInt(rgb[2], 10), 0, 255).toString(16).padStart(2, '0');
        const b = clamp(parseInt(rgb[3], 10), 0, 255).toString(16).padStart(2, '0');
        return `#${r}${g}${b}`;
    }

    return '#111111';
};

const FontFamilyStyle = Extension.create({
    name: 'fontFamilyStyle',
    addOptions() {
        return {
            types: ['textStyle'],
        };
    },
    addGlobalAttributes() {
        return [{
            types: this.options.types,
            attributes: {
                fontFamily: {
                    default: null,
                    parseHTML: (element) => normalizeFontFamily(element.style.fontFamily),
                    renderHTML: (attributes) => {
                        const fontFamily = normalizeFontFamily(attributes.fontFamily);
                        if (!fontFamily) {
                            return {};
                        }
                        return {
                            style: `font-family:${fontFamily}`,
                        };
                    },
                },
            },
        }];
    },
    addCommands() {
        return {
            setFontFamily: (fontFamily) => ({ chain }) => {
                const normalized = normalizeFontFamily(fontFamily);
                if (!normalized) {
                    return chain().setMark('textStyle', { fontFamily: null }).removeEmptyTextStyle().run();
                }
                return chain().setMark('textStyle', { fontFamily: normalized }).run();
            },
            unsetFontFamily: () => ({ chain }) => {
                return chain().setMark('textStyle', { fontFamily: null }).removeEmptyTextStyle().run();
            },
        };
    },
});

const FontSizeStyle = Extension.create({
    name: 'fontSizeStyle',
    addOptions() {
        return {
            types: ['textStyle'],
        };
    },
    addGlobalAttributes() {
        return [{
            types: this.options.types,
            attributes: {
                fontSize: {
                    default: null,
                    parseHTML: (element) => normalizeFontSize(element.style.fontSize),
                    renderHTML: (attributes) => {
                        const fontSize = normalizeFontSize(attributes.fontSize);
                        if (!fontSize) {
                            return {};
                        }
                        return {
                            style: `font-size:${fontSize}`,
                        };
                    },
                },
            },
        }];
    },
    addCommands() {
        return {
            setFontSize: (fontSize) => ({ chain }) => {
                const normalized = normalizeFontSize(fontSize);
                if (!normalized) {
                    return chain().setMark('textStyle', { fontSize: null }).removeEmptyTextStyle().run();
                }
                return chain().setMark('textStyle', { fontSize: normalized }).run();
            },
            unsetFontSize: () => ({ chain }) => {
                return chain().setMark('textStyle', { fontSize: null }).removeEmptyTextStyle().run();
            },
        };
    },
});

const LineHeightStyle = Extension.create({
    name: 'lineHeightStyle',
    addOptions() {
        return {
            types: ['paragraph', 'heading'],
        };
    },
    addGlobalAttributes() {
        return [{
            types: this.options.types,
            attributes: {
                lineHeight: {
                    default: '1.45',
                    parseHTML: (element) => normalizeLineHeight(element.style.lineHeight) || '1.45',
                    renderHTML: (attributes) => {
                        const lineHeight = normalizeLineHeight(attributes.lineHeight) || '1.45';
                        return {
                            style: `line-height:${lineHeight}`,
                        };
                    },
                },
            },
        }];
    },
});

const ResizableImage = Image.extend({
    addAttributes() {
        const inherited = this.parent ? this.parent() : {};
        return {
            ...inherited,
            width: {
                default: '100%',
                parseHTML: (element) => {
                    const dataWidth = element.getAttribute('data-width');
                    const styleWidth = element.style && element.style.width ? element.style.width : '';
                    const attrWidth = element.getAttribute('width');
                    return normalizeImageWidth(dataWidth || styleWidth || attrWidth || '100%') || '100%';
                },
                renderHTML: (attributes) => {
                    const width = normalizeImageWidth(attributes.width);
                    const styleParts = ['max-width:100%', 'height:auto'];
                    const htmlAttrs = {};

                    if (width !== '') {
                        styleParts.unshift(`width:${width}`);
                        htmlAttrs['data-width'] = width;
                    }

                    htmlAttrs.style = styleParts.join(';');
                    return htmlAttrs;
                },
            },
        };
    },
});

const WriterShortcuts = Extension.create({
    name: 'writerShortcuts',
    addKeyboardShortcuts() {
        return {
            'Mod-b': () => this.editor.chain().focus().toggleBold().run(),
            'Mod-i': () => this.editor.chain().focus().toggleItalic().run(),
            'Mod-u': () => this.editor.chain().focus().toggleUnderline().run(),
            'Mod-Shift-x': () => this.editor.chain().focus().toggleStrike().run(),
            'Mod-Alt-1': () => this.editor.chain().focus().toggleHeading({ level: 1 }).run(),
            'Mod-Alt-2': () => this.editor.chain().focus().toggleHeading({ level: 2 }).run(),
            'Mod-Alt-3': () => this.editor.chain().focus().toggleHeading({ level: 3 }).run(),
            'Mod-Shift-7': () => this.editor.chain().focus().toggleOrderedList().run(),
            'Mod-Shift-8': () => this.editor.chain().focus().toggleBulletList().run(),
            'Mod-Shift-9': () => this.editor.chain().focus().toggleTaskList().run(),
            'Mod-Shift-l': () => this.editor.chain().focus().setTextAlign('left').run(),
            'Mod-Shift-e': () => this.editor.chain().focus().setTextAlign('center').run(),
            'Mod-Shift-r': () => this.editor.chain().focus().setTextAlign('right').run(),
            'Mod-Shift-j': () => this.editor.chain().focus().setTextAlign('justify').run(),
            'Mod-Shift-h': () => this.editor.chain().focus().toggleHighlight().run(),
            'Shift-Mod-z': () => this.editor.chain().focus().redo().run(),
            'Mod-y': () => this.editor.chain().focus().redo().run(),
            Tab: () => {
                if (this.editor.isActive('taskList')) {
                    return this.editor.chain().focus().sinkListItem('taskItem').run();
                }
                if (this.editor.isActive('bulletList') || this.editor.isActive('orderedList')) {
                    return this.editor.chain().focus().sinkListItem('listItem').run();
                }
                return false;
            },
            'Shift-Tab': () => {
                if (this.editor.isActive('taskList')) {
                    return this.editor.chain().focus().liftListItem('taskItem').run();
                }
                if (this.editor.isActive('bulletList') || this.editor.isActive('orderedList')) {
                    return this.editor.chain().focus().liftListItem('listItem').run();
                }
                return false;
            },
        };
    },
});

document.addEventListener('DOMContentLoaded', function() {
    const reportDataContainer = document.getElementById('report-data');
    if (!reportDataContainer) {
        return;
    }

    const uploadImageUrl = reportDataContainer.dataset.uploadImageUrl || 'upload_report_image.php';
    const itemId = reportDataContainer.dataset.itemId || '';
    const isLockedWorkspace = reportDataContainer.dataset.isLocked === '1';
    const isNewCopyWorkspace = reportDataContainer.dataset.isNewCopyWorkspace === '1';
    const existingHtml = document.getElementById('existing_report_html').value || '';
    const saveModeInput = document.getElementById('saveModeInput');
    const doctorSelect = document.getElementById('reporting_doctor_select');
    const reportContentInput = document.getElementById('report_content');
    const editorMount = document.getElementById('report_content_editor');
    const toolbarButtons = Array.from(document.querySelectorAll('#tiptap-toolbar [data-cmd]'));
    const fontFamilySelect = document.getElementById('tiptap-font-family');
    const fontSizeSelect = document.getElementById('tiptap-font-size');
    const lineHeightSelect = document.getElementById('tiptap-line-height');
    const colorPicker = document.getElementById('tiptap-color-picker');
    const imageInput = document.getElementById('tiptap-image-input');
    const imageTools = document.getElementById('tiptap-image-tools');
    const imageWidthRange = document.getElementById('tiptap-image-width-range');
    const imageWidthLabel = document.getElementById('tiptap-image-width-label');
    const shortcutsToggle = document.getElementById('tiptap-shortcuts-toggle');
    const shortcutsPanel = document.getElementById('tiptap-shortcuts-panel');

    if (!editorMount || !reportContentInput) {
        return;
    }

    let editor = null;

    const disableInteractiveControls = () => {
        toolbarButtons.forEach((button) => {
            button.disabled = true;
        });

        [fontFamilySelect, fontSizeSelect, lineHeightSelect, colorPicker, imageWidthRange, imageInput].forEach((input) => {
            if (input) {
                input.disabled = true;
            }
        });

        if (shortcutsToggle) {
            shortcutsToggle.disabled = true;
        }

        if (doctorSelect) {
            doctorSelect.disabled = true;
        }

        setImageToolVisibility(false);
    };

    const setImageToolVisibility = (isVisible) => {
        if (!imageTools) {
            return;
        }
        imageTools.hidden = !isVisible;
    };

    const getSelectedImageWidthPercent = () => {
        if (!editor || !editor.isActive('image')) {
            return 100;
        }

        const width = normalizeImageWidth(editor.getAttributes('image').width || '100%');
        if (width.endsWith('%')) {
            return clamp(parseInt(width, 10), 20, 100);
        }

        // Fallback when width is in px and we do not have a stable container width.
        return 100;
    };

    const setSelectedImageWidthPercent = (percent) => {
        if (!editor || !editor.isActive('image')) {
            return false;
        }

        const widthPercent = `${clamp(Math.round(percent), 20, 100)}%`;
        return editor.chain().focus().updateAttributes('image', { width: widthPercent }).run();
    };

    const refreshImageTools = () => {
        if (!editor || !imageWidthRange || !imageWidthLabel) {
            return;
        }

        const imageActive = editor.isActive('image');
        setImageToolVisibility(imageActive);
        if (!imageActive) {
            return;
        }

        const widthPercent = getSelectedImageWidthPercent();
        imageWidthRange.value = String(widthPercent);
        imageWidthLabel.textContent = `${widthPercent}%`;
    };

    const syncEditorContent = () => {
        if (!editor) {
            return;
        }
        reportContentInput.value = editor.getHTML();
    };

    const updateToolbarState = () => {
        if (!editor) {
            return;
        }

        if (isLockedWorkspace) {
            disableInteractiveControls();
            return;
        }

        const activeChecks = {
            bold: () => editor.isActive('bold'),
            italic: () => editor.isActive('italic'),
            underline: () => editor.isActive('underline'),
            strike: () => editor.isActive('strike'),
            highlight: () => editor.isActive('highlight'),
            superscript: () => editor.isActive('superscript'),
            subscript: () => editor.isActive('subscript'),
            h1: () => editor.isActive('heading', { level: 1 }),
            h2: () => editor.isActive('heading', { level: 2 }),
            h3: () => editor.isActive('heading', { level: 3 }),
            paragraph: () => editor.isActive('paragraph'),
            bulletList: () => editor.isActive('bulletList'),
            orderedList: () => editor.isActive('orderedList'),
            taskList: () => editor.isActive('taskList'),
            blockquote: () => editor.isActive('blockquote'),
            codeBlock: () => editor.isActive('codeBlock'),
            alignLeft: () => editor.isActive({ textAlign: 'left' }),
            alignCenter: () => editor.isActive({ textAlign: 'center' }),
            alignRight: () => editor.isActive({ textAlign: 'right' }),
            alignJustify: () => editor.isActive({ textAlign: 'justify' }),
            setLink: () => editor.isActive('link'),
        };

        const canChecks = {
            undo: () => editor.can().chain().focus().undo().run(),
            redo: () => editor.can().chain().focus().redo().run(),
            imageSmaller: () => editor.isActive('image'),
            imageLarger: () => editor.isActive('image'),
            imageReset: () => editor.isActive('image'),
        };

        toolbarButtons.forEach((button) => {
            const cmd = button.dataset.cmd || '';
            const isActive = activeChecks[cmd] ? activeChecks[cmd]() : false;
            button.classList.toggle('is-active', Boolean(isActive));

            if (canChecks[cmd]) {
                button.disabled = !canChecks[cmd]();
            } else {
                button.disabled = false;
            }
        });

        if (colorPicker) {
            const activeColor = editor.getAttributes('textStyle').color || '';
            colorPicker.value = toHexColor(activeColor);
        }

        if (fontFamilySelect) {
            const fontFamily = normalizeFontFamily(editor.getAttributes('textStyle').fontFamily || '');
            fontFamilySelect.value = fontFamily;
        }

        if (fontSizeSelect) {
            const fontSize = normalizeFontSize(editor.getAttributes('textStyle').fontSize || '');
            fontSizeSelect.value = fontSize;
        }

        if (lineHeightSelect) {
            const paragraphLineHeight = normalizeLineHeight(editor.getAttributes('paragraph').lineHeight || '');
            const headingLineHeight = normalizeLineHeight(editor.getAttributes('heading').lineHeight || '');
            lineHeightSelect.value = paragraphLineHeight || headingLineHeight || '1.45';
        }

        refreshImageTools();
    };

    const setEditorHtml = (htmlContent) => {
        if (!editor) {
            return;
        }
        editor.commands.setContent(htmlContent || '<p></p>', false);
        syncEditorContent();
        updateToolbarState();
    };

    const uploadImage = async (file) => {
        const formData = new FormData();
        formData.append('report_image', file);
        if (itemId !== '') {
            formData.append('item_id', itemId);
        }

        const response = await fetch(uploadImageUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        });

        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (!response.ok || !payload || !payload.success) {
            throw new Error((payload && payload.message) ? payload.message : 'Image upload failed.');
        }

        return payload.data || {};
    };

    const actionHandlers = {
        undo: () => editor.chain().focus().undo().run(),
        redo: () => editor.chain().focus().redo().run(),
        clearFormatting: () => editor.chain().focus().clearNodes().unsetAllMarks().run(),
        bold: () => editor.chain().focus().toggleBold().run(),
        italic: () => editor.chain().focus().toggleItalic().run(),
        underline: () => editor.chain().focus().toggleUnderline().run(),
        strike: () => editor.chain().focus().toggleStrike().run(),
        highlight: () => editor.chain().focus().toggleHighlight().run(),
        subscript: () => editor.chain().focus().toggleSubscript().run(),
        superscript: () => editor.chain().focus().toggleSuperscript().run(),
        h1: () => editor.chain().focus().toggleHeading({ level: 1 }).run(),
        h2: () => editor.chain().focus().toggleHeading({ level: 2 }).run(),
        h3: () => editor.chain().focus().toggleHeading({ level: 3 }).run(),
        paragraph: () => editor.chain().focus().setParagraph().run(),
        blockquote: () => editor.chain().focus().toggleBlockquote().run(),
        codeBlock: () => editor.chain().focus().toggleCodeBlock().run(),
        bulletList: () => editor.chain().focus().toggleBulletList().run(),
        orderedList: () => editor.chain().focus().toggleOrderedList().run(),
        taskList: () => editor.chain().focus().toggleTaskList().run(),
        alignLeft: () => editor.chain().focus().setTextAlign('left').run(),
        alignCenter: () => editor.chain().focus().setTextAlign('center').run(),
        alignRight: () => editor.chain().focus().setTextAlign('right').run(),
        alignJustify: () => editor.chain().focus().setTextAlign('justify').run(),
        setColor: () => {
            if (!colorPicker) {
                return false;
            }
            return editor.chain().focus().setColor(colorPicker.value).run();
        },
        clearColor: () => editor.chain().focus().unsetColor().run(),
        setFontFamily: () => {
            if (!fontFamilySelect) {
                return false;
            }
            return editor.chain().focus().setFontFamily(fontFamilySelect.value || '').run();
        },
        setFontSize: () => {
            if (!fontSizeSelect) {
                return false;
            }
            return editor.chain().focus().setFontSize(fontSizeSelect.value || '').run();
        },
        setLineHeight: () => {
            if (!lineHeightSelect) {
                return false;
            }

            const normalized = normalizeLineHeight(lineHeightSelect.value) || '1.45';
            return editor
                .chain()
                .focus()
                .updateAttributes('paragraph', { lineHeight: normalized })
                .updateAttributes('heading', { lineHeight: normalized })
                .run();
        },
        clearTypography: () => {
            const resetLineHeight = '1.45';
            return editor
                .chain()
                .focus()
                .unsetFontFamily()
                .unsetFontSize()
                .updateAttributes('paragraph', { lineHeight: resetLineHeight })
                .updateAttributes('heading', { lineHeight: resetLineHeight })
                .run();
        },
        setLink: () => {
            const currentHref = editor.getAttributes('link').href || '';
            const rawUrl = window.prompt('Enter URL (https://...)', currentHref);
            if (rawUrl === null) {
                return;
            }

            const trimmedUrl = rawUrl.trim();
            if (trimmedUrl === '') {
                editor.chain().focus().unsetLink().run();
                return;
            }

            const normalizedUrl = /^(https?:|mailto:)/i.test(trimmedUrl)
                ? trimmedUrl
                : `https://${trimmedUrl}`;

            editor.chain().focus().extendMarkRange('link').setLink({ href: normalizedUrl }).run();
        },
        unsetLink: () => editor.chain().focus().unsetLink().run(),
        insertRule: () => editor.chain().focus().setHorizontalRule().run(),
        insertImage: () => {
            if (imageInput) {
                imageInput.click();
            }
        },
        imageSmaller: () => setSelectedImageWidthPercent(getSelectedImageWidthPercent() - 5),
        imageLarger: () => setSelectedImageWidthPercent(getSelectedImageWidthPercent() + 5),
        imageReset: () => editor.chain().focus().updateAttributes('image', { width: '100%' }).run(),
    };

    toolbarButtons.forEach((button) => {
        button.addEventListener('click', function() {
            if (isLockedWorkspace) {
                return;
            }
            const cmd = this.dataset.cmd || '';
            if (!actionHandlers[cmd] || !editor) {
                return;
            }
            actionHandlers[cmd]();
            syncEditorContent();
            updateToolbarState();
        });
    });

    if (fontFamilySelect) {
        fontFamilySelect.addEventListener('change', function() {
            if (!editor || isLockedWorkspace) {
                return;
            }
            actionHandlers.setFontFamily();
            syncEditorContent();
            updateToolbarState();
        });
    }

    if (fontSizeSelect) {
        fontSizeSelect.addEventListener('change', function() {
            if (!editor || isLockedWorkspace) {
                return;
            }
            actionHandlers.setFontSize();
            syncEditorContent();
            updateToolbarState();
        });
    }

    if (lineHeightSelect) {
        lineHeightSelect.addEventListener('change', function() {
            if (!editor || isLockedWorkspace) {
                return;
            }
            actionHandlers.setLineHeight();
            syncEditorContent();
            updateToolbarState();
        });
    }

    if (imageWidthRange) {
        imageWidthRange.addEventListener('input', function() {
            if (!editor || !editor.isActive('image') || isLockedWorkspace) {
                return;
            }

            const nextValue = clamp(parseInt(this.value, 10) || 100, 20, 100);
            setSelectedImageWidthPercent(nextValue);
            imageWidthLabel.textContent = `${nextValue}%`;
            syncEditorContent();
            updateToolbarState();
        });
    }

    if (imageInput) {
        imageInput.addEventListener('change', async function() {
            if (!editor || !this.files || !this.files.length || isLockedWorkspace) {
                return;
            }

            const file = this.files[0];
            const insertImageButton = toolbarButtons.find((button) => button.dataset.cmd === 'insertImage');

            try {
                if (insertImageButton) {
                    insertImageButton.disabled = true;
                    insertImageButton.textContent = 'Uploading...';
                }

                const uploaded = await uploadImage(file);
                const naturalWidth = parseInt(uploaded.width || 0, 10);
                let initialWidth = '100%';
                if (naturalWidth > 1900) {
                    initialWidth = '65%';
                } else if (naturalWidth > 1500) {
                    initialWidth = '75%';
                } else if (naturalWidth > 1150) {
                    initialWidth = '85%';
                }

                editor.chain().focus().setImage({
                    src: uploaded.url,
                    alt: uploaded.originalName || file.name || 'Report Image',
                    title: uploaded.originalName || file.name || 'Report Image',
                    width: initialWidth,
                }).run();

                syncEditorContent();
                updateToolbarState();
            } catch (error) {
                console.error('Image upload failed:', error);
                alert(error.message || 'Unable to upload image right now.');
            } finally {
                if (insertImageButton) {
                    insertImageButton.disabled = false;
                    insertImageButton.textContent = 'Image';
                }
                this.value = '';
            }
        });
    }

    if (shortcutsToggle && shortcutsPanel) {
        shortcutsToggle.addEventListener('click', function() {
            if (isLockedWorkspace) {
                return;
            }
            const expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            shortcutsPanel.hidden = expanded;
        });
    }

    if (editorMount) {
        editorMount.addEventListener('keydown', function(event) {
            if (isLockedWorkspace) {
                return;
            }
            const isMod = event.ctrlKey || event.metaKey;
            if (!isMod || !editor) {
                return;
            }

            const key = String(event.key || '').toLowerCase();
            if (key === 'k') {
                event.preventDefault();
                if (event.shiftKey) {
                    actionHandlers.unsetLink();
                } else {
                    actionHandlers.setLink();
                }
                syncEditorContent();
                updateToolbarState();
                return;
            }

            if (key === 'i' && event.shiftKey) {
                event.preventDefault();
                actionHandlers.insertImage();
            }
        });
    }

    editor = new Editor({
        element: editorMount,
        extensions: [
            StarterKit.configure({
                heading: {
                    levels: [1, 2, 3],
                },
            }),
            Link.configure({
                openOnClick: false,
                autolink: true,
                linkOnPaste: true,
            }),
            Underline,
            TextAlign.configure({
                types: ['heading', 'paragraph'],
            }),
            Placeholder.configure({
                placeholder: 'Start typing the report here...',
                emptyEditorClass: 'is-editor-empty',
            }),
            TextStyle,
            FontFamilyStyle,
            FontSizeStyle,
            LineHeightStyle,
            Color,
            Highlight.configure({
                multicolor: true,
            }),
            Subscript,
            Superscript,
            TaskList,
            TaskItem.configure({
                nested: true,
            }),
            ResizableImage.configure({
                inline: false,
                allowBase64: false,
                HTMLAttributes: {
                    loading: 'lazy',
                    decoding: 'async',
                },
            }),
            WriterShortcuts,
        ],
        editable: !isLockedWorkspace,
        content: '<p></p>',
        onCreate: () => {
            syncEditorContent();
            updateToolbarState();
        },
        onUpdate: () => {
            syncEditorContent();
            updateToolbarState();
        },
        onSelectionUpdate: () => {
            updateToolbarState();
        },
    });

    const hydrateEditorContent = () => {
        if (existingHtml.trim() !== '') {
            setEditorHtml(existingHtml);
            return;
        }

        setEditorHtml('<p></p>');
    };

    hydrateEditorContent();

    if (isLockedWorkspace) {
        disableInteractiveControls();
    }

    document.querySelectorAll('[data-save-mode]').forEach((button) => {
        button.addEventListener('click', function() {
            saveModeInput.value = this.dataset.saveMode || 'draft';
        });
    });

    const form = document.querySelector('form[action^="fill_report.php"]');
    if (form) {
        form.addEventListener('submit', function(event) {
            if (isLockedWorkspace) {
                event.preventDefault();
                alert('This uploaded copy is locked. Click "Create Copy" to submit a new version.');
                return;
            }

            if (isNewCopyWorkspace && saveModeInput) {
                saveModeInput.value = 'complete';
            }

            const activeMode = saveModeInput.value || 'draft';
            if (!doctorSelect || doctorSelect.value.trim() === '') {
                event.preventDefault();
                if (activeMode === 'complete') {
                    alert('Please choose a reporting radiologist before uploading this report.');
                } else {
                    alert('Please choose a reporting radiologist before saving this report.');
                }
                if (doctorSelect) {
                    doctorSelect.focus();
                }
                return;
            }

            syncEditorContent();
            const plainText = editor.getText().replace(/\u00a0/g, ' ').trim();
            if (plainText === '') {
                event.preventDefault();
                alert('Report content cannot be empty.');
            }
        });
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>