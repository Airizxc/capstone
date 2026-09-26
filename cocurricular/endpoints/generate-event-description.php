<?php
/**
 * Co-Curricular Module — AI Event Description Generator Endpoint
 * Assists Faculty Advisers in drafting rich, type-tailored event descriptions
 * directly within the Schedule Club Event modal before event creation.
 * Follows provider chain: Gemini -> OpenAI fallback -> Local Synthesizer fallback.
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
        echo json_encode(['success' => false, 'error' => 'Unauthorized. You do not have permission to access the AI event assistant.']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    // Parse JSON or POST body
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

    // Faculty Advisers must own/be assigned to the requested club
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

    $title = trim((string) ($parsedBody['title'] ?? ''));
    if ($title === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please enter an event title before generating a description.']);
        exit;
    }

    $eventType = trim((string) ($parsedBody['event_type'] ?? 'General Meeting'));
    $eventDate = trim((string) ($parsedBody['event_date'] ?? ''));
    $startTime = trim((string) ($parsedBody['start_time'] ?? ''));
    $endTime = trim((string) ($parsedBody['end_time'] ?? ''));
    $venue = trim((string) ($parsedBody['venue'] ?? ''));
    $currentDesc = trim((string) ($parsedBody['current_description'] ?? ''));
    $instructions = trim((string) ($parsedBody['additional_instructions'] ?? ''));

    $aiInput = [
        'title'                   => $title,
        'event_type'              => $eventType,
        'club_name'               => $clubName,
        'club_id'                 => $clubId,
        'event_date'              => $eventDate,
        'start_time'              => $startTime,
        'end_time'                => $endTime,
        'venue'                   => $venue,
        'current_description'     => $currentDesc,
        'additional_instructions' => $instructions,
    ];

    $diagnosticContext = [
        'endpoint' => 'generate-event-description',
        'user_id'  => $currentUserId,
        'club_id'  => $clubId,
    ];

    $result = cocurricularGenerateEventDescriptionDraft($aiInput, $diagnosticContext);

    if (empty($result['success']) || empty($result['description'])) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error'   => $result['error'] ?? 'Unable to generate event description. Please try again.',
        ]);
        exit;
    }

    echo json_encode([
        'success'       => true,
        'data'          => [
            'description' => $result['description'],
            'summary'     => $result['summary'] ?? '',
        ],
        'model'         => $result['model'] ?? 'gemini-3.6-flash',
        'provider'      => $result['provider'] ?? 'gemini',
        'source'        => $result['source'] ?? 'api',
        'fallback_used' => !empty($result['fallback_used']),
        'generated_at'  => $result['generated_at'] ?? date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'An unexpected server error occurred: ' . $e->getMessage(),
    ]);
}
