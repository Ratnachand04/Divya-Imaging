<?php
$page_title = "Existing Patients";
$required_role = "receptionist";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/patient_registration_helper.php';

$error_message = '';

try {
    ensure_patient_registration_schema($conn);
} catch (Exception $schema_error) {
    $error_message = "Patient module initialization failed: " . $schema_error->getMessage();
}

$search = trim($_GET['search'] ?? '');
$patients = [];

try {
    if ($search !== '') {
        $search_like = '%' . $search . '%';
        $stmt_search = $conn->prepare(
            "SELECT id, patient_unique_id, name, age, sex, mobile_number, address, referring_doctor_name, referring_doctor_contact, emergency_contact_person
             FROM patients
              WHERE (is_archived = 0 OR is_archived IS NULL) AND (name LIKE ? OR mobile_number LIKE ? OR patient_unique_id LIKE ?)
             ORDER BY id DESC
             LIMIT 100"
        );
        $stmt_search->bind_param("sss", $search_like, $search_like, $search_like);
    } else {
        $stmt_search = $conn->prepare(
            "SELECT id, patient_unique_id, name, age, sex, mobile_number, address, referring_doctor_name, referring_doctor_contact, emergency_contact_person
             FROM patients
              WHERE is_archived = 0 OR is_archived IS NULL
             ORDER BY id DESC
             LIMIT 100"
        );
    }

    if ($stmt_search) {
        $stmt_search->execute();
        $patients_result = $stmt_search->get_result();
        while ($row = $patients_result->fetch_assoc()) {
            $patients[] = $row;
        }
        $stmt_search->close();
    }
} catch (Exception $search_error) {
    if ($error_message === '') {
        $error_message = 'Could not load patient list: ' . $search_error->getMessage();
    }
}

require_once '../includes/header.php';
?>

