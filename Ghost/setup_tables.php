<?php
// Ghost/setup_tables.php - Creates required utility tables
require_once __DIR__ . '/../includes/db_connect.php';

// 1. Create site_messages table
$sql = "CREATE TABLE IF NOT EXISTS site_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'warning', 'maintenance', 'success', 'danger') DEFAULT 'info',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo "Table 'site_messages' created or already exists.<br>";
} else {
    echo "Error creating 'site_messages': " . $conn->error . "<br>";
}

// 2. Create error_logs table
$sql = "CREATE TABLE IF NOT EXISTS error_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    error_level INT,
    error_message TEXT,
    file_path VARCHAR(255),
    line_number INT,
    request_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo "Table 'error_logs' created or already exists.<br>";
} else {
    echo "Error creating 'error_logs': " . $conn->error . "<br>";
}

// 3. Create developer_settings table
$sql = "CREATE TABLE IF NOT EXISTS developer_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo "Table 'developer_settings' created or already exists.<br>";
    
    // Insert default settings
    $defaults = [
        ['developer_mode', 'false'],
        ['public_ip', ''],
        ['local_ip', ''],
        ['last_ip_check', ''],
        ['ip_check_interval', '300'],
        ['hot_reload', 'false'],
        ['last_reload_time', ''],
        ['ip_last_error', ''],
        ['ip_change_log', '']
    ];
    
    foreach ($defaults as $d) {
        $stmt = $conn->prepare("INSERT IGNORE INTO developer_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->bind_param("ss", $d[0], $d[1]);
        $stmt->execute();
    }
    echo "Default developer settings inserted.<br>";
} else {
    echo "Error creating 'developer_settings': " . $conn->error . "<br>";
}

// 4. Create flexible app_settings table
$sql = "CREATE TABLE IF NOT EXISTS app_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_scope VARCHAR(50) NOT NULL DEFAULT 'global',
    scope_id INT NOT NULL DEFAULT 0,
    setting_key VARCHAR(120) NOT NULL,
    setting_value LONGTEXT DEFAULT NULL,
    value_type VARCHAR(20) NOT NULL DEFAULT 'string',
    category VARCHAR(80) DEFAULT NULL,
    metadata_json JSON DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_scope_key (setting_scope, scope_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

if ($conn->query($sql) === TRUE) {
    echo "Table 'app_settings' created or already exists.<br>";
} else {
    echo "Error creating 'app_settings': " . $conn->error . "<br>";
}

// 5. Ensure default platform admin user exists
$dev_username = 'platform';
$dev_pass = 'password123';
$hashed = password_hash($dev_pass, PASSWORD_DEFAULT);

$check = $conn->prepare("SELECT id FROM users WHERE username = ?");
$check->bind_param("s", $dev_username);
$check->execute();
$check->store_result();

if ($check->num_rows == 0) {
    $insert = $conn->prepare("INSERT INTO users (username, password, role, is_active) VALUES (?, ?, 'platform_admin', 1)");
    $insert->bind_param("ss", $dev_username, $hashed);
    if ($insert->execute()) {
        echo "User 'platform' created with password 'password123'.<br>";
    } else {
        echo "Error creating platform admin user: " . $conn->error . "<br>";
    }
} else {
    echo "User 'platform' already exists. Refreshing role and password.<br>";
    $update = $conn->prepare("UPDATE users SET role='platform_admin', password=?, is_active=1 WHERE username = ?");
    $update->bind_param("ss", $hashed, $dev_username);
    $update->execute();
}

$conn->close();
?>