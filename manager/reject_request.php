<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['action'] = 'reject';
    if (!isset($_POST['return_to'])) {
        $_POST['return_to'] = 'details';
    }
}

require __DIR__ . '/update_request_status.php';