<div class="table-container existing-patients-page">
    <div class="existing-patients-header">
        <h1>Existing Patients</h1>
        <p>Search and edit already registered patients. New patients are created from Generate Bill using the New Patient checkbox.</p>
    </div>

    <?php if ($error_message): ?><div class="error-banner"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <form method="GET" action="existing_patients.php" class="date-filter-form existing-patient-search-form">
        <div class="form-group">
            <label for="search">Search by Name / Mobile / Patient ID</label>
            <input type="text" id="search" name="search" placeholder="e.g., DC20250001 or 9876543210" value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <button type="submit" class="btn-submit">Search</button>
    </form>

    <form id="quickHistoryLookupForm" class="date-filter-form existing-patient-search-form" style="margin-top:10px;">
        <div class="form-group">
            <label for="quick_history_patient_id">Quick History by Patient ID</label>
            <input type="text" id="quick_history_patient_id" placeholder="Enter DC2026.... or numeric ID" maxlength="20">
        </div>
        <button type="submit" class="btn-submit">Open History</button>
    </form>

    <div class="table-responsive">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Patient ID</th>
                    <th>Name</th>
                    <th>Age/Gender</th>
                    <th>Mobile</th>
                    <th>Ref. Doctor</th>
                    <th>Emergency Contact</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($patients)): ?>
                    <?php foreach ($patients as $patient): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($patient['patient_unique_id'] ?: ('P-' . $patient['id'])); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($patient['name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($patient['address'] ?? ''); ?></small>
                            </td>
                            <td><?php echo (int)$patient['age']; ?> / <?php echo htmlspecialchars($patient['sex']); ?></td>
                            <td><?php echo htmlspecialchars($patient['mobile_number'] ?? '-'); ?></td>
                            <td>
                                <?php echo htmlspecialchars($patient['referring_doctor_name'] ?: '-'); ?><br>
                                <small><?php echo htmlspecialchars($patient['referring_doctor_contact'] ?: ''); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($patient['emergency_contact_person'] ?: '-'); ?></td>
                            <td class="actions-cell">
                                <a class="btn-action btn-edit" href="edit_patient.php?id=<?php echo (int)$patient['id']; ?>">Edit</a>
                                <button class="btn-action btn-view view-patient-history" data-patient-id="<?php echo (int)$patient['id']; ?>" data-patient-unique-id="<?php echo htmlspecialchars($patient['patient_unique_id'] ?? ''); ?>" data-patient-name="<?php echo htmlspecialchars($patient['name']); ?>">History</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align:center;">No patients found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Patient Visit History Modal -->
<div id="visitHistoryModal" class="modal" style="display:none;">
    <div class="modal-content" style="width: 90%; max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <h2 id="modalPatientName">Patient Visit History</h2>
            <span class="close-modal" onclick="closeVisitHistoryModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div id="historyLoader" style="text-align:center; padding:30px 20px;">
                <p>Loading visit history...</p>
            </div>
            <div id="historyContent" style="display:none;">
                <!-- Summary Section -->
                <div>
                    <h3>Visit Summary</h3>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:15px;">
                        <div style="background:#fff9fb; padding:15px; border-radius:6px; border-left:4px solid #e63b6f;">
                            <strong>Total Visits:</strong> 
                            <span id="visitCount" style="font-size:24px; font-weight:700; color:#e63b6f; display:block; margin-top:8px;">0</span>
                        </div>
                        <div style="background:#fff9fb; padding:15px; border-radius:6px; border-left:4px solid #16a34a;">
                            <strong>Unique Scans/Tests:</strong>
                            <span id="uniqueScanCount" style="font-size:24px; font-weight:700; color:#16a34a; display:block; margin-top:8px;">0</span>
                        </div>
                    </div>
                </div>

                <!-- Scans Index Section -->
                <div style="margin-top:25px;">
                    <h3>All Scans/Tests Performed</h3>
                    <div id="scansIndex"></div>
                </div>

                <!-- Detailed Visit History Section -->
                <div style="margin-top:25px;">
                    <h3>Visit Details</h3>
                    <div id="visitsDetail"></div>
                </div>
            </div>
            <div id="historyError" style="display:none; color:#dc2626; padding:15px; background:#fee2e2; border-radius:6px; border-left:4px solid #dc2626; font-weight:500; font-size:14px;"></div>
        </div>
    </div>
</div>

<style>
.modal {
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.4);
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-content {
    background: #fffbfd;
    margin: 5% auto;
    border: 1px solid #f9a8d4;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from { 
        transform: translateY(30px);
        opacity: 0;
    }
    to { 
        transform: translateY(0);
        opacity: 1;
    }
}

.modal-header {
    padding: 20px;
    background: #fffafe;
    border-bottom: 1px solid #f9a8d4;
    border-radius: 8px 8px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    margin: 0;
    color: #7a0f2e;
    font-size: 22px;
    font-weight: 700;
}

.close-modal {
    font-size: 28px;
    font-weight: bold;
    color: #e63b6f;
    cursor: pointer;
    padding: 0;
    line-height: 1;
    transition: color 0.2s ease;
}

.close-modal:hover {
    color: #be123c;
}

.modal-body {
    padding: 25px;
    background: #fffbfd;
}

/* Summary Section */
.modal-body > div:first-child {
    background: #fff5f9;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 25px;
    border: 1px solid #f9a8d4;
}

.modal-body > div:first-child h3 {
    margin: 0 0 15px 0;
    font-size: 16px;
    font-weight: 700;
    color: #7a0f2e;
}

/* Summary Cards Grid */
.modal-body > div:first-child > div:last-child {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
}

.modal-body > div:first-child div[style*="background:white"] {
    background: #fff9fb !important;
    padding: 15px !important;
    border-radius: 6px !important;
    border-left: 4px solid #e63b6f !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
    transition: all 0.2s ease;
}

.modal-body > div:first-child div[style*="background:white"]:hover {
    box-shadow: 0 2px 6px rgba(0,0,0,0.08) !important;
}

.modal-body > div:first-child strong {
    display: block;
    color: #6b7280;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 8px;
    font-weight: 600;
}

.modal-body > div:first-child span {
    font-size: 28px;
    font-weight: 700;
    color: #e63b6f;
}

.modal-body > div:first-child div[style*="background:white"]:nth-child(2) span {
    color: #16a34a;
}

/* Scans Section */
.modal-body > div:nth-child(2) {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 25px;
    border: 1px solid #f9a8d4;
}

.modal-body > div:nth-child(2) h3 {
    margin: 0 0 15px 0;
    font-size: 16px;
    font-weight: 700;
    color: #7a0f2e;
}

.scan-badge {
    display: inline-block;
    background: #e63b6f;
    color: white;
    padding: 6px 12px;
    margin: 5px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    box-shadow: 0 1px 3px rgba(230, 59, 111, 0.2);
    transition: all 0.2s ease;
}

.scan-badge:hover {
    background: #be123c;
    box-shadow: 0 2px 6px rgba(230, 59, 111, 0.3);
}

/* Visit Details */
.modal-body > div:nth-child(3) h3 {
    margin: 0 0 15px 0;
    font-size: 16px;
    font-weight: 700;
    color: #7a0f2e;
}

.visit-card {
    background: #fff9fb;
    border: 1px solid #fbcfe8;
    border-radius: 6px;
    padding: 15px;
    margin-bottom: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: all 0.2s ease;
    border-left: 4px solid #e63b6f;
}

.visit-card:hover {
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}

.visit-card h4 {
    margin: 0 0 12px 0;
    color: #e63b6f;
    font-size: 15px;
    font-weight: 600;
}

.visit-tests {
    background: #fdf5f8;
    padding: 12px;
    border-radius: 6px;
    margin-top: 10px;
}

.test-item {
    padding: 10px;
    margin: 6px 0;
    background: #fff9fb;
    border-left: 3px solid #16a34a;
    border-radius: 4px;
    font-size: 14px;
}

.test-main {
    font-weight: 600;
    color: #1f2937;
}

.test-status {
    font-size: 12px;
    margin-top: 6px;
    color: #6b7280;
}

/* Empty state */
.modal-body p[style*="color"] {
    text-align: center;
    padding: 20px;
    color: #9ca3af !important;
    font-size: 14px;
}

/* Loader */
#historyLoader {
    text-align: center;
    padding: 30px 20px;
}

