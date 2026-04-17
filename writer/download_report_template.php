<?php
$page_title = "Download Report Template";
$required_role = "writer";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
$debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($item_id <= 0) {
    http_response_code(400);
    echo "Invalid report item.";
    exit;
}

$bill_items_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bill_items', 'bi') : '`bill_items` bi';
$bills_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'bills', 'b') : '`bills` b';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$tests_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'tests', 't') : '`tests` t';
$referral_doctors_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd') : '`referral_doctors` rd';
$patient_uid_expression = function_exists('get_patient_identifier_expression') ? get_patient_identifier_expression($conn, 'p') : 'CAST(p.id AS CHAR)';

$sql = "SELECT
            bi.id AS bill_item_id,
            b.id AS bill_id,
            b.created_at AS bill_created_at,
            p.name AS patient_name,
            {$patient_uid_expression} AS patient_uid,
            p.age AS patient_age,
            p.sex AS patient_sex,
            COALESCE(rd.doctor_name, '') AS referral_doctor,
            b.referral_source_other,
            b.referral_type,
            t.main_test_name,
            t.sub_test_name,
            t.document AS template_path
        FROM {$bill_items_source}
        JOIN {$bills_source} ON bi.bill_id = b.id
        JOIN {$patients_source} ON b.patient_id = p.id
        LEFT JOIN {$referral_doctors_source} ON b.referral_doctor_id = rd.id
        JOIN {$tests_source} ON bi.test_id = t.id
        WHERE bi.id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo "Unable to fetch report details.";
    exit;
}

$stmt->bind_param('i', $item_id);
$stmt->execute();
$result = $stmt->get_result();
$report = $result->fetch_assoc();
$stmt->close();

if (!$report) {
    http_response_code(404);
    echo "Report item not found.";
    exit;
}

$reportTemplateBaseDir = dirname(__DIR__) . '/templates/report_templates/';
$reportTemplateBaseUrl = '../templates/report_templates/';
$legacyTemplateBaseDir = dirname(__DIR__) . '/uploads/test_documents/';

function resolve_template_absolute($document, $newBaseDir, $legacyBaseDir) {
    if (empty($document)) {
        return [false, null];
    }
    // Normalize slashes
    $normalized = str_replace('\\', '/', $document);
    
    // Remove typical relative prefixes
    $normalized = str_replace(['../', '..\\'], '', $normalized);

    // Handle paths stored as web absolute paths (remove project folder prefix if present)
    $contextPrefix = rtrim(str_replace(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '', str_replace('\\', '/', dirname(__DIR__))), '/') . '/';
    if ($contextPrefix === '/') $contextPrefix = '/';
    // Also try legacy hardcoded prefix
    $legacyPrefix = '/diagnostic-center/';
    if (stripos($normalized, $legacyPrefix) === 0) {
         $normalized = substr($normalized, strlen($legacyPrefix));
    } elseif (strlen($contextPrefix) > 1 && stripos($normalized, $contextPrefix) === 0) {
         $normalized = substr($normalized, strlen($contextPrefix));
    } elseif ($contextPrefix === '/' && substr($normalized, 0, 1) === '/') {
         $normalized = ltrim($normalized, '/');
    }

    $normalized = trim($normalized, '/');
    if ($normalized === '') {
        return [false, null];
    }
    $projectRoot = dirname(__DIR__);
    $segments = str_replace('/', DIRECTORY_SEPARATOR, $normalized);

    $directCandidate = $projectRoot . DIRECTORY_SEPARATOR . $segments;
    if (file_exists($directCandidate)) {
        return [true, $directCandidate];
    }

    $relativeCandidates = [
        'templates/report_templates/' . $normalized,
        'uploads/report_templates/' . $normalized,
    ];
    foreach ($relativeCandidates as $relativeCandidate) {
        $candidateAbsolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeCandidate);
        if (file_exists($candidateAbsolute)) {
            return [true, $candidateAbsolute];
        }
    }

    $candidate = rtrim($newBaseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $segments;
    if (file_exists($candidate)) {
        return [true, $candidate];
    }

    $legacyCandidate = rtrim($legacyBaseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($normalized);
    if (file_exists($legacyCandidate)) {
        return [true, $legacyCandidate];
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
                return [true, $matches[0]];
            }
        }
    }

    return [false, null];
}

list($templateExists, $templateAbsolutePath) = resolve_template_absolute(
    $report['template_path'],
    $reportTemplateBaseDir,
    $legacyTemplateBaseDir
);

if (!$templateExists || !$templateAbsolutePath) {
    http_response_code(404);
    echo "Template unavailable for this test.";
    exit;
}

