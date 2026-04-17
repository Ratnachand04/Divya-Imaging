<?php
// --- Database Configuration ---
// Default: Docker environment (set via docker-compose.yml / .env)
// Override: Set environment variables DB_HOST, DB_USER, DB_PASS, DB_NAME

$servername = getenv('DB_HOST') ?: 'db';
$username   = getenv('DB_USER') ?: 'root';
$password   = getenv('DB_PASS') ?: 'root_password';
$dbname     = getenv('DB_NAME') ?: 'diagnostic_center_db';

// --- Create Connection ---
$conn = new mysqli($servername, $username, $password, $dbname);

// --- Check Connection ---
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection failed. Please check server configuration.");
}

// Set character set to utf8mb4 for better Unicode support
$conn->set_charset("utf8mb4");

// Always keep package-feature schema aligned for existing databases.
if (!function_exists('ensure_package_management_schema')) {
    $functions_file = __DIR__ . '/functions.php';
    if (is_file($functions_file)) {
        require_once $functions_file;
    }
}

if (function_exists('ensure_package_management_schema')) {
    try {
        ensure_package_management_schema($conn);
    } catch (Throwable $e) {
        // Do not block normal app flow if migration guard fails; log for troubleshooting.
        error_log('Package schema sync failed: ' . $e->getMessage());
    }
}

if (function_exists('ensure_data_storage_base_structure')) {
    try {
        ensure_data_storage_base_structure();
    } catch (Throwable $e) {
        // Keep app requests running even if filesystem prep fails.
        error_log('Data storage setup failed: ' . $e->getMessage());
    }
}

// Keep horizontal table scaling metadata and shard views aligned.
if (function_exists('ensure_horizontal_table_scaling')) {
    try {
        ensure_horizontal_table_scaling($conn, 100000);
    } catch (Throwable $e) {
        // Avoid blocking user requests if shard maintenance fails.
        error_log('Horizontal scaling sync failed: ' . $e->getMessage());
    }
}

if (function_exists('ensure_table_mirror_sync')) {
    try {
        ensure_table_mirror_sync($conn);
    } catch (Throwable $e) {
        // Keep app alive even when mirror setup has an issue.
        error_log('Table mirror sync failed: ' . $e->getMessage());
    }
}

// ERROR HANDLER FOR DEVELOPER ROLE
if (!function_exists('custom_error_handler')) {
    function custom_error_handler($errno, $errstr, $errfile, $errline) {
        global $servername, $username, $password, $dbname;
        
        // Respect error suppression
        if (!(error_reporting() & $errno)) {
            return false;
        }

        try {
            // Create a dedicated connection for logging to avoid interfering with main connection state
            $log_conn = new mysqli($servername, $username, $password, $dbname);
            if ($log_conn->connect_error) return false; 

            $request_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'CLI';
            
            $stmt = $log_conn->prepare("INSERT INTO error_logs (error_level, error_message, file_path, line_number, request_url) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("issis", $errno, $errstr, $errfile, $errline, $request_url);
                $stmt->execute();
                $stmt->close();
            }
            $log_conn->close();
        } catch (Exception $e) {
            // Validating logging shouldn't break application
        }
        
        // Continue with normal error handling
        return false; 
    }
    set_error_handler("custom_error_handler");
}

