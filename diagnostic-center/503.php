<?php
// 503 Service Unavailable Page
$page_title = "Service Unavailable";

// --- Log 503 Error for Developer ---
// This ensures that major server issues appear in the Ghost/error_logs.php
try {
    require_once __DIR__ . '/includes/db_connect.php';
    
    if (isset($conn) && !$conn->connect_error) {
        $req_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'Unknown';
        
        // Construct message
        $err_msg = "503 Service Unavailable";

        // Log it
        $stmt = $conn->prepare("INSERT INTO error_logs (error_level, error_message, file_path, line_number, request_url) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $err_lvl = 503; // Custom level for Service Unavailable
            $err_file = "server"; 
            $err_line = 0;
            $stmt->bind_param("issis", $err_lvl, $err_msg, $err_file, $err_line, $req_url);
            $stmt->execute();
            $stmt->close();
        }
    }
} catch (Exception $e) {
    // Silently fail logging if DB is down
}
http_response_code(503); // Send actual 503 header
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>503 - Service Unavailable</title>
    <!-- Using absolute paths so it works from any subdirectory -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .error-container {
            text-align: center;
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            max-width: 500px;
            width: 90%;
            animation: fadeIn 0.5s ease-out;
        }
        .error-code {
            font-size: 100px;
            font-weight: 800;
            color: #fd7e14; /* Orange/Warning color for 503 */
            margin: 0;
            line-height: 1;
            text-shadow: 2px 2px 0px #eee;
        }
        .error-message {
            font-size: 24px;
            color: #343a40;
            margin: 20px 0;
            font-weight: 600;
        }
        .error-desc {
            color: #6c757d;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .btn-retry {
            background: #fd7e14;
            color: white;
            padding: 12px 30px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
            display: inline-block;
            cursor: pointer;
            border: none;
        }
        .btn-retry:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(253, 126, 20, 0.3);
            background: #e3690b;
        }
        .icon-box {
            font-size: 50px;
            color: #fd7e14;
            margin-bottom: 20px;
            opacity: 0.2;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

    <div class="error-container">
        <div class="icon-box">
            <i class="fas fa-tools"></i> 
        </div>
        <h1 class="error-code">503</h1>
        <h2 class="error-message">Service Temporarily Unavailable</h2>
        <p class="error-desc">
            The server is currently unable to handle the request due to a temporary overload or scheduled maintenance. Please try again later.
        </p>
        <button onclick="window.location.reload()" class="btn-retry">
            <i class="fas fa-redo"></i> Retry
        </button>
    </div>

</body>
</html>
