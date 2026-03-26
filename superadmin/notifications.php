<?php
$page_title = "Notifications";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

// Ensure the notification_queue table has necessary columns
$conn->query("CREATE TABLE IF NOT EXISTS notification_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_group VARCHAR(50),
    recipient_data JSON,
    recipient_count INT,
    subject VARCHAR(255),
    message TEXT,
    channels VARCHAR(100),
    status VARCHAR(20) DEFAULT 'Queued',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL
)");

// Fetch Lists for Dropdowns
$doctors = [];
$res = $conn->query("SELECT id, doctor_name, email, phone_number as phone FROM referral_doctors ORDER BY doctor_name");
if ($res) {
    while($row = $res->fetch_assoc()) { $doctors[] = $row; }
}

$employees = [];
// Note: Using 'users' table for employees. Contact info is currently unavailable in this table.
$res = $conn->query("SELECT id, username as name, '' as email, '' as phone FROM users WHERE role != 'superadmin' ORDER BY username");
if ($res) {
    while($row = $res->fetch_assoc()) { $employees[] = $row; }
}

// Templates
$templates = [
    'custom' => ['subject' => '', 'message' => ''],
    'birthday' => [
        'subject' => 'Happy Birthday from Divya Imaging Center!', 
        'message' => "Dear {name},\n\nWishing you a very Happy Birthday! May this year bring you good health and happiness.\n\nBest Regards,\nDivya Imaging Center"
    ],
    'report_ready' => [
        'subject' => 'Your Medical Report is Ready', 
        'message' => "Dear {name},\n\nYour medical report is ready for collection. You can download it from our portal or collect it from the reception.\n\nRegards,\nDivya Imaging Center"
    ],
    'payment_reminder' => [
        'subject' => 'Payment Reminder', 
        'message' => "Dear {name},\n\nThis is a gentle reminder regarding your outstanding balance of {amount}. Please clear the dues at your earliest convenience.\n\nRegards,\nAccounts Team"
    ],
    'salary_slip' => [
        'subject' => 'Salary Slip Generated', 
        'message' => "Dear {name},\n\nYour salary slip for this month has been generated. Please contact HR for any discrepancies.\n\nRegards,\nManagement"
    ]
];

$message_status = '';

// Handle Delete Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $id = (int)$_POST['delete_item'];
    $conn->query("DELETE FROM notification_queue WHERE id = $id");
    $message_status = "<div class='success-banner'>Notification deleted.</div>";
}

// Handle Clear History
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_history'])) {
    $conn->query("DELETE FROM notification_queue WHERE status IN ('Sent', 'Failed')");
    $message_status = "<div class='success-banner'>History cleared (Sent/Failed items removed).</div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recipient_type'])) {
    $type = $_POST['recipient_type'];
    $subject = $_POST['subject'] ?? 'Notification';
    $message_body = $_POST['message'];
    $channels = isset($_POST['channel']) ? implode(', ', $_POST['channel']) : 'None';
    
    $recipient_group = $type;
    $recipient_data = [];
    $count = 0;

    if ($type === 'group_patients') {
        $res = $conn->query("SELECT COUNT(*) as cnt FROM patients");
        $count = $res->fetch_assoc()['cnt'];
        $recipient_group = 'all_patients';
    } elseif ($type === 'group_doctors') {
        $res = $conn->query("SELECT COUNT(*) as cnt FROM referral_doctors");
        $count = $res->fetch_assoc()['cnt'];
        $recipient_group = 'all_doctors';
    } elseif ($type === 'individual_doctor') {
        $doc_id = (int)$_POST['doctor_id'];
        foreach($doctors as $d) {
            if ($d['id'] == $doc_id) {
                $recipient_data = ['id' => $d['id'], 'name' => $d['doctor_name'], 'email' => $d['email'], 'phone' => $d['phone']];
                break;
            }
        }
        $count = 1;
        $recipient_group = 'single_doctor';
    } elseif ($type === 'individual_employee') {
        $emp_id = (int)$_POST['employee_id'];
        foreach($employees as $e) {
            if ($e['id'] == $emp_id) {
                $recipient_data = ['id' => $e['id'], 'name' => $e['name'], 'email' => $e['email'], 'phone' => $e['phone']];
                break;
            }
        }
        $count = 1;
        $recipient_group = 'single_employee';
    } elseif ($type === 'custom') {
        $recipient_data = [
            'name' => $_POST['custom_name'],
            'email' => $_POST['custom_email'],
            'phone' => $_POST['custom_phone']
        ];
        $count = 1;
        $recipient_group = 'custom_single';
    }

    // Insert into queue
    $stmt = $conn->prepare("INSERT INTO notification_queue (recipient_group, recipient_data, recipient_count, subject, message, channels, status) VALUES (?, ?, ?, ?, ?, ?, 'Queued')");
    $json_data = json_encode($recipient_data);
    $stmt->bind_param("ssisss", $recipient_group, $json_data, $count, $subject, $message_body, $channels);
    
    if ($stmt->execute()) {
        $message_status = "<div class='success-banner'>
            <strong>Success!</strong> Notification queued for <strong>$count</strong> recipient(s).<br>
            Type: " . ucwords(str_replace('_', ' ', $recipient_group)) . "
        </div>";
    } else {
        $message_status = "<div class='error-banner'>Error queuing notification: " . $conn->error . "</div>";
    }
    $stmt->close();
}

