<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireAuth();
    if (getCurrentUserRoleKey() !== 'student') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Student access is required.'], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Attendance submission must use POST.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $credential = trim((string) ($_POST['credential'] ?? $_POST['attendance_code'] ?? $_POST['token'] ?? ''));
    $action = strtolower(trim((string) ($_POST['action'] ?? 'submit')));
    $messages = [
        'invalid_code' => 'This QR code is not a valid attendance session.',
        'not_open' => 'Attendance is not yet open.',
        'expired' => 'The attendance deadline has passed.',
        'closed' => 'Attendance is closed.',
        'finalized' => 'Attendance has already been finalized.',
        'not_approved' => 'You are not an approved participant for this event.',
        'already_recorded' => 'You already recorded your attendance for this session.',
        'error' => 'Unable to process your attendance request right now. Please try again.',
    ];

    if ($action === 'validate') {
        $result = cocurricularValidateStudentAttendance($credential, (int) getCurrentUserId());
        $context = cocurricularResolveAttendanceContext($credential);
        $clubId = (int) ($context['club_id'] ?? 0);
        $status = (string) ($result['status'] ?? 'error');
        $response = [
            'success' => in_array($status, ['ready', 'already_recorded'], true),
            'status' => $status,
            'message' => $messages[$status] ?? $messages['error'],
            'event' => $result['event_title'] ?? null,
            'club' => $result['club_name'] ?? null,
            'date' => $result['session_date'] ?? null,
            'time' => $result['time_range'] ?? null,
            'location' => $result['location'] ?? null,
            'recorded_at' => $result['marked_at'] ?? null,
            'attendance_status' => $result['attendance_status'] ?? null,
            'open_at' => $result['open_at'] ?? null,
            'attendance_id' => $context['attendance_id'] ?? null,
            'event_id' => $context['event_id'] ?? null,
            'club_id' => $clubId ?: null,
            'return_url' => $clubId > 0
                ? BASE_URL . '/modules/cocurricular/pages/my-club.php?club_id=' . $clubId
                : BASE_URL . '/modules/cocurricular/pages/student-club-membership.php',
        ];
        if (!in_array($status, ['ready', 'already_recorded'], true)) {
            http_response_code(in_array($status, ['invalid_code', 'not_open', 'expired', 'closed', 'finalized', 'not_approved'], true) ? 409 : 500);
        }
        echo json_encode($response, JSON_THROW_ON_ERROR);
        exit;
    }

    $result = cocurricularSubmitStudentAttendance($credential, (int) getCurrentUserId());
    $context = cocurricularResolveAttendanceContext($credential);
    $clubId = (int) ($context['club_id'] ?? 0);
    $status = (string) ($result['status'] ?? 'error');
    if (!in_array($status, ['recorded', 'already_recorded'], true)) {
        http_response_code(in_array($status, ['invalid_code', 'not_open', 'expired', 'closed', 'finalized', 'not_approved'], true) ? 409 : 500);
    }
    echo json_encode([
        'success' => in_array($status, ['recorded', 'already_recorded'], true),
        'status' => $status,
        'message' => $messages[$status] ?? $messages['error'],
        'event' => $result['event_title'] ?? null,
        'club' => $result['club_name'] ?? null,
        'date' => $result['session_date'] ?? null,
        'time' => $result['time_range'] ?? null,
        'location' => $result['location'] ?? null,
        'recorded_at' => $result['marked_at'] ?? null,
        'attendance_status' => $result['attendance_status'] ?? null,
        'attendance_id' => $context['attendance_id'] ?? null,
        'event_id' => $context['event_id'] ?? null,
        'club_id' => $clubId ?: null,
        'return_url' => $clubId > 0
            ? BASE_URL . '/modules/cocurricular/pages/my-club.php?club_id=' . $clubId
            : BASE_URL . '/modules/cocurricular/pages/student-club-membership.php',
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Student attendance error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process your attendance request right now. Please try again.'], JSON_THROW_ON_ERROR);
}