// Fallback logic for when ZipArchive is missing:
// We will still allow downloading the template, but it won't have patient data pre-filled.
$zipSupportAvailable = class_exists('ZipArchive');

$patientName = $report['patient_name'] ?? '';
$patientUid = trim((string)($report['patient_uid'] ?? ''));
$patientUid = $patientUid !== '' ? $patientUid : 'N/A';
$patientAge = $report['patient_age'] !== null ? trim((string)$report['patient_age']) : '';
$patientGender = $report['patient_sex'] ?? '';
$patientGenderShort = strtoupper(substr(trim((string)$patientGender), 0, 1));
if (!in_array($patientGenderShort, ['M', 'F', 'O'], true)) {
    $patientGenderShort = trim((string)$patientGender) !== '' ? strtoupper(trim((string)$patientGender)) : '';
}
$ageSexValue = trim($patientAge . ($patientAge !== '' ? ' Y' : ''));
if ($patientGenderShort !== '') {
    $ageSexValue = trim($ageSexValue . ' / ' . $patientGenderShort);
}
$refDoctor = trim($report['referral_doctor'] ?? '');
if (($report['referral_type'] ?? '') === 'Other' && !empty($report['referral_source_other'])) {
    $refDoctor = trim((string)$report['referral_source_other']);
}
if ($refDoctor !== '' && stripos($refDoctor, 'Dr.') !== 0) {
    $refDoctor = 'Dr. ' . $refDoctor;
}
if ($refDoctor === '') {
    $refDoctor = 'Self';
}
$billDateTime = $report['bill_created_at'];
$reportDate = $billDateTime ? date('d-M-Y', strtotime($billDateTime)) : date('d-M-Y');

$replacements = [
    '{{NAME}}' => $patientName,
    '{{PATIENT_NAME}}' => $patientName,
    '{{PATIENT_UID}}' => $patientUid,
    '{{UID}}' => $patientUid,
    '{{UHID}}' => $patientUid,
    '{{PATIENT_ID}}' => $patientUid,
    '{{REG_NO}}' => $patientUid,
    '{{REGISTRATION_ID}}' => $patientUid,
    '{{AGE}}' => $patientAge,
    '{{AGE_SEX}}' => $ageSexValue,
    '{{GENDER}}' => $patientGender,
    '{{SEX}}' => $patientGenderShort,
    '{{REF_DR}}' => $refDoctor,
    '{{REF_DOCTOR}}' => $refDoctor,
    '{{REFERRED_BY}}' => $refDoctor,
    '{{REFERRED_DOCTOR}}' => $refDoctor,
    '{{DATE}}' => $reportDate,
    '{{REPORT_DATE}}' => $reportDate,
    '{{BILL_NO}}' => $report['bill_id'],
];

$xmlSafeReplacements = [];
foreach ($replacements as $placeholder => $value) {
    $xmlSafeReplacements[$placeholder] = htmlspecialchars($value, ENT_QUOTES | ENT_XML1);
}

$tempFile = tempnam(sys_get_temp_dir(), 'writer_report_');
$workingFile = $tempFile . '.docx';
unlink($tempFile);

if (!copy($templateAbsolutePath, $workingFile)) {
    http_response_code(500);
    echo "Unable to prepare report template.";
    exit;
}

function append_value_after_label($xml, $labels, $value, &$debugLabelHits = null, $partName = '', $fieldKey = '') {
    if ($value === '') {
        return $xml;
    }

    $labelList = is_array($labels) ? $labels : [$labels];

    foreach ($labelList as $label) {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        if (@$dom->loadXML($xml)) {
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $query = "//w:t[contains(translate(., 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'), '" . strtoupper($label) . "')]";
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length > 0) {
                $node = $nodes->item(0);
                if (stripos($node->nodeValue, $value) === false) {
                    $node->nodeValue = preg_replace(
                        '/' . preg_quote($label, '/') . '/iu',
                        $label . ' ' . $value,
                        $node->nodeValue,
                        1
                    );
                }
                if (is_array($debugLabelHits)) {
                    $debugLabelHits[] = [
                        'part' => $partName,
                        'field' => $fieldKey,
                        'label' => $label,
                        'method' => 'dom',
                    ];
                }
                $xml = $dom->saveXML();
                break;
            }
        }

        $escapedValue = htmlspecialchars($value, ENT_QUOTES | ENT_XML1);
        $pattern = '/' . preg_quote($label, '/') . '(\s*)/iu';
        if (preg_match($pattern, $xml) === 1) {
            $xml = preg_replace($pattern, $label . ' ' . $escapedValue . '$1', $xml, 1);
            if (is_array($debugLabelHits)) {
                $debugLabelHits[] = [
                    'part' => $partName,
                    'field' => $fieldKey,
                    'label' => $label,
                    'method' => 'regex',
                ];
            }
            break;
        }
    }

    return $xml;
}

