<?php
$page_title = "Fill Test Report";
$required_role = "writer";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
if (!$item_id) {
    header("Location: dashboard.php");
    exit();
}

// ── Ensure reporting_doctor column exists on bill_items ──────────────────
$conn->query("ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS reporting_doctor VARCHAR(150) DEFAULT NULL");

$radiologist_list = [
    'Dr. G. Mamatha MD (RD)',
    'Dr. G. Sri Kanth DMRD',
    'Dr. P. Madhu Babu MD',
    'Dr. Sahithi Chowdary',
    'Dr. SVN. Vamsi Krishna MD(RD)',
    'Dr. T. Koushik MD(RD)',
    'Dr. T. Rajeshwar Rao MD DMRD',
];

// Fetch all necessary details for the report header
$stmt_fetch = $conn->prepare(
    "SELECT 
        b.id as bill_id, p.name as patient_name, p.age, p.sex, 
        b.created_at as bill_date, t.sub_test_name, t.document, 
        rd.doctor_name as referring_doctor_name, b.referral_source_other, b.referral_type
     FROM bill_items bi 
     JOIN bills b ON bi.bill_id = b.id 
     JOIN patients p ON b.patient_id = p.id 
     JOIN tests t ON bi.test_id = t.id 
     LEFT JOIN referral_doctors rd ON b.referral_doctor_id = rd.id 
     WHERE bi.id = ?"
);
$stmt_fetch->bind_param("i", $item_id);
$stmt_fetch->execute();
$report_details = $stmt_fetch->get_result()->fetch_assoc();
$stmt_fetch->close();
if (!$report_details) die("Report details not found.");

// Handle form submission to save the report
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $report_content = trim($_POST['report_content']);
    $reporting_doctor = isset($_POST['reporting_doctor']) ? trim($_POST['reporting_doctor']) : '';
    // Validate against allowed list
    if (!in_array($reporting_doctor, $radiologist_list, true)) {
        $reporting_doctor = null;
    }
    $stmt_update = $conn->prepare("UPDATE bill_items SET report_content = ?, report_status = 'Completed', reporting_doctor = ? WHERE id = ?");
    $stmt_update->bind_param("ssi", $report_content, $reporting_doctor, $item_id);
    if ($stmt_update->execute()) {
        $_SESSION['success_message'] = "Report for Bill #{$report_details['bill_id']} has been saved successfully.";
        header("Location: dashboard.php");
        exit();
    }
}

// Pre-load existing reporting_doctor value if already set
$existing_doctor = null;
$rd_check = $conn->prepare("SELECT reporting_doctor FROM bill_items WHERE id = ?");
$rd_check->bind_param('i', $item_id);
$rd_check->execute();
$rd_check->bind_result($existing_doctor);
$rd_check->fetch();
$rd_check->close();

require_once '../includes/header.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.7.0/mammoth.browser.min.js"></script>
<script src="https://cdn.tiny.cloud/1/r41uafihmk98jko98ir0noqb18kzh4r4xtzbknnosb4dk2sd/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>

