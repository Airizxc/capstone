<?php
/**
 * Secure Co-Curricular announcement image viewer.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';
require_once ROOT_PATH . '/includes/authentication.php';

requireAuth();
$announcementId = (int) ($_GET['announcement_id'] ?? 0);
$clubId = (int) ($_GET['club_id'] ?? 0);
if ($announcementId <= 0 || $clubId <= 0) {
    http_response_code(400);
    exit('Invalid request.');
}

$announcement = cocurricularGetAnnouncementById($announcementId);
if (!$announcement || (int) $announcement['club_id'] !== $clubId) {
    http_response_code(404);
    exit('Image not found.');
}

$isOsa = getCurrentUserRoleKey() === 'osa' && userCanAccessModule('cocurricular');
$isApprovedMember = !$isOsa && getCurrentUserRoleKey() === 'student'
    && cocurricularFetchApprovedMembershipForClub($clubId, getCurrentUserId());
if (!$isOsa && !$isApprovedMember) {
    http_response_code(403);
    exit('Unauthorized.');
}

$storedPath = trim((string) ($announcement['attachment_path'] ?? ''));
if ($storedPath === '' || preg_match('~^https?://~i', $storedPath)) {
    http_response_code(404);
    exit('Image not found.');
}

$normalizedPath = ltrim(str_replace('\\', '/', $storedPath), '/');
if (!str_starts_with($normalizedPath, 'cocurricular_announcements/')) {
    http_response_code(404);
    exit('Image not found.');
}

$filePath = realpath(ROOT_PATH . '/storage/uploads/' . $normalizedPath);
$uploadsRoot = realpath(ROOT_PATH . '/storage/uploads');
if ($filePath === false || $uploadsRoot === false || !is_file($filePath)
    || strncmp($filePath, $uploadsRoot . DIRECTORY_SEPARATOR, strlen($uploadsRoot . DIRECTORY_SEPARATOR)) !== 0) {
    http_response_code(404);
    exit('Image not found.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(404);
    exit('Image not found.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;
