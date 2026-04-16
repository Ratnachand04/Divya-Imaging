<?php
$page_title = "Word Report Workspace";
$required_role = ["writer", "superadmin"];
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$current_role = $_SESSION['role'] ?? 'writer';
$cancel_link = ($current_role === 'superadmin') ? '../superadmin/patients.php' : 'dashboard.php';

$item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
if (!$item_id) {
    $fallback = ($current_role === 'superadmin') ? '../superadmin/patients.php' : 'dashboard.php';
    header("Location: " . $fallback);
    exit();
}

// ── Ensure reporting_doctor column exists on bill_items ──────────────────
$conn->query("ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS reporting_doctor VARCHAR(150) DEFAULT NULL");

$radiologist_list = get_reporting_radiologist_list();

$fetch_report_details = static function (mysqli $conn, int $item_id): ?array {
    $stmt_fetch = $conn->prepare(
        "SELECT
            bi.report_content,
            COALESCE(bi.report_status, 'Pending') AS report_status,
            bi.reporting_doctor,
            b.id AS bill_id,
            p.name AS patient_name,
            p.age,
            p.sex,
            b.created_at AS bill_date,
            t.sub_test_name,
            t.document,
            rd.doctor_name AS referring_doctor_name,
            b.referral_source_other,
            b.referral_type
         FROM bill_items bi
         JOIN bills b ON bi.bill_id = b.id
         JOIN patients p ON b.patient_id = p.id
         JOIN tests t ON bi.test_id = t.id
         LEFT JOIN referral_doctors rd ON b.referral_doctor_id = rd.id
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

$report_details = $fetch_report_details($conn, $item_id);
if (!$report_details) {
    die("Report details not found.");
}

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

    if ($report_content === '') {
        $flash_error = 'Report content cannot be empty.';
    }

    $reporting_doctor = isset($_POST['reporting_doctor']) ? trim($_POST['reporting_doctor']) : '';
    $valid_reporting_doctor = in_array($reporting_doctor, $radiologist_list, true) ? $reporting_doctor : null;

    if ($save_mode === 'complete' && $valid_reporting_doctor === null) {
        $flash_error = 'Please choose a reporting radiologist before uploading this report.';
    }

    if ($flash_error === '') {
        // Keep previously selected valid doctor when saving draft with empty selector.
        if ($valid_reporting_doctor === null && !empty($report_details['reporting_doctor']) && in_array($report_details['reporting_doctor'], $radiologist_list, true)) {
            $valid_reporting_doctor = $report_details['reporting_doctor'];
        }

        $stmt_update = $conn->prepare("UPDATE bill_items SET report_content = ?, report_status = ?, reporting_doctor = ?, updated_at = NOW() WHERE id = ?");
        if ($stmt_update) {
            $stmt_update->bind_param("sssi", $report_content, $target_status, $valid_reporting_doctor, $item_id);
            if ($stmt_update->execute()) {
                $_SESSION['writer_report_success'] = $save_mode === 'complete'
                    ? "Report for Bill #{$report_details['bill_id']} uploaded successfully. It is now available to manager and superadmin."
                    : "Report for Bill #{$report_details['bill_id']} saved as draft. Only writer and superadmin can continue editing before upload.";
                $stmt_update->close();
                header("Location: fill_report.php?item_id=" . $item_id);
                exit();
            }
            $flash_error = 'Unable to save the report right now. Please try again.';
            $stmt_update->close();
        } else {
            $flash_error = 'Unable to prepare the save operation right now. Please try again.';
        }
    }
}

$existing_doctor = trim((string)($report_details['reporting_doctor'] ?? ''));
$report_status = trim((string)($report_details['report_status'] ?? 'Pending'));
$report_status_label = ($report_status === 'Completed') ? 'Uploaded' : $report_status;

$referring_doctor = trim((string)($report_details['referring_doctor_name'] ?? ''));
if (($report_details['referral_type'] ?? '') === 'Other' && !empty($report_details['referral_source_other'])) {
    $referring_doctor = trim((string)$report_details['referral_source_other']);
}
if ($referring_doctor === '') {
    $referring_doctor = 'Self';
} elseif (stripos($referring_doctor, 'Dr.') !== 0) {
    $referring_doctor = 'Dr. ' . $referring_doctor;
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

<script src="/assets/vendor/mammoth/mammoth.browser.min.js"></script>
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
    <h1>Word Report Workspace: <?php echo htmlspecialchars($report_details['sub_test_name']); ?></h1>

    <?php if ($flash_success !== ''): ?>
        <div class="reports-alert" style="margin-bottom:12px;"><?php echo htmlspecialchars($flash_success); ?></div>
    <?php endif; ?>
    <?php if ($flash_error !== ''): ?>
        <div class="reports-alert is-warning" style="margin-bottom:12px;"><?php echo htmlspecialchars($flash_error); ?></div>
    <?php endif; ?>

    <div class="patient-details-header">
        <strong>Patient:</strong> <span id="patient-name"><?php echo htmlspecialchars($report_details['patient_name']); ?></span> | 
        <strong>Age/Gender:</strong> <span id="patient-age"><?php echo $report_details['age']; ?></span>/<span id="patient-sex"><?php echo $report_details['sex']; ?></span> | 
        <strong>Bill No:</strong> <span id="bill-id"><?php echo $report_details['bill_id']; ?></span> |
        <strong>Status:</strong> <span style="font-weight:700;color:<?php echo $report_status === 'Completed' ? '#1f6d47' : '#9c4221'; ?>;"><?php echo htmlspecialchars($report_status_label); ?></span>
    </div>
    <p class="description" style="margin-top:8px;">
        Save stores a draft for writer/superadmin editing. Upload submits this report to manager and superadmin.
    </p>

    <div id="report-data"
         data-template-url="download_report_template.php?item_id=<?php echo urlencode((string)$item_id); ?>"
            data-upload-image-url="upload_report_image.php"
            data-item-id="<?php echo htmlspecialchars((string)$item_id); ?>"
         data-referring-doctor="<?php echo htmlspecialchars($referring_doctor); ?>"
         style="display: none;">
    </div>
    <textarea id="existing_report_html" style="display:none;"><?php echo htmlspecialchars((string)($report_details['report_content'] ?? '')); ?></textarea>

    <form action="fill_report.php?item_id=<?php echo $item_id; ?>" method="POST">
        <input type="hidden" name="save_mode" id="saveModeInput" value="<?php echo $report_status === 'Completed' ? 'complete' : 'draft'; ?>">

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
            <a href="<?php echo htmlspecialchars($cancel_link); ?>" class="btn-cancel">Cancel</a>
            <?php if ($report_status === 'Completed'): ?>
                <button type="submit" class="btn-secondary" data-save-mode="draft">Save as Draft</button>
                <button type="submit" class="btn-submit" data-save-mode="complete">Save & Re-Upload</button>
            <?php else: ?>
                <button type="submit" class="btn-secondary" data-save-mode="draft">Save Document</button>
                <button type="submit" class="btn-submit" data-save-mode="complete">Upload to Manager & Superadmin</button>
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

    const templateUrl = reportDataContainer.dataset.templateUrl || '';
    const uploadImageUrl = reportDataContainer.dataset.uploadImageUrl || 'upload_report_image.php';
    const itemId = reportDataContainer.dataset.itemId || '';
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
            if (!editor) {
                return;
            }
            actionHandlers.setFontFamily();
            syncEditorContent();
            updateToolbarState();
        });
    }

    if (fontSizeSelect) {
        fontSizeSelect.addEventListener('change', function() {
            if (!editor) {
                return;
            }
            actionHandlers.setFontSize();
            syncEditorContent();
            updateToolbarState();
        });
    }

    if (lineHeightSelect) {
        lineHeightSelect.addEventListener('change', function() {
            if (!editor) {
                return;
            }
            actionHandlers.setLineHeight();
            syncEditorContent();
            updateToolbarState();
        });
    }

    if (imageWidthRange) {
        imageWidthRange.addEventListener('input', function() {
            if (!editor || !editor.isActive('image')) {
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
            if (!editor || !this.files || !this.files.length) {
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
            const expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            shortcutsPanel.hidden = expanded;
        });
    }

    if (editorMount) {
        editorMount.addEventListener('keydown', function(event) {
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

    const patientName = document.getElementById('patient-name').textContent;
    const patientAge = document.getElementById('patient-age').textContent;
    const patientSex = document.getElementById('patient-sex').textContent;
    const billId = document.getElementById('bill-id').textContent;
    const referredBy = reportDataContainer.dataset.referringDoctor || 'Self';

    const patientHeaderHtml = `
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;" border="1">
            <tbody>
                <tr>
                    <td style="padding: 8px;"><strong>Patient Name:</strong></td>
                    <td style="padding: 8px;">${patientName}</td>
                    <td style="padding: 8px;"><strong>Age/Gender:</strong></td>
                    <td style="padding: 8px;">${patientAge} / ${patientSex}</td>
                </tr>
                <tr>
                    <td style="padding: 8px;"><strong>Bill No:</strong></td>
                    <td style="padding: 8px;">${billId}</td>
                    <td style="padding: 8px;"><strong>Referred By:</strong></td>
                    <td style="padding: 8px;"><strong>${referredBy}</strong></td>
                </tr>
            </tbody>
        </table>
        <hr style="margin-bottom: 20px;">`;

    const replacements = {
        '{{NAME}}': patientName,
        '{{PATIENT_NAME}}': patientName,
        '{{AGE}}': patientAge,
        '{{SEX}}': patientSex,
        '{{GENDER}}': patientSex,
        '{{BILL_NO}}': billId,
        '{{REF_DR}}': referredBy,
        '{{REF_DOCTOR}}': referredBy,
        '{{AGE_SEX}}': `${patientAge} / ${patientSex}`,
    };

    const hydrateEditorContent = () => {
        if (existingHtml.trim() !== '') {
            setEditorHtml(existingHtml);
            return;
        }

        if (!templateUrl || templateUrl.trim() === '') {
            setEditorHtml(patientHeaderHtml);
            return;
        }

        if (!window.mammoth || typeof window.mammoth.convertToHtml !== 'function') {
            setEditorHtml(patientHeaderHtml + '<p style="color:#a72a5f;"><strong>Note:</strong> Template conversion library is unavailable. You can continue writing manually.</p>');
            return;
        }

        fetch(templateUrl, { credentials: 'same-origin' })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status} - ${response.statusText}`);
                }
                return response.arrayBuffer();
            })
            .then((arrayBuffer) => window.mammoth.convertToHtml({ arrayBuffer }))
            .then((result) => {
                let html = result.value || '';
                Object.keys(replacements).forEach((token) => {
                    html = html.split(token).join(String(replacements[token] || ''));
                });
                setEditorHtml(patientHeaderHtml + html);
            })
            .catch((error) => {
                console.error('Error loading DOCX template:', error);
                setEditorHtml(patientHeaderHtml + '<p style="color:#a72a5f;"><strong>Note:</strong> Could not load template. You can still write and save the report.</p>');
            });
    };

    hydrateEditorContent();

    document.querySelectorAll('[data-save-mode]').forEach((button) => {
        button.addEventListener('click', function() {
            saveModeInput.value = this.dataset.saveMode || 'draft';
        });
    });

    const form = document.querySelector('form[action^="fill_report.php"]');
    if (form) {
        form.addEventListener('submit', function(event) {
            const activeMode = saveModeInput.value || 'draft';
            if (activeMode === 'complete' && (!doctorSelect || doctorSelect.value.trim() === '')) {
                event.preventDefault();
                alert('Please choose a reporting radiologist before uploading this report.');
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