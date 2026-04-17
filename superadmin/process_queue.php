<?php
// superadmin/process_queue.php
header('Content-Type: application/json');

$required_role = 'superadmin';
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

$notification_queue_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'notification_queue', 'nq') : '`notification_queue` nq';
$patients_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'patients', 'p') : '`patients` p';
$referral_doctors_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'referral_doctors', 'rd') : '`referral_doctors` rd';
$users_source = function_exists('table_scale_get_read_source') ? table_scale_get_read_source($conn, 'users', 'u') : '`users` u';

// --- PHPMailer Setup ---
// Note: You must install PHPMailer via Composer: composer require phpmailer/phpmailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if PHPMailer is available
$has_phpmailer = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        $has_phpmailer = true;
    }
}

if (!$has_phpmailer && file_exists(__DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php')) {
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
    $has_phpmailer = true;
}

// --- Configuration ---
// Load settings from file if available, otherwise fallback to env vars or defaults
$configFile = __DIR__ . '/../includes/mail_config.php';
$loaded = file_exists($configFile) ? require $configFile : [];
$fileConfig = is_array($loaded) ? $loaded : [];

$smtp_config = [
    'host' => $fileConfig['host'] ?? (getenv('SMTP_HOST') ?: ''),
    'username' => $fileConfig['username'] ?? (getenv('SMTP_USERNAME') ?: ''),
    'password' => $fileConfig['password'] ?? (getenv('SMTP_PASSWORD') ?: ''),
    'port' => isset($fileConfig['port']) ? (int)$fileConfig['port'] : (getenv('SMTP_PORT') ? (int)getenv('SMTP_PORT') : 587),
    'encryption' => $fileConfig['encryption'] ?? (getenv('SMTP_ENCRYPTION') ?: 'tls'),
    'from_email' => $fileConfig['from_email'] ?? (getenv('SMTP_FROM_EMAIL') ?: 'admin@example.com'),
    'from_name' => $fileConfig['from_name'] ?? (getenv('SMTP_FROM_NAME') ?: 'Divya Imaging Center')
];

// Determine if we should simulate
// Simulation is active if the password or host is effectively empty (or 'user@example.com' placeholder)
$missing_smtp = empty($smtp_config['host']) || empty($smtp_config['password']) || $smtp_config['username'] === 'user@example.com';
$simulation_mode = $missing_smtp; 

// --- Helper Functions ---

function sendEmail($to, $name, $subject, $body, $config, $is_simulation) {
    if ($is_simulation) {
        // Simulate success and log
        $log_entry = "[" . date('Y-m-d H:i:s') . "] [SIMULATED EMAIL] To: $to | Subject: $subject\n";
        file_put_contents(__DIR__ . '/../uploads/mail.log', $log_entry, FILE_APPEND);
        return ['success' => true, 'note' => 'Simulated'];
    }

    global $has_phpmailer;
    if (!$has_phpmailer) {
        return ['success' => false, 'error' => 'PHPMailer not installed'];
    }

    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];
        $mail->SMTPSecure = $config['encryption'];
        $mail->Port       = $config['port'];

        // Recipients
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($to, $name);

        // Content
        $mail->isHTML(false); 
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $mail->ErrorInfo];
    }
}

function sendWhatsApp($phone, $message) {
    // Placeholder for WhatsApp API integration (e.g., Twilio, Meta API)
    if (empty($phone)) return ['success' => false, 'error' => 'No phone number'];
    
    // Simulate logging
    $log_entry = "[" . date('Y-m-d H:i:s') . "] [SIMULATED WHATSAPP] To: $phone | Body: $message\n";
    file_put_contents(__DIR__ . '/../uploads/whatsapp.log', $log_entry, FILE_APPEND);
    
    return ['success' => true, 'note' => 'Simulated WhatsApp send'];
}

// --- Main Processing Logic ---

$response = ['success' => false, 'message' => '', 'processed_count' => 0];

// Remove the hard exit for missing SMTP
/* 
if (!empty($smtp_missing)) { ... } 
*/

try {
    // Fetch queued items
    $result = $conn->query("SELECT nq.* FROM {$notification_queue_source} WHERE nq.status = 'Queued' LIMIT 5"); 
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => true, 'message' => 'Queue is empty']);
        exit;
    }

    $queue_items = [];
    while ($row = $result->fetch_assoc()) {
        $queue_items[] = $row;
    }
    $result->close();

    $processed_count = 0;

    foreach ($queue_items as $row) {
        $channels = explode(', ', $row['channels']);
        $send_email = in_array('email', $channels);
        $send_whatsapp = in_array('whatsapp', $channels);
        
        $recipients = [];

        // Determine Recipients
        if ($row['recipient_group'] === 'all_patients') {
            $res = $conn->query("SELECT p.name, '' as email, p.mobile_number as phone FROM {$patients_source} WHERE p.mobile_number IS NOT NULL");
            if($res) while($r = $res->fetch_assoc()) $recipients[] = $r;
        } elseif ($row['recipient_group'] === 'all_doctors') {
            $res = $conn->query("SELECT rd.doctor_name as name, rd.email, rd.phone_number as phone FROM {$referral_doctors_source}");
            if($res) while($r = $res->fetch_assoc()) $recipients[] = $r;
        } elseif ($row['recipient_group'] === 'all_employees') {
            $res = $conn->query("SELECT u.username as name, '' as email, '' as phone FROM {$users_source} WHERE u.role != 'superadmin'");
            if($res) while($r = $res->fetch_assoc()) $recipients[] = $r;
        } elseif (in_array($row['recipient_group'], ['single_doctor', 'single_employee', 'custom_single'])) {
            $data = json_decode($row['recipient_data'], true);
            if ($data) {
                $recipients[] = [
                    'name' => $data['name'] ?? 'User',
                    'email' => $data['email'] ?? '',
                    'phone' => $data['phone'] ?? ''
                ];
            }
        }

        // Send Messages
        $success_count = 0;
        foreach ($recipients as $recipient) {
            $msg_body = str_replace('{name}', $recipient['name'], $row['message']);
            
            // Send Email
            if ($send_email && !empty($recipient['email'])) {
                $email_result = sendEmail($recipient['email'], $recipient['name'], $row['subject'], $msg_body, $smtp_config, $simulation_mode);
                if (!$email_result['success']) {
                    $response['message'] .= " [Mail Err: " . $email_result['error'] . "] ";
                }
            }

            // Send WhatsApp
            if ($send_whatsapp && !empty($recipient['phone'])) {
                 sendWhatsApp($recipient['phone'], $msg_body);
            }
            
            $success_count++;
        }

        // Update Queue Status
        $status = ($success_count > 0) ? 'Sent' : 'Failed';
        $update = $conn->prepare("UPDATE notification_queue SET status = ?, processed_at = NOW() WHERE id = ?");
        if ($update) {
            $update->bind_param("si", $status, $row['id']);
            $update->execute();
            $update->close();
        } else {
             $response['message'] .= " DB Error: " . $conn->error;
        }
        
        $processed_count++;
    }

    $response['success'] = true;
    $response['message'] = "Processed $processed_count queue items.";
    if ($simulation_mode) {
        $response['message'] .= " [SIMULATION MODE: No real emails sent]";
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

$conn->close();
echo json_encode($response);
?>