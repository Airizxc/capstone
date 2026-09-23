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
    $role = getCurrentUserRoleKey();
    if ($role !== 'osa' && !smsIsGrantedAdminRole()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Only OSA or Admin can access the AI announcement generator.']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    // Parse JSON body or POST form data
    $rawInput = file_get_contents('php://input');
    $parsedBody = [];
    if (!empty($rawInput) && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $parsedBody = json_decode($rawInput, true) ?: [];
    } else {
        $parsedBody = $_POST;
    }

    $eventId = (int) ($parsedBody['event_id'] ?? 0);
    if ($eventId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please select a valid event.']);
        exit;
    }

    $event = cocurricularGetEventById($eventId);
    if (!$event) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Event not found.']);
        exit;
    }

    $clubId = (int) ($event['club_id'] ?? 0);
    $club = $clubId > 0 ? cocurricularGetClubById($clubId) : null;
    $clubName = (string) ($club['club_name'] ?? '');

    $eventDate = !empty($event['event_date']) ? date('F j, Y', strtotime((string) $event['event_date'])) : '';
    $startTime = !empty($event['start_time']) ? date('h:i A', strtotime((string) $event['start_time'])) : '';
    $endTime = !empty($event['end_time']) ? date('h:i A', strtotime((string) $event['end_time'])) : '';
    $timeRange = ($startTime !== '' && $endTime !== '') ? "{$startTime} - {$endTime}" : $startTime;

    $aiInput = [
        'club_name' => $clubName,
        'event_name' => (string) ($event['title'] ?? ''),
        'title' => (string) ($event['title'] ?? ''),
        'topic' => ((string) ($event['event_type'] ?? '')) . ': ' . ((string) ($event['title'] ?? '')),
        'details' => (string) ($event['description'] ?? ''),
        'event_date' => $eventDate,
        'event_time' => $timeRange,
        'venue' => (string) ($event['venue'] ?? ''),
        'additional_instructions' => 'Write an official campus announcement inviting students to this upcoming event based strictly on the provided event details.',
    ];

    $result = cocurricularGenerateAnnouncementDraft($aiInput);

    if (!$result['success']) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => $result['error'] ?? 'Unable to generate announcement draft right now.',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'draft' => [
            'title' => $result['data']['title'] ?? '',
            'content' => $result['data']['content'] ?? '',
            'summary' => $result['data']['summary'] ?? '',
        ],
        'club_id' => $clubId,
        'event_id' => $eventId,
        'model' => $result['model'] ?? COCURRICULAR_OPENAI_MODEL,
        'generated_at' => $result['generated_at'] ?? date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $exception) {
    error_log('generate-event-announcement endpoint error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to generate announcement draft right now. Please try again later.']);
}
