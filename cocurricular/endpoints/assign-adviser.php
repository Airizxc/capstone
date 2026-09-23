<?php
/**
 * Co-Curricular Module — Assign Faculty Adviser Endpoint
 * Handles secure server-side AJAX requests from OSA / Admin to assign a faculty adviser to a club.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireAuth();

    $currentUserId = (int) getCurrentUserId();
    $roleKey = getCurrentUserRoleKey();

    if ($roleKey !== 'osa' && !smsIsGrantedAdminRole($roleKey) && !userCanAccessModule('cocurricular')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized. Only OSA or Admin can assign faculty advisers.',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed. Use POST.',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    // Parse input from JSON payload or standard POST fields
    $rawInput = (string) file_get_contents('php://input');
    $data = [];
    if ($rawInput !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    } else {
        $data = $_POST;
    }

    // CSRF verification if token is passed
    $submittedToken = (string) ($data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($submittedToken !== '' && function_exists('verifyCsrfToken') && !verifyCsrfToken($submittedToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired CSRF token.',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    $clubId = (int) ($data['club_id'] ?? 0);
    $facultyUserId = (int) ($data['faculty_user_id'] ?? 0);

    if ($clubId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Please select a valid club.',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    $action = strtolower(trim((string) ($data['action'] ?? 'assign')));
    if ($action === 'unassign' || $facultyUserId === 0) {
        $result = cocurricularUnassignClubAdviser($clubId, $currentUserId);
    } else {
        $result = cocurricularAssignClubAdviser($clubId, $facultyUserId, $currentUserId);
    }

    if (!($result['success'] ?? false)) {
        http_response_code(422);
    }

    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Co-Curricular assign-adviser endpoint error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error while processing adviser assignment.',
    ], JSON_THROW_ON_ERROR);
}