// Fetch Notification History
$history_sql = "SELECT * FROM notification_queue ORDER BY created_at DESC LIMIT 20";
$history_result = $conn->query($history_sql);
?>

<div class="main-content page-container">
    <div class="dashboard-header">
        <h1>Send Notifications</h1>
        <a href="dashboard.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <?php echo $message_status; ?>

    <div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 30px;">
        <!-- Sending Form -->
        <div class="card" style="background: #fff; padding: 25px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); height: fit-content;">
            <h2 style="margin-top: 0; font-size: 1.2rem; color: #2d3748; border-bottom: 2px solid #edf2f7; padding-bottom: 15px; margin-bottom: 20px;">Compose Message</h2>
            
            <form method="POST" action="notifications.php" id="notifyForm">
                
                <!-- Recipient Selection -->
                <div class="form-group form-section">
                    <label>Recipient Type</label>
                    <select name="recipient_type" id="recipientType" required class="form-control" onchange="toggleRecipientFields()">
                        <option value="group_patients">All Patients (Bulk)</option>
                        <option value="group_doctors">All Doctors (Bulk)</option>
                        <option value="individual_doctor">Specific Doctor</option>
                        <option value="individual_employee">Specific Employee</option>
                        <option value="custom">Custom Email / Phone</option>
                    </select>
                </div>

                <!-- Dynamic Fields -->
                <div id="field_doctor" class="form-group form-section hidden">
                    <label>Select Doctor</label>
                    <select name="doctor_id" class="form-control">
                        <?php foreach($doctors as $d): ?>
                            <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['doctor_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="field_employee" class="form-group form-section hidden">
                    <label>Select Employee</label>
                    <select name="employee_id" class="form-control">
                        <?php foreach($employees as $e): ?>
                            <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="field_custom" class="hidden" style="background: #f7fafc; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="custom_name" class="form-control" placeholder="Recipient Name">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="custom_email" class="form-control" placeholder="user@example.com">
                    </div>
                    <div class="form-group">
                        <label>Phone (WhatsApp)</label>
                        <input type="text" name="custom_phone" class="form-control" placeholder="+91...">
                    </div>
                </div>

                <!-- Templates -->
                <div class="form-group form-section">
                    <label>Load Template</label>
                    <div style="display: flex; flex-wrap: wrap;">
                        <button type="button" class="btn-action template-btn" style="background: #6c757d;" onclick="loadTemplate('birthday')">Birthday</button>
                        <button type="button" class="btn-action template-btn" style="background: #6c757d;" onclick="loadTemplate('report_ready')">Report Ready</button>
                        <button type="button" class="btn-action template-btn" style="background: #6c757d;" onclick="loadTemplate('payment_reminder')">Payment Due</button>
                        <button type="button" class="btn-action template-btn" style="background: #6c757d;" onclick="loadTemplate('salary_slip')">Salary Slip</button>
                        <button type="button" class="btn-action template-btn" style="background: #e2e8f0; color: #333;" onclick="loadTemplate('custom')">Clear</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Channels</label>
                    <div style="display: flex; gap: 20px; margin-top: 5px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="channel[]" value="email" checked> 
                            <i class="fas fa-envelope" style="color: #4e73df;"></i> Email
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="channel[]" value="whatsapp" checked> 
                            <i class="fab fa-whatsapp" style="color: #25D366;"></i> WhatsApp
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Subject (Email)</label>
                    <input type="text" name="subject" id="msgSubject" placeholder="Important Update..." class="form-control">
                </div>

                <div class="form-group">
                    <label>Message Content</label>
                    <textarea name="message" id="msgBody" rows="6" required placeholder="Type your message here... Use {name} for dynamic name." class="form-control" style="resize: vertical;"></textarea>
                    <small style="color: #718096;">Available variables: {name}, {amount}</small>
                </div>

                <button type="submit" class="btn-action" style="width: 100%; justify-content: center;">
                    <i class="fas fa-paper-plane" style="margin-right: 8px;"></i> Queue Notification
                </button>
            </form>
        </div>

        <!-- History Table -->
        <div class="card" style="background: #fff; padding: 25px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #edf2f7; padding-bottom: 15px; margin-bottom: 20px;">
                <h2 style="margin: 0; font-size: 1.2rem; color: #2d3748;">Notification Queue</h2>
                <div style="display: flex; gap: 10px;">
                    <form method="POST" onsubmit="return confirm('Clear all Sent/Failed items?');" style="margin:0;">
                        <button type="submit" name="clear_history" value="1" class="btn-action" style="background: #e53e3e; font-size: 0.9rem; padding: 8px 15px;">
                            <i class="fas fa-trash-alt"></i> Clear History
                        </button>
                    </form>
                    <button id="btnProcessQueue" class="btn-action" style="background: #38a169; font-size: 0.9rem; padding: 8px 15px;">
                        <i class="fas fa-sync-alt"></i> Process Queue Now
                    </button>
                </div>
            </div>
            
            <div class="table-container" style="box-shadow: none; padding: 0;">
                <table id="notificationTable" class="display" style="width: 100%; font-size: 0.9rem;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Target</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($history_result && $history_result->num_rows > 0): ?>
                            <?php while($row = $history_result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600;"><?php echo date('d M', strtotime($row['created_at'])); ?></div>
                                        <div style="font-size: 0.8em; color: #718096;"><?php echo date('h:i A', strtotime($row['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600; color: #2d3748;"><?php echo ucwords(str_replace('_', ' ', $row['recipient_group'])); ?></span>
                                        <?php 
                                            $data = json_decode($row['recipient_data'], true);
                                            if(isset($data['name'])) echo " <span style='color:#718096;'>(" . htmlspecialchars($data['name']) . ")</span>";
                                        ?>
                                        <div style="font-size: 0.8em; color: #718096; margin-top: 2px;"><?php echo htmlspecialchars($row['subject']); ?></div>
                                    </td>
                                    <td>
                                        <?php 
                                            $statusClass = 'badge-warning';
                                            $statusStyle = 'background: #fff3e0; color: #ef6c00;';
                                            if($row['status'] == 'Sent') {
                                                $statusClass = 'badge-success';
                                                $statusStyle = 'background: #e8f5e9; color: #2e7d32;';
                                            } elseif($row['status'] == 'Failed') {
                                                $statusStyle = 'background: #fed7d7; color: #c53030;';
                                            }
                                        ?>
                                        <span class="badge <?php echo $statusClass; ?>" style="<?php echo $statusStyle; ?> padding: 4px 8px; border-radius: 4px; font-size: 0.8em;">
                                            <?php echo $row['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" onsubmit="return confirm('Delete this notification?');" style="display:inline;">
                                            <input type="hidden" name="delete_item" value="<?php echo $row['id']; ?>">
                                            <button type="submit" style="background:none; border:none; color:#e53e3e; cursor:pointer; padding: 5px;" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const templates = <?php echo json_encode($templates); ?>;

function toggleRecipientFields() {
    const type = document.getElementById('recipientType').value;
    document.getElementById('field_doctor').classList.add('hidden');
    document.getElementById('field_employee').classList.add('hidden');
    document.getElementById('field_custom').classList.add('hidden');

    if (type === 'individual_doctor') {
        document.getElementById('field_doctor').classList.remove('hidden');
    } else if (type === 'individual_employee') {
        document.getElementById('field_employee').classList.remove('hidden');
    } else if (type === 'custom') {
        document.getElementById('field_custom').classList.remove('hidden');
    }
}

function loadTemplate(key) {
    if (templates[key]) {
        document.getElementById('msgSubject').value = templates[key].subject;
        document.getElementById('msgBody').value = templates[key].message;
    }
}

document.getElementById('btnProcessQueue').addEventListener('click', function() {
    const btn = this;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;

    fetch('process_queue.php')
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while processing the queue.');
        })
        .finally(() => {
            btn.innerHTML = originalText;
            btn.disabled = false;
        });
});
</script>

<?php require_once '../includes/footer.php'; ?>
