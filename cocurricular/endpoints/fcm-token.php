<?php
/**
 * Co-Curricular Module — FCM Device Token Registration Endpoint
 * Accepts FCM token from authenticated student browser and saves it to cocurricular_fcm_tokens.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireAuth();

    // Allow any authenticated user to register FCM push notification token

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed. Use POST.',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    $userId = (int) getCurrentUserId();
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthenticated session.',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    // Parse input from JSON payload or standard POST fields
    $rawInput = (string) file_get_contents('php://input');
    $data = [];
    if ($rawInput !== '') {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    if (empty($data)) {
        $data = $_POST;
    }

    $fcmToken = trim((string) ($data['fcm_token'] ?? $data['token'] ?? ''));
    $deviceType = trim((string) ($data['device_type'] ?? 'web'));
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if ($fcmToken === '' || strlen($fcmToken) < 10 || strlen($fcmToken) > 500 || str_starts_with($fcmToken, 'web_push_')) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or synthetic FCM token. Real Firebase registration token required.',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    $registered = cocurricularRegisterFcmToken($userId, $fcmToken, $deviceType, $userAgent);
    if (!$registered) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to save FCM device token.',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'FCM token registered.',
    ], JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    error_log('Co-Curricular FCM token endpoint error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error processing token registration.',
    ], JSON_THROW_ON_ERROR);
}
