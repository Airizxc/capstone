<?php
/**
 * OSA-only attendance process endpoint. The tracker UI can consume this later.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireAuth();
    if (getCurrentUserRoleKey() !== 'osa' || !userCanAccessModule('cocurricular')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $userId = (int) getCurrentUserId();
    $eventId = (int) ($_POST['event_id'] ?? $_GET['event_id'] ?? 0);
    $attendanceId = (int) ($_POST['attendance_id'] ?? $_GET['attendance_id'] ?? 0);
    if ($eventId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid event is required.'], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $session = cocurricularGetAttendanceSession($attendanceId);
        echo json_encode([
            'success' => true,
            'session' => $session,
            'sessions' => cocurricularFetchAttendanceSessions($eventId),
            'roster' => $session ? cocurricularFetchAttendanceRoster($attendanceId) : [],
            'summary' => $session ? cocurricularGetAttendanceSummary($attendanceId) : [],
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $result = match ($action) {
        'create' => cocurricularCreateAttendanceSession($eventId, $userId, [
            'club_id' => (int) ($_POST['club_id'] ?? 0),
            'location' => trim((string) ($_POST['location'] ?? '')),
            'session_date' => trim((string) ($_POST['session_date'] ?? '')),
            'session_start_time' => trim((string) ($_POST['session_start_time'] ?? '')),
            'session_end_time' => trim((string) ($_POST['session_end_time'] ?? '')),
            'attendance_open_at' => trim((string) ($_POST['attendance_open_at'] ?? '')),
            'attendance_deadline' => trim((string) ($_POST['attendance_deadline'] ?? '')),
            'attendance_method' => trim((string) ($_POST['attendance_method'] ?? '')),
        ]),
        'open' => (function () use ($attendanceId, $userId): array {
            $session = cocurricularGetAttendanceSession($attendanceId);
            if (!$session) {
                return ['status' => 'not_found'];
            }
            return cocurricularOpenAttendance($attendanceId, $userId);
        })(),
        'mark' => cocurricularUpdateAttendanceRecord(
            $attendanceId,
            (int) ($_POST['record_id'] ?? 0),
            $userId,
            (string) ($_POST['attendance_status'] ?? ''),
            isset($_POST['remarks']) ? trim((string) $_POST['remarks']) : null
        ),
        'close' => cocurricularCloseAttendance($attendanceId),
        'finalize' => cocurricularFinalizeAttendance($attendanceId),
        default => ['status' => 'invalid_action'],
    };

    $okStatuses = ['created', 'existing', 'open', 'updated', 'closed', 'finalized'];
    $ok = in_array((string) ($result['status'] ?? ''), $okStatuses, true);
    if (!$ok) {
        http_response_code(match ($result['status'] ?? '') {
            'invalid_event', 'not_found', 'invalid_action' => 400,
            'no_approved_participants', 'closed', 'expired', 'finalized', 'still_open', 'closed_or_missing', 'invalid_transition', 'invalid_configuration' => 409,
            default => 500,
        });
    }
    echo json_encode([
        'success' => $ok,
        'status' => $result['status'] ?? 'error',
        'message' => $result['message'] ?? ($ok ? 'Attendance session created.' : 'Unable to create attendance session.'),
        'session' => $result['session'] ?? cocurricularGetAttendanceSession($attendanceId),
        'sessions' => cocurricularFetchAttendanceSessions($eventId),
        'roster' => $ok && $attendanceId > 0 ? cocurricularFetchAttendanceRoster($attendanceId) : [],
        'summary' => $ok && $attendanceId > 0 ? cocurricularGetAttendanceSummary($attendanceId) : [],
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Cocurricular attendance process error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process attendance.'], JSON_THROW_ON_ERROR);
}
