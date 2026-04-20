<?php
$page_title = "Scans";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';

$sa_active_page = 'scans.php';

$defaultStartDate = date('Y-m-01');
$defaultEndDate = date('Y-m-d');

$scanStartDate = $_GET['start_date'] ?? $defaultStartDate;
$scanEndDate = $_GET['end_date'] ?? $defaultEndDate;

$scanStartObj = DateTime::createFromFormat('Y-m-d', $scanStartDate);
if (!$scanStartObj || $scanStartObj->format('Y-m-d') !== $scanStartDate) {
	$scanStartObj = new DateTime($defaultStartDate);
}

$scanEndObj = DateTime::createFromFormat('Y-m-d', $scanEndDate);
if (!$scanEndObj || $scanEndObj->format('Y-m-d') !== $scanEndDate) {
	$scanEndObj = new DateTime($defaultEndDate);
}

if ($scanEndObj < $scanStartObj) {
	$scanEndObj = clone $scanStartObj;
}

$scanStartDate = $scanStartObj->format('Y-m-d');
$scanEndDate = $scanEndObj->format('Y-m-d');
?>

<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_scans.css?v=<?php echo time(); ?>">

<?php require_once __DIR__ . '/components/shell_start.php'; ?>
<?php require_once __DIR__ . '/components/scans_component.php'; ?>
<?php require_once __DIR__ . '/components/shell_end.php'; ?>

<script src="<?php echo $base_url; ?>/assets/js/superadmin_scans_components.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $base_url; ?>/assets/js/superadmin_scans_page.js?v=<?php echo time(); ?>"></script>

<?php require_once '../includes/footer.php'; ?>
