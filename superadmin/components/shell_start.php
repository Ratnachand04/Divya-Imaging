<?php
if (!isset($base_url)) {
    $base_url = '';
}

if (!isset($sa_active_page)) {
    $sa_active_page = basename($_SERVER['PHP_SELF']);
}
?>

<div class="sa-shell">
    <?php require __DIR__ . '/sidebar_component.php'; ?>

    <div class="sa-content-wrap">
        <?php require __DIR__ . '/header_component.php'; ?>

        <main class="sa-main-content">
