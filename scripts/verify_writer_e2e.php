<?php
/**
 * End-to-end writer workflow verifier (non-destructive with row restore).
 *
 * Validates:
 * 1) Draft save sets report_docx_path and creates DOCX in radiologist folder.
 * 2) Reload hydration prefers DOCX payload over DB report_content fallback.
 * 3) Upload mode keeps visibility rules (manager sees Completed only).
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

// Keep verifier resilient on older snapshots by ensuring writer columns exist.
if (function_exists('table_scale_apply_alter_to_all_physical_tables')) {
    table_scale_apply_alter_to_all_physical_tables($conn, 'bill_items', "ADD COLUMN IF NOT EXISTS reporting_doctor VARCHAR(150) DEFAULT NULL");
    table_scale_apply_alter_to_all_physical_tables($conn, 'bill_items', "ADD COLUMN IF NOT EXISTS report_docx_path VARCHAR(600) DEFAULT NULL");
    table_scale_apply_alter_to_all_physical_tables($conn, 'bill_items', "ADD COLUMN IF NOT EXISTS report_copy_number INT NOT NULL DEFAULT 1");
} else {
    $conn->query("ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS reporting_doctor VARCHAR(150) DEFAULT NULL");
    $conn->query("ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS report_docx_path VARCHAR(600) DEFAULT NULL");
    $conn->query("ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS report_copy_number INT NOT NULL DEFAULT 1");
}

function v_xml_escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function v_build_docx_body_from_html(string $html): string {
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
        $paragraphs[] = '<w:p><w:r><w:t xml:space="preserve">' . v_xml_escape($line) . '</w:t></w:r></w:p>';
    }

    if (empty($paragraphs)) {
        $paragraphs[] = '<w:p><w:r><w:t xml:space="preserve"></w:t></w:r></w:p>';
    }

    return implode('', $paragraphs);
}

function v_create_docx(string $absolute_path, string $html_content, array $meta = []): bool {
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

    $document_body = v_build_docx_body_from_html($html_content);
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
        . '  <dc:title>' . v_xml_escape($title) . '</dc:title>'
        . '  <dc:creator>Diagnostic Center</dc:creator>'
        . '  <cp:lastModifiedBy>Diagnostic Center</cp:lastModifiedBy>'
        . '  <dcterms:created xsi:type="dcterms:W3CDTF">' . v_xml_escape($saved_at) . '</dcterms:created>'
        . '  <dcterms:modified xsi:type="dcterms:W3CDTF">' . v_xml_escape($saved_at) . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $app_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '  <Application>Diagnostic Center Writer</Application>'
        . '</Properties>';

    $custom_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<writerReport xmlns="https://diagnostic-center.local/schemas/writer-report">'
        . '  <savedAt>' . v_xml_escape($saved_at) . '</savedAt>'
        . '  <radiologist>' . v_xml_escape($radiologist) . '</radiologist>'
        . '  <billItemId>' . v_xml_escape($bill_item_id) . '</billItemId>'
        . '  <mainTest>' . v_xml_escape($report_main_test) . '</mainTest>'
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

function v_resolve_docx_absolute(string $relative_path): ?string {
    $normalized = function_exists('data_storage_normalize_relative_path')
        ? data_storage_normalize_relative_path($relative_path)
        : trim(str_replace('\\', '/', $relative_path));

    if (!is_string($normalized) || $normalized === '') {
        return null;
    }

    if (function_exists('data_storage_resolve_primary_or_mirror')) {
        $resolved = data_storage_resolve_primary_or_mirror($normalized);
        if (is_string($resolved) && $resolved !== '' && is_file($resolved)) {
            return $resolved;
        }
    }

    $project_root = function_exists('data_storage_project_root_path')
        ? data_storage_project_root_path()
        : dirname(__DIR__);

    $absolute = $project_root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    return is_file($absolute) ? $absolute : null;
}

function v_extract_html_from_docx(string $absolute_docx_path): ?string {
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
            $entry = (string)$zip->getNameIndex($i);
            if (preg_match('#^customXml/item\\d+\\.xml$#', $entry)) {
                $candidate = $zip->getFromName($entry);
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

    $node = $html_nodes[0];
    $encoding = '';
    $attrs = $node->attributes();
    if ($attrs && isset($attrs['encoding'])) {
        $encoding = strtolower(trim((string)$attrs['encoding']));
    }

    $payload = (string)$node;
    if ($payload === '') {
        return null;
    }

    if ($encoding === 'base64') {
        $decoded = base64_decode($payload, true);
        return ($decoded === false) ? null : $decoded;
    }

    return $payload;
}

function v_manager_visible_count(mysqli $conn, int $item_id): int {
    $bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
    $bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';

    $sql = "SELECT COUNT(*) AS c
            FROM {$bill_items_source}
            JOIN {$bills_source} ON bi.bill_id = b.id
            WHERE bi.id = ?
              AND b.bill_status != 'Void'
              AND COALESCE(bi.report_status, 'Pending') = 'Completed'";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('i', $item_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return (int)($row['c'] ?? 0);
}

$result = [
    'ok' => false,
    'checks' => [],
    'item' => null,
    'paths' => [],
    'notes' => [],
];

$generated_relative = '';
$generated_absolute = '';
$generated_existed_before = false;
$mirror_generated = '';

$source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';

$candidate_sql = "SELECT bi.id, bi.bill_id, bi.report_content, bi.report_docx_path, COALESCE(bi.report_status, 'Pending') AS report_status,
                         COALESCE(bi.report_copy_number, 1) AS report_copy_number, bi.reporting_doctor,
                         b.created_at AS bill_created_at,
                         COALESCE(NULLIF(t.main_test_name, ''), 'uncategorized') AS main_test_name
                  FROM {$source}
                  JOIN {$bills_source} ON bi.bill_id = b.id
                  JOIN {$tests_source} ON bi.test_id = t.id
                  WHERE bi.item_status = 0
                    AND b.bill_status != 'Void'
                    AND (bi.report_docx_path IS NULL OR bi.report_docx_path = '')
                  ORDER BY bi.id DESC
                  LIMIT 1";

$candidate = null;
if ($candidate_res = $conn->query($candidate_sql)) {
    $candidate = $candidate_res->fetch_assoc() ?: null;
    $candidate_res->free();
}

if (!$candidate) {
    $fallback_sql = "SELECT bi.id, bi.bill_id, bi.report_content, bi.report_docx_path, COALESCE(bi.report_status, 'Pending') AS report_status,
                            COALESCE(bi.report_copy_number, 1) AS report_copy_number, bi.reporting_doctor,
                            b.created_at AS bill_created_at,
                            COALESCE(NULLIF(t.main_test_name, ''), 'uncategorized') AS main_test_name
                     FROM {$source}
                     JOIN {$bills_source} ON bi.bill_id = b.id
                     JOIN {$tests_source} ON bi.test_id = t.id
                     WHERE bi.item_status = 0
                       AND b.bill_status != 'Void'
                     ORDER BY bi.id DESC
                     LIMIT 1";
    if ($fallback_res = $conn->query($fallback_sql)) {
        $candidate = $fallback_res->fetch_assoc() ?: null;
        $fallback_res->free();
    }
}

if (!$candidate) {
    $result['notes'][] = 'No active bill item found for verification.';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

$item_id = (int)$candidate['id'];
$bill_id = (int)$candidate['bill_id'];
$main_test_name = trim((string)($candidate['main_test_name'] ?? 'uncategorized'));
$current_copy = max(1, (int)($candidate['report_copy_number'] ?? 1));

$reporting_list = function_exists('get_reporting_radiologist_list') ? get_reporting_radiologist_list() : [];
$doctor = !empty($reporting_list) ? (string)$reporting_list[0] : 'Dr. Placeholder';

$write_table = function_exists('table_scale_find_physical_table_by_id')
    ? (table_scale_find_physical_table_by_id($conn, 'bill_items', $item_id, 'id') ?: 'bill_items')
    : 'bill_items';
$write_table = preg_match('/^[A-Za-z0-9_]+$/', (string)$write_table) ? (string)$write_table : 'bill_items';

$original = [
    'report_content' => (string)($candidate['report_content'] ?? ''),
    'report_docx_path' => (string)($candidate['report_docx_path'] ?? ''),
    'report_status' => (string)($candidate['report_status'] ?? 'Pending'),
    'reporting_doctor' => isset($candidate['reporting_doctor']) ? (string)$candidate['reporting_doctor'] : null,
    'report_copy_number' => $current_copy,
];

$result['item'] = [
    'item_id' => $item_id,
    'bill_id' => $bill_id,
    'main_test_name' => $main_test_name,
    'write_table' => $write_table,
];

$token = 'E2E_' . date('Ymd_His') . '_' . substr(md5((string)microtime(true)), 0, 8);
$draft_html = '<p>Writer draft ' . $token . '</p><p>Line two</p>';
$upload_html = '<p>Writer upload ' . $token . '</p><p>Final line</p>';
$db_fallback_html = '<p>DB_FALLBACK_' . $token . '</p>';

try {
    $storage_meta = function_exists('data_storage_reports_directory')
        ? data_storage_reports_directory($doctor, $main_test_name)
        : [
            'relative_path' => 'data/reports/' . date('Y') . '/' . date('m') . '/uncategorized/' . date('d') . '/reports',
            'absolute_path' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'reports',
        ];

    $copy_number = ($original['report_status'] === 'Completed') ? ($current_copy + 1) : $current_copy;
    if ($copy_number < 1) {
        $copy_number = 1;
    }

    $copy_suffix = ($copy_number > 1) ? '_copy-' . $copy_number : '';
    $file_name = 'bill_' . max(1, $bill_id) . '_item_' . $item_id . $copy_suffix . '.docx';
    $generated_relative = rtrim((string)$storage_meta['relative_path'], '/') . '/' . $file_name;
    $generated_absolute = rtrim((string)$storage_meta['absolute_path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file_name;
    $generated_existed_before = is_file($generated_absolute);

    $draft_docx_ok = v_create_docx($generated_absolute, $draft_html, [
        'title' => 'Bill #' . $bill_id,
        'radiologist' => $doctor,
        'bill_item_id' => (string)$item_id,
        'main_test' => $main_test_name,
    ]);

    $result['checks']['draft_docx_created'] = $draft_docx_ok && is_file($generated_absolute);

    if (!$result['checks']['draft_docx_created']) {
        throw new RuntimeException('Failed creating draft DOCX file.');
    }

    if (function_exists('data_storage_copy_absolute_file_to_mirror')) {
        $mirror = data_storage_copy_absolute_file_to_mirror($generated_absolute);
        $mirror_generated = is_string($mirror) ? (string)$mirror : '';
    }

    $upd = $conn->prepare("UPDATE `{$write_table}`
                           SET report_content = ?, report_docx_path = ?, report_status = 'Pending', reporting_doctor = ?, report_copy_number = ?, updated_at = NOW()
                           WHERE id = ?");
    if (!$upd) {
        throw new RuntimeException('Failed preparing draft update.');
    }

    $upd->bind_param('sssii', $draft_html, $generated_relative, $doctor, $copy_number, $item_id);
    if (!$upd->execute()) {
        $upd->close();
        throw new RuntimeException('Draft update execution failed.');
    }
    $upd->close();

    $verify_stmt = $conn->prepare("SELECT report_docx_path, COALESCE(report_status, 'Pending') AS report_status, reporting_doctor, report_content FROM `{$write_table}` WHERE id = ?");
    $verify_stmt->bind_param('i', $item_id);
    $verify_stmt->execute();
    $verify_row = $verify_stmt->get_result()->fetch_assoc() ?: [];
    $verify_stmt->close();

    $saved_path = (string)($verify_row['report_docx_path'] ?? '');
    $result['paths']['draft_saved_path'] = $saved_path;
    $result['paths']['draft_absolute_path'] = $generated_absolute;
    $result['checks']['draft_db_path_set'] = ($saved_path === $generated_relative);
    $result['checks']['draft_radiologist_folder'] = (strpos($saved_path, 'data/reports/' . data_storage_safe_segment($doctor, 'unassigned_radiologist') . '/') === 0);
    $result['checks']['draft_status_pending'] = ((string)($verify_row['report_status'] ?? '') === 'Pending');

    // Force DB fallback content mismatch; hydration should still read DOCX payload.
    $mut = $conn->prepare("UPDATE `{$write_table}` SET report_content = ? WHERE id = ?");
    $mut->bind_param('si', $db_fallback_html, $item_id);
    $mut->execute();
    $mut->close();

    $reload_stmt = $conn->prepare("SELECT report_content, report_docx_path FROM `{$write_table}` WHERE id = ?");
    $reload_stmt->bind_param('i', $item_id);
    $reload_stmt->execute();
    $reload_row = $reload_stmt->get_result()->fetch_assoc() ?: [];
    $reload_stmt->close();

    $resolved = v_resolve_docx_absolute((string)($reload_row['report_docx_path'] ?? ''));
    $hydrated_html = $resolved ? v_extract_html_from_docx($resolved) : null;
    $db_content_after_mutation = (string)($reload_row['report_content'] ?? '');

    $result['checks']['reload_docx_resolved'] = is_string($resolved) && $resolved !== '';
    $result['checks']['reload_hydrates_from_docx'] = is_string($hydrated_html) && ($hydrated_html === $draft_html);
    $result['checks']['reload_not_using_db_fallback'] = is_string($hydrated_html) && ($hydrated_html !== $db_content_after_mutation);

    // Manager visibility while Draft (Pending): should be hidden.
    $visible_draft = v_manager_visible_count($conn, $item_id);
    $result['checks']['manager_hidden_before_upload'] = ($visible_draft === 0);

    // Upload mode simulation: overwrite same DOCX + mark Completed.
    $upload_docx_ok = v_create_docx($generated_absolute, $upload_html, [
        'title' => 'Bill #' . $bill_id,
        'radiologist' => $doctor,
        'bill_item_id' => (string)$item_id,
        'main_test' => $main_test_name,
    ]);
    $result['checks']['upload_docx_written'] = $upload_docx_ok && is_file($generated_absolute);

    $upd2 = $conn->prepare("UPDATE `{$write_table}`
                            SET report_content = ?, report_docx_path = ?, report_status = 'Completed', reporting_doctor = ?, report_copy_number = ?, updated_at = NOW()
                            WHERE id = ?");
    if (!$upd2) {
        throw new RuntimeException('Failed preparing upload update.');
    }

    $upd2->bind_param('sssii', $upload_html, $generated_relative, $doctor, $copy_number, $item_id);
    if (!$upd2->execute()) {
        $upd2->close();
        throw new RuntimeException('Upload update execution failed.');
    }
    $upd2->close();

    $post_upload_visible = v_manager_visible_count($conn, $item_id);
    $result['checks']['manager_visible_after_upload'] = ($post_upload_visible === 1);

    $upload_row_stmt = $conn->prepare("SELECT COALESCE(report_status, 'Pending') AS report_status FROM `{$write_table}` WHERE id = ?");
    $upload_row_stmt->bind_param('i', $item_id);
    $upload_row_stmt->execute();
    $upload_row = $upload_row_stmt->get_result()->fetch_assoc() ?: [];
    $upload_row_stmt->close();
    $result['checks']['upload_status_completed'] = ((string)($upload_row['report_status'] ?? '') === 'Completed');

    $resolved_after_upload = v_resolve_docx_absolute($generated_relative);
    $hydrated_upload = $resolved_after_upload ? v_extract_html_from_docx($resolved_after_upload) : null;
    $result['checks']['upload_docx_payload_roundtrip'] = is_string($hydrated_upload) && ($hydrated_upload === $upload_html);

    $all_ok = true;
    foreach ($result['checks'] as $check_value) {
        if ($check_value !== true) {
            $all_ok = false;
            break;
        }
    }
    $result['ok'] = $all_ok;

} catch (Throwable $e) {
    $result['ok'] = false;
    $result['notes'][] = 'Verifier error: ' . $e->getMessage();
} finally {
    // Restore original row state.
    $restore = $conn->prepare("UPDATE `{$write_table}`
                               SET report_content = ?, report_docx_path = ?, report_status = ?, reporting_doctor = ?, report_copy_number = ?, updated_at = NOW()
                               WHERE id = ?");
    if ($restore) {
        $restore_docx = (string)$original['report_docx_path'];
        $restore_doctor = $original['reporting_doctor'];
        $restore->bind_param(
            'ssssii',
            $original['report_content'],
            $restore_docx,
            $original['report_status'],
            $restore_doctor,
            $original['report_copy_number'],
            $item_id
        );
        $restore->execute();
        $restore->close();
    }

    // Remove generated test artifact if it did not exist before this run.
    if ($generated_absolute !== '' && !$generated_existed_before && is_file($generated_absolute)) {
        @unlink($generated_absolute);
    }

    if ($generated_relative !== '' && function_exists('data_storage_mirror_relative_path')) {
        $mirror_rel = data_storage_mirror_relative_path($generated_relative);
        $root = function_exists('data_storage_project_root_path') ? data_storage_project_root_path() : dirname(__DIR__);
        $mirror_abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mirror_rel);
        if (!$generated_existed_before && is_file($mirror_abs)) {
            @unlink($mirror_abs);
        }
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
