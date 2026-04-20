<?php
$required_role = "superadmin";
require_once '../includes/auth_check.php';
header('Location: analysis.php');
exit;