#historyLoader p {
    color: #6b7280;
    font-size: 15px;
    font-weight: 500;
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .modal-content {
        width: 95% !important;
        margin: 20% auto !important;
    }
    
    .modal-header h2 {
        font-size: 18px;
    }
    
    .modal-body {
        padding: 20px;
    }
}
</style>

<script>
function viewPatientHistory(patientId, patientName, patientUniqueId) {
    const modal = document.getElementById('visitHistoryModal');
    const modalName = document.getElementById('modalPatientName');
    const loader = document.getElementById('historyLoader');
    const content = document.getElementById('historyContent');
    const error = document.getElementById('historyError');
    
    modal.style.display = 'block';
    modalName.textContent = patientName + ' - Visit History';
    loader.style.display = 'block';
    content.style.display = 'none';
    error.style.display = 'none';
    
    const params = new URLSearchParams();
    if (patientUniqueId) {
        params.set('patient_unique_id', patientUniqueId);
    } else {
        params.set('patient_id', patientId);
    }

    fetch(`ajax_patient_history.php?${params.toString()}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to fetch patient history');
            }
            return response.json();
        })
        .then(data => {
            if (!data.success) {
                throw new Error(data.message || 'Failed to load history');
            }
            
            loader.style.display = 'none';
            displayHistoryData(data);
            content.style.display = 'block';
        })
        .catch(err => {
            loader.style.display = 'none';
            error.textContent = 'Error: ' + (err.message || 'Unknown error occurred');
            error.style.display = 'block';
        });
}

function displayHistoryData(data) {
    // Update summary
    document.getElementById('visitCount').textContent = data.visit_count;
    document.getElementById('uniqueScanCount').textContent = data.total_unique_scans;
    
    // Display all scans index
    const scansIndex = document.getElementById('scansIndex');
    if (data.all_scans.length > 0) {
        scansIndex.innerHTML = data.all_scans.map(scan => {
            const scanName = scan.sub_test_name 
                ? scan.main_test_name + ' - ' + scan.sub_test_name 
                : scan.main_test_name;
            return '<span class="scan-badge">' + htmlEscape(scanName) + '</span>';
        }).join('');
    } else {
        scansIndex.innerHTML = '<p style="color:#9ca3af; margin:0;">No scans performed yet.</p>';
    }
    
    // Display detailed visits
    const visitsDetail = document.getElementById('visitsDetail');
    if (data.visits.length > 0) {
        visitsDetail.innerHTML = data.visits.map((visit, idx) => {
            const visitDate = new Date(visit.visit_date);
            const dateStr = visitDate.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }) + ' at ' + visitDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            
            const testsHtml = visit.tests.map(test => {
                const testName = test.sub_test_name 
                    ? test.main_test_name + ' - ' + test.sub_test_name 
                    : test.main_test_name;
                const statusColor = test.report_status === 'Completed' ? '#16a34a' : '#f59e0b';
                
                return '<div class="test-item">' +
                    '<div class="test-main">' + htmlEscape(testName) + '</div>' +
                    '<div class="test-status">Status: <span style="color:' + statusColor + '; font-weight:600;">' + test.report_status + '</span></div>' +
                    '</div>';
            }).join('');
            
            return '<div class="visit-card">' +
                '<h4>Visit #' + (data.visits.length - idx) + ' — ' + dateStr + '</h4>' +
                '<div class="visit-tests">' + testsHtml + '</div>' +
                '</div>';
        }).join('');
    } else {
        visitsDetail.innerHTML = '<p style="color:#9ca3af; text-align:center; padding:20px; margin:0;">No visits found.</p>';
    }
}

function closeVisitHistoryModal() {
    const modal = document.getElementById('visitHistoryModal');
    modal.style.display = 'none';
}

function htmlEscape(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

function normalizePatientIdInput(value) {
    const raw = (value || '').trim().toUpperCase();
    if (!raw) return '';
    return raw.startsWith('DC') ? raw : ('DC' + raw);
}

function isValidPatientIdFormat(value) {
    return /^DC\d{8}$/.test(value || '');
}

// Add event listeners to all history buttons
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.view-patient-history').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const patientId = this.getAttribute('data-patient-id');
            const patientUniqueId = this.getAttribute('data-patient-unique-id');
            const patientName = this.getAttribute('data-patient-name');
            viewPatientHistory(patientId, patientName, patientUniqueId);
        });
    });

    const quickHistoryLookupForm = document.getElementById('quickHistoryLookupForm');
    if (quickHistoryLookupForm) {
        quickHistoryLookupForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const quickIdInput = document.getElementById('quick_history_patient_id');
            const rawValue = (quickIdInput && quickIdInput.value ? quickIdInput.value : '').trim();

            if (!rawValue) {
                alert('Please enter a patient ID.');
                return;
            }

            const normalizedId = normalizePatientIdInput(rawValue);
            if (!isValidPatientIdFormat(normalizedId)) {
                alert('Patient ID must be in DCYYYYNNNN format.');
                return;
            }

            if (quickIdInput) {
                quickIdInput.value = normalizedId;
            }
            viewPatientHistory('', normalizedId, normalizedId);
        });
    }
    
    // Close modal when clicking outside of it
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('visitHistoryModal');
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
