<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireAuth();
    if (getCurrentUserRoleKey() !== 'osa' || !userCanAccessModule('cocurricular')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }

    $applicationId = (int) ($_POST['application_id'] ?? 0);
    if ($applicationId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid application ID.']);
        exit;
    }

    $application = cocurricularGetMembershipApplication($applicationId);
    if (!$application) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Application or club not found.']);
        exit;
    }

    $student = [];
    $mainDb = db();
    if ($mainDb) {
        $statement = $mainDb->prepare('SELECT full_name, email, student_id FROM users WHERE id = ? LIMIT 1');
        $statement->execute([(int) $application['user_id']]);
        $student = $statement->fetch() ?: [];
    }

    $application['student_name'] = $student['full_name'] ?? 'Unknown';
    $application['student_email'] = $student['email'] ?? '';
    $application['student_id'] = $student['student_id'] ?? ($application['student_id'] ?? '');
    $application['reviewer'] = $application['reviewer'] ?? '';
    $application['submitted_at'] = $application['submitted_at'] ? date('M j, Y g:i A', strtotime($application['submitted_at'])) : '';
    $application['reviewed_at'] = $application['reviewed_at'] ? date('M j, Y g:i A', strtotime($application['reviewed_at'])) : '';

    echo json_encode(['success' => true, 'application' => $application]);
} catch (Throwable $exception) {
    error_log('Cocurricular OSA application details error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load application details.']);
}