<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';
require_once __DIR__ . '/../../../includes/authentication.php';

requireAuth();

$eventId = (int) ($_GET['event_id'] ?? 0);
if ($eventId <= 0) {
    http_response_code(400);
    exit('Invalid request.');
}

$event = cocurricularGetEventById($eventId);
if (!$event) {
    http_response_code(404);
    exit('Event image not found.');
}

$clubId = (int) ($_GET['club_id'] ?? (int) ($event['club_id'] ?? 0));
if ((int) $event['club_id'] !== $clubId) {
    http_response_code(404);
    exit('Event image not found.');
}

$isOsa = getCurrentUserRoleKey() === 'osa' && userCanAccessModule('cocurricular');
$isApprovedStudent = getCurrentUserRoleKey() === 'student'
    && getCurrentUserId() !== null
    && cocurricularHasApprovedMembershipForClub($clubId, (int) getCurrentUserId());

if (!$isOsa && !$isApprovedStudent) {
    http_response_code(403);
    exit('Unauthorized.');
}

if ($isApprovedStudent && (string) $event['status'] !== 'Published') {
    http_response_code(403);
    exit('Unauthorized.');
}

$storedPath = trim((string) ($event['image_path'] ?? ''));
if ($storedPath === '' || preg_match('~^https?://~i', $storedPath)) {
    http_response_code(404);
    exit('Image not found.');
}

$normalizedPath = ltrim(str_replace('\\', '/', $storedPath), '/');
if (!str_starts_with($normalizedPath, 'cocurricular_events/')) {
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
