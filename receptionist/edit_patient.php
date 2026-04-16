<?php
$required_role = "manager";
require_once '../includes/auth_check.php';

header('Location: ../manager/edit_patient.php?' . http_build_query($_GET));
exit;
