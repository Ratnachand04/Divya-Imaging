<?php
// We don't enforce login here so anyone can see the error page
$page_title = "Page Not Found";

// --- Log 404 Error for Developer ---
// This ensures that every time a user sees this page, it appears in the Ghost/error_logs.php
try {
    require_once __DIR__ . '/includes/db_connect.php';
    
    if (isset($conn) && !$conn->connect_error) {
        $req_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'Unknown';
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        
        // Construct message
        $err_msg = "404 Not Found";
        if ($referer) {
            // Shorten referer to avoid clutter
             $short_ref = str_replace(['http://', 'https://', $_SERVER['HTTP_HOST'] . '/'], '', $referer);
             $err_msg .= " (from: $short_ref)";
        }

        // 404 is technically a client error, but we log it as level 404 for our custom badge
        $stmt = $conn->prepare("INSERT INTO error_logs (error_level, error_message, file_path, line_number, request_url) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $err_lvl = 404; // Custom level
            $err_file = "client"; 
            $err_line = 0;
            $stmt->bind_param("issis", $err_lvl, $err_msg, $err_file, $err_line, $req_url);
            $stmt->execute();
            $stmt->close();
        }
    }
} catch (Exception $e) {
    // Silently fail logging if DB is down, to at least show the 404 page
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found</title>
    <!-- Relative path assuming 404.php is at root -->
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
            color: #dc3545;
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
        .btn-home {
            background: #0d6efd;
            color: white;
            padding: 12px 30px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
            display: inline-block;
        }
        .btn-home:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(13, 110, 253, 0.3);
            background: #0b5ed7;
        }
        .icon-box {
            font-size: 50px;
            color: #dc3545;
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
            <i class="fas fa-ghost"></i> 
        </div>
        <h1 class="error-code">404</h1>
        <h2 class="error-message">Oops! Page not found</h2>
        <p class="error-desc">
            The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.
        </p>
        <a href="index.php" class="btn-home">
            <i class="fas fa-home"></i> Go to Homepage
        </a>
    </div>

</body>
</html>