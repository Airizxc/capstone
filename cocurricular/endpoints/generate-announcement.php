<?php
/**
 * Co-Curricular Module — AI Announcement Draft Generator Endpoint
 * Handles secure server-side AJAX requests from OSA to generate announcement drafts via GPT-4.1.
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
        echo json_encode(['success' => false, 'error' => 'Unauthorized. You do not have permission to access the AI announcement assistant.']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
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
            echo json_encode(['success' => false, 'error' => 'Invalid JSON request body.']);
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
        echo json_encode(['success' => false, 'error' => 'Invalid or missing CSRF token.']);
        exit;
    }

    $clubId = (int) ($parsedBody['club_id'] ?? 0);

    // Faculty Advisers MUST provide their assigned club_id
    if (!$isOsaAdmin) {
        if ($clubId <= 0 || !cocurricularIsFacultyAdviserOfClub($currentUserId, $clubId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized. You are not the assigned faculty adviser for this club.']);
            exit;
        }
    } else {
        if ($clubId > 0 && !cocurricularGetClubById($clubId)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Club not found.']);
            exit;
        }
    }

    $clubName = '';
    if ($clubId > 0) {
        $club = cocurricularGetClubById($clubId);
        $clubName = (string) ($club['club_name'] ?? '');
    }

    $input = [
        'title' => trim((string) ($parsedBody['title'] ?? '')),
        'topic' => trim((string) ($parsedBody['topic'] ?? '')),
        'details' => trim((string) ($parsedBody['details'] ?? '')),
        'club_name' => $clubName !== '' ? $clubName : trim((string) ($parsedBody['club_name'] ?? '')),
        'event_name' => trim((string) ($parsedBody['event_name'] ?? '')),
        'event_date' => trim((string) ($parsedBody['event_date'] ?? '')),
        'event_time' => trim((string) ($parsedBody['event_time'] ?? '')),
        'venue' => trim((string) ($parsedBody['venue'] ?? '')),
        'audience' => trim((string) ($parsedBody['audience'] ?? '')),
        'additional_instructions' => trim((string) ($parsedBody['additional_instructions'] ?? '')),
    ];

    // Event integration: if event_id is supplied, verify and pre-fill details
    $eventId = (int) ($parsedBody['event_id'] ?? 0);
    if ($eventId > 0) {
        $event = cocurricularGetEventById($eventId);
        if ($event) {
            $eventClubId = (int) ($event['club_id'] ?? 0);
            if (!$isOsaAdmin && $eventClubId !== $clubId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Selected event does not belong to your assigned club.']);
                exit;
            }
            if ($clubName === '' && $eventClubId > 0) {
                $evClub = cocurricularGetClubById($eventClubId);
                $clubName = (string) ($evClub['club_name'] ?? '');
                $input['club_name'] = $clubName;
            }

            $eventDate = !empty($event['event_date']) ? date('F j, Y', strtotime((string) $event['event_date'])) : '';
            $startTime = !empty($event['start_time']) ? date('h:i A', strtotime((string) $event['start_time'])) : '';
            $endTime = !empty($event['end_time']) ? date('h:i A', strtotime((string) $event['end_time'])) : '';
            $timeRange = ($startTime !== '' && $endTime !== '') ? "{$startTime} - {$endTime}" : $startTime;

            if ($input['topic'] === '') {
                $input['topic'] = ((string) ($event['event_type'] ?? '')) . ': ' . ((string) ($event['title'] ?? ''));
            }
            if ($input['event_name'] === '') {
                $input['event_name'] = (string) ($event['title'] ?? '');
            }
            if ($input['details'] === '') {
                $input['details'] = (string) ($event['description'] ?? '');
            }
            if ($input['event_date'] === '') {
                $input['event_date'] = $eventDate;
            }
            if ($input['event_time'] === '') {
                $input['event_time'] = $timeRange;
            }
            if ($input['venue'] === '') {
                $input['venue'] = (string) ($event['venue'] ?? '');
            }
        }
    }

    $diagContext = [
        'endpoint' => 'generate-announcement',
        'club_id'  => $clubId,
        'user_id'  => $currentUserId,
    ];

    $result = cocurricularGenerateAnnouncementDraft($input, $diagContext);

    if (!$result['success']) {
        http_response_code(422);
        echo json_encode($result);
        exit;
    }

    echo json_encode($result);
} catch (Throwable $exception) {
    error_log('Cocurricular generate-announcement endpoint error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to generate announcement draft right now. Please try again later.']);
}
