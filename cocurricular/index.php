<?php
/**
 * SMS 2 - Co-Curricular - Overview
 */
$pageTitle    = 'Co-Curricular';
$activeModule = 'cocurricular';
$activePage   = '';
$breadcrumbs  = [
    ['label' => 'Co-Curricular', 'url' => null],
];

require_once __DIR__ . '/../../config/config.php';
header('Location: ' . BASE_URL . '/modules/cocurricular/pages/club-directory.php');
exit;