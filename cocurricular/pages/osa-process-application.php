<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
}
try {
    requireAuth();
    if (getCurrentUserRoleKey() !== 'osa' || !userCanAccessModule('cocurricular')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }

    $applicationId = (int) ($_POST['application_id'] ?? 0);
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($applicationId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid application or action.']);
        exit;
    }

    $application = cocurricularGetMembershipApplication($applicationId);
    if (!$application) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Application or club not found.']);
        exit;
    }
    if ($application['status'] !== 'Pending') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Only pending applications can be reviewed.']);
        exit;
    }

    $status = $action === 'approve' ? 'Approved' : 'Rejected';
    if (!cocurricularUpdateMembershipApplicationStatus($applicationId, $status)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Application was already reviewed or could not be updated.']);
        exit;
    }
    if (!$isAjax) {
        header('Location: ' . BASE_URL . '/modules/cocurricular/pages/membership-applications.php');
        exit;
    }
    echo json_encode(['success' => true, 'status' => $status, 'application_id' => $applicationId]);
} catch (Throwable $exception) {
    error_log('Cocurricular OSA application error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process application.']);
}
