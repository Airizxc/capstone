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
    $role = getCurrentUserRoleKey();
    if ($role !== 'osa' && !smsIsGrantedAdminRole()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized. Only OSA or Admin can access the AI announcement assistant.']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
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

    $clubId = (int) ($parsedBody['club_id'] ?? 0);
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

    $result = cocurricularGenerateAnnouncementDraft($input);

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