<div class="form-container">
    <h1>Writing Report for: <?php echo htmlspecialchars($report_details['sub_test_name']); ?></h1>
    <div class="patient-details-header">
        <strong>Patient:</strong> <span id="patient-name"><?php echo htmlspecialchars($report_details['patient_name']); ?></span> | 
        <strong>Age/Gender:</strong> <span id="patient-age"><?php echo $report_details['age']; ?></span>/<span id="patient-sex"><?php echo $report_details['sex']; ?></span> | 
        <strong>Bill No:</strong> <span id="bill-id"><?php echo $report_details['bill_id']; ?></span>
    </div>

    <div id="report-data" 
         data-document-path="<?php echo htmlspecialchars($report_details['document']); ?>"
         data-referring-doctor="<?php echo htmlspecialchars($report_details['referring_doctor_name'] ?? 'Self'); ?>"
         style="display: none;">
    </div>

    <form action="fill_report.php?item_id=<?php echo $item_id; ?>" method="POST">

        <!-- ── Reporting Doctor Selection ───────────────────────────────── -->
        <div class="fill-report-doctor-bar">
            <div class="fill-report-doctor-inner">
                <label for="reporting_doctor_select" class="fill-report-doctor-label">
                    <i class="fas fa-user-md"></i>
                    Reporting Radiologist <span class="req">*</span>
                </label>
                <select id="reporting_doctor_select" name="reporting_doctor" required class="fill-report-doctor-select">
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

        <textarea id="report_content" name="report_content"></textarea>
        <div style="margin-top:20px; text-align: right;">
            <a href="dashboard.php" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-submit">Save & Complete Report</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    tinymce.init({
        selector: '#report_content',
        
        // --- FIX: REMOVED DEPRECATED 'spellchecker' PLUGIN ---
        plugins: 'lists link image table code help wordcount autolink', 
        toolbar: 'undo redo | blocks | bold italic | alignleft aligncenter alignright | bullist numlist outdent indent | link image',
        
        // --- FIX: ENABLED BROWSER'S NATIVE SPELL CHECK ---
        browser_spellcheck: true,
        
        height: 700,
        menubar: false,
        content_style: 'body { font-family: Times New Roman, serif; font-size: 12pt; margin: 1rem auto; max-width: 8.5in; padding: 1in; }',
        
        setup: function(editor) {
            // --- PREDICTIVE AUTOCOMPLETE LOGIC (This part is correct and will now work) ---
            const medicalTerms = [
                'No significant abnormalities noted.', 'Findings are within normal limits.', 
                'Clinical correlation is recommended.', 'Further evaluation is advised.',
                'degenerative changes', 'inflammatory changes', 'post-traumatic changes',
                'lesion', 'edema', 'fracture', 'effusion', 'hematoma', 'stenosis',
                'echotexture', 'vascularity', 'calcification', 'nodule', 'mass', 'cyst',
                'Abdomen', 'Pelvis', 'Thorax', 'Cervical Spine', 'Lumbar Spine', 'Dorsal Spine',
                'MRI', 'CT Scan', 'Ultrasound', 'X-Ray',
                'benign', 'malignant', 'acute', 'chronic', 'mild', 'moderate', 'severe'
            ];

            editor.ui.registry.addAutocompleter('medicalTerms', {
                ch: '', 
                minChars: 2,
                columns: 1,
                fetch: function(pattern) {
                    return new Promise((resolve) => {
                        const lowerCasePattern = pattern.toLowerCase();
                        const matches = medicalTerms.filter(term => 
                            term.toLowerCase().includes(lowerCasePattern)
                        );
                        resolve(matches.map(term => ({
                            value: term,
                            text: term,
                            icon: '✓'
                        })));
                    });
                },
                onAction: function(autocompleteApi, rng, value) {
                    editor.selection.setRng(rng);
                    editor.insertContent(value);
                    autocompleteApi.hide();
                }
            });

            // This part loads your .docx template and patient info
            editor.on('init', function() {
                const reportDataContainer = document.getElementById('report-data');
                const docxPath = reportDataContainer.dataset.documentPath;
                
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

                if (!docxPath || docxPath.trim() === '') {
                    editor.setContent(patientHeaderHtml);
                    return;
                }

                const fullDocxUrl = docxPath;

                fetch(fullDocxUrl)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status} - ${response.statusText}`);
                        }
                        return response.arrayBuffer();
                    })
                    .then(arrayBuffer => mammoth.convertToHtml({ arrayBuffer: arrayBuffer }))
                    .then(result => {
                        editor.setContent(patientHeaderHtml + result.value);
                    })
                    .catch(error => {
                        console.error("Error loading DOCX template:", error);
                        editor.setContent(`<p style="color: red;"><strong>Error:</strong> Could not load the report template. Details: ${error.message}</p>`);
                    });
            });
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>