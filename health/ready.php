<?php
header('Content-Type: text/plain; charset=utf-8');

$readyFile = __DIR__ . '/ready.txt';
if (!is_file($readyFile)) {
    http_response_code(503);
    echo "NOT READY\n";
    exit;
}

http_response_code(200);
echo "OK\n";
