<?php
/**
 * Co-Curricular Module — Scheduled Attendance Notification Processor
 * Safe to execute via CLI (Cron / Windows Task Scheduler) or protected web endpoint.
 *
 * Usage via CLI:
 *   php modules/cocurricular/jobs/process-attendance-notifications.php
 */
declare(strict_types=1);

// Prevent HTML error output when running under Web server
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';
require_once __DIR__ . '/../includes/cocurricular-notification-triggers.php';

// Authorization check for web execution
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../../../includes/authentication.php';
    try {
        requireAuth();
        if (getCurrentUserRoleKey() !== 'osa' && !smsIsGrantedAdminRole()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }
}

try {
    $reminderMinutes = defined('COCURRICULAR_ATTENDANCE_REMINDER_MINUTES')
        ? (int) COCURRICULAR_ATTENDANCE_REMINDER_MINUTES
        : 30;

    $result = cocurricularProcessAttendanceReminders($reminderMinutes);

    $response = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'inspected_sessions' => $result['inspected_sessions'] ?? 0,
        'reminders_sent' => $result['reminders_sent'] ?? 0,
        'deadline_reminders_sent' => $result['deadline_reminders_sent'] ?? 0,
    ];

    if (php_sapi_name() === 'cli') {
        echo "[COCURRICULAR JOB] " . date('Y-m-d H:i:s') . " - Attendance Notification Processor Executed.\n";
        echo "Inspected Sessions: " . $response['inspected_sessions'] . "\n";
        echo "Reminders Sent: " . $response['reminders_sent'] . "\n";
        echo "Deadline Reminders Sent: " . $response['deadline_reminders_sent'] . "\n";
    } else {
        echo json_encode($response);
    }
} catch (Throwable $exception) {
    error_log('process-attendance-notifications job error: ' . $exception->getMessage());
    if (php_sapi_name() === 'cli') {
        echo "[COCURRICULAR JOB ERROR] " . $exception->getMessage() . "\n";
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Processor execution failed.']);
}