function fill_report_xml_content($xml, $xmlSafeReplacements, $patientName, $patientUid, $patientAge, $patientGender, $patientGenderShort, $ageSexValue, $refDoctor, $reportDate, &$debugPlaceholderHits = null, &$debugLabelHits = null, $partName = '') {
    if (is_array($debugPlaceholderHits)) {
        foreach ($xmlSafeReplacements as $placeholder => $_value) {
            if (strpos($xml, $placeholder) !== false) {
                $debugPlaceholderHits[] = [
                    'part' => $partName,
                    'placeholder' => $placeholder,
                ];
            }
        }
    }

    $xml = str_replace(array_keys($xmlSafeReplacements), array_values($xmlSafeReplacements), $xml);

    $xml = append_value_after_label($xml, ['NAME:', 'NAME :', 'PATIENT NAME:', 'PATIENT NAME :'], $patientName, $debugLabelHits, $partName, 'name');
    $xml = append_value_after_label($xml, ['UID:', 'UID :', 'UHID:', 'UHID :', 'PATIENT ID:', 'PATIENT ID :', 'PATIENT UID:', 'PATIENT UID :', 'REG NO:', 'REG NO :', 'REGISTRATION ID:', 'REGISTRATION ID :'], $patientUid, $debugLabelHits, $partName, 'patient_uid');
    $xml = append_value_after_label($xml, ['AGE/SEX:', 'AGE /SEX:', 'AGE / SEX:', 'AGE/ SEX:', 'AGE /SEX :', 'AGE / SEX :'], $ageSexValue, $debugLabelHits, $partName, 'age_sex');
    $xml = append_value_after_label($xml, ['AGE:', 'AGE :'], $patientAge, $debugLabelHits, $partName, 'age');
    $xml = append_value_after_label($xml, ['SEX:', 'SEX :', 'GENDER:', 'GENDER :'], $patientGenderShort !== '' ? $patientGenderShort : $patientGender, $debugLabelHits, $partName, 'sex');
    $xml = append_value_after_label($xml, ['REF DR:', 'REF DR :', 'REF.DR.:', 'REF.DR :', 'REFERRING DOCTOR:', 'REFERRING DOCTOR :'], $refDoctor, $debugLabelHits, $partName, 'ref_doctor');
    $xml = append_value_after_label($xml, ['DATE:', 'DATE :'], $reportDate, $debugLabelHits, $partName, 'date');

    return $xml;
}

if ($zipSupportAvailable) {
    $debugPlaceholderHits = [];
    $debugLabelHits = [];
    $zip = new ZipArchive();
    if ($zip->open($workingFile) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if (!preg_match('#^word/(document|header[0-9]+|footer[0-9]+)\.xml$#', (string)$entryName)) {
                continue;
            }

            $xml = $zip->getFromName($entryName);
            if ($xml === false) {
                continue;
            }

            $xml = fill_report_xml_content(
                $xml,
                $xmlSafeReplacements,
                $patientName,
                $patientUid,
                $patientAge,
                $patientGender,
                $patientGenderShort,
                $ageSexValue,
                $refDoctor,
                $reportDate,
                $debugPlaceholderHits,
                $debugLabelHits,
                (string)$entryName
            );

            $zip->addFromString($entryName, $xml);
        }
        $zip->close();

        if ($debugMode) {
            $debugPayload = [
                'item_id' => $item_id,
                'bill_id' => (int)$report['bill_id'],
                'template' => basename((string)$templateAbsolutePath),
                'placeholders_found' => $debugPlaceholderHits,
                'labels_found' => $debugLabelHits,
            ];
            error_log('[writer-docx-debug] ' . json_encode($debugPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
}

$cleanPatient = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $patientName);
$cleanSubtest = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $report['sub_test_name']);
$downloadName = sprintf('%s_%s_%s.docx',
    $report['bill_id'],
    $cleanPatient ?: 'Patient',
    $cleanSubtest ?: 'Subtest'
);

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($workingFile));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$bufferLevel = ob_get_level();
while ($bufferLevel-- > 0) {
    ob_end_clean();
}

$fh = fopen($workingFile, 'rb');
if ($fh) {
    while (!feof($fh)) {
        echo fread($fh, 8192);
        flush();
    }
    fclose($fh);
}

@unlink($workingFile);
exit;