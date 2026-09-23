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
require_once __DIR__ . '/../../includes/authentication.php';

$roleKey = getCurrentUserRoleKey();
if (in_array($roleKey, ['faculty', 'adviser', 'panel', 'grammarian', 'research_director', 'hr', 'department_chair'], true)) {
    header('Location: ' . BASE_URL . '/modules/cocurricular/pages/faculty-adviser.php');
    exit;
}

header('Location: ' . BASE_URL . '/modules/cocurricular/pages/club-directory.php');
exit;