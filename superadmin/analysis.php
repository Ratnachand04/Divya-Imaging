<?php
$required_role = "superadmin";
require_once '../includes/auth_check.php';

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'analysis_daily.php';
if ($query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target);
exit;
