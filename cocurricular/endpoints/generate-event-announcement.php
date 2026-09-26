<?php
/**
 * Co-Curricular Module — AI Event-to-Announcement Draft Generator Endpoint
 * Accepts an existing event_id, retrieves event information, and generates an announcement draft via GPT-4.1.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';
require_once __DIR__ . '/../includes/cocurricular-ai.php';

try {
    requireAuth();
    $currentUserId = (int) getCurrentUserId();
    $userRoleKey = getCurrentUserRoleKey();

    $isOsaAdmin = smsRoleAllowedForModule(['osa', 'superadmin'], 'cocurricular')
        || in_array($userRoleKey, ['osa', 'superadmin', 'sms_admin'], true);

    $isFacultyUser = in_array($userRoleKey, ['faculty', 'adviser', 'panel', 'grammarian', 'research_director', 'hr', 'department_chair'], true)
        || cocurricularIsValidFacultyUser($currentUserId);

    if (!$isOsaAdmin && !$isFacultyUser) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. You do not have permission to access the AI announcement generator.']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed.', 'message' => 'Method not allowed.']);
        exit;
    }

    // Parse JSON body or POST form data
    $rawInput = (string) file_get_contents('php://input');
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    $isJsonRequest = stripos($contentType, 'application/json') !== false
        || (!empty($rawInput) && (str_starts_with(trim($rawInput), '{') || str_starts_with(trim($rawInput), '[')));

    $parsedBody = [];
    if ($isJsonRequest && trim($rawInput) !== '') {
        $decoded = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid JSON request body.', 'message' => 'Invalid JSON request body.']);
            exit;
        }
        $parsedBody = $decoded;
    } else {
        $parsedBody = $_POST;
    }

    $headerCsrf = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_SERVER['HTTP_X_XSRF_TOKEN']
        ?? (function_exists('getallheaders') ? (getallheaders()['X-CSRF-Token'] ?? getallheaders()['x-csrf-token'] ?? null) : null);
    $csrfToken = (string) ($parsedBody['csrf_token'] ?? $_POST['csrf_token'] ?? $headerCsrf ?? '');

    if (!csrfVerify($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or missing CSRF token.', 'message' => 'Invalid or missing CSRF token.']);
        exit;
    }

    $eventId = (int) ($parsedBody['event_id'] ?? 0);
    if ($eventId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please select a valid event.', 'message' => 'Please select a valid event.']);
        exit;
    }

    $event = cocurricularGetEventById($eventId);
    if (!$event) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Event not found.', 'message' => 'Event not found.']);
        exit;
    }

    $clubId = (int) ($event['club_id'] ?? 0);
    if (!$isOsaAdmin && ($clubId <= 0 || !cocurricularIsFacultyAdviserOfClub($currentUserId, $clubId))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized. You are not authorized to access events for this club.', 'message' => 'Unauthorized. You are not authorized to access events for this club.']);
        exit;
    }

    $club = $clubId > 0 ? cocurricularGetClubById($clubId) : null;
    $clubName = (string) ($club['club_name'] ?? '');

    $eventDate = !empty($event['event_date']) ? date('F j, Y', strtotime((string) $event['event_date'])) : '';
    $startTime = !empty($event['start_time']) ? date('h:i A', strtotime((string) $event['start_time'])) : '';
    $endTime = !empty($event['end_time']) ? date('h:i A', strtotime((string) $event['end_time'])) : '';
    $timeRange = ($startTime !== '' && $endTime !== '') ? "{$startTime} - {$endTime}" : $startTime;

    $extraInstructions = trim((string) ($parsedBody['additional_instructions'] ?? ''));
    $instructions = 'Write an official campus announcement inviting students to this upcoming event based strictly on the provided event details.';
    if ($extraInstructions !== '') {
        $instructions .= ' ' . $extraInstructions;
    }

    $aiInput = [
        'club_name' => $clubName,
        'event_name' => (string) ($event['title'] ?? ''),
        'title' => (string) ($event['title'] ?? ''),
        'topic' => ((string) ($event['event_type'] ?? '')) . ': ' . ((string) ($event['title'] ?? '')),
        'details' => (string) ($event['description'] ?? ''),
        'event_date' => $eventDate,
        'event_time' => $timeRange,
        'venue' => (string) ($event['venue'] ?? ''),
        'additional_instructions' => $instructions,
    ];

    $diagContext = [
        'endpoint' => 'generate-event-announcement',
        'club_id'  => $clubId,
        'user_id'  => $currentUserId,
    ];

    $result = cocurricularGenerateAnnouncementDraft($aiInput, $diagContext);

    if (!$result['success']) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error'   => $result['error'] ?? 'Unable to generate announcement draft right now.',
            'message' => $result['error'] ?? 'Unable to generate announcement draft right now.',
        ]);
        exit;
    }

    $draftData = [
        'title'   => $result['data']['title'] ?? '',
        'content' => $result['data']['content'] ?? '',
        'summary' => $result['data']['summary'] ?? '',
    ];

    echo json_encode([
        'success'       => true,
        'data'          => $draftData,
        'draft'         => $draftData,
        'club_id'       => $clubId,
        'event_id'      => $eventId,
        'model'         => $result['model'] ?? 'local-fallback',
        'source'        => $result['source'] ?? 'local',
        'provider'      => $result['provider'] ?? 'local',
        'fallback_used' => !empty($result['fallback_used']),
        'generated_at'  => $result['generated_at'] ?? date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $exception) {
    error_log('generate-event-announcement endpoint error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to generate announcement draft right now. Please try again later.', 'message' => 'Unable to generate announcement draft right now. Please try again later.']);
}
