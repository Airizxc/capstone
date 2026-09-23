<?php
/**
 * Co-Curricular Module — Notification Management Endpoint
 * Provides JSON notification listing, unread counts, and mark-as-read actions for authenticated students.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireAuth();

    $userId = (int) getCurrentUserId();
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthenticated session.',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    $roleKey = getCurrentUserRoleKey();
    if ($roleKey !== 'student' && !smsIsGrantedAdminRole($roleKey)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized role.',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    // Handle GET Request — Retrieve notifications & unread count
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $unreadOnly = !empty($_GET['unread_only']);
        $limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20;

        $unreadCount = cocurricularUnreadNotificationCount($userId);
        $payload = cocurricularNotificationPayloadForCurrentUser($limit);

        if ($unreadOnly) {
            $payload = array_values(array_filter($payload, static fn(array $item): bool => !empty($item['is_unread'])));
        }

        echo json_encode([
            'success' => true,
            'unread_count' => $unreadCount,
            'count' => count($payload),
            'notifications' => $payload,
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    // Handle POST Request — State mutations (Mark Read / Mark All Read)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        $action = trim((string) ($data['action'] ?? 'mark_read'));

        if ($action === 'mark_all_read') {
            $ok = cocurricularMarkAllNotificationsRead($userId);
            echo json_encode([
                'success' => $ok,
                'message' => $ok ? 'All notifications marked as read.' : 'No unread notifications.',
                'unread_count' => cocurricularUnreadNotificationCount($userId),
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        if ($action === 'mark_read') {
            $notificationId = (int) ($data['notification_id'] ?? $data['id'] ?? 0);
            if ($notificationId <= 0) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Valid notification_id is required.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            $marked = cocurricularMarkNotificationRead($notificationId, $userId);
            echo json_encode([
                'success' => true,
                'marked' => $marked,
                'message' => $marked ? 'Notification marked as read.' : 'Notification already read or not found.',
                'unread_count' => cocurricularUnreadNotificationCount($userId),
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action.',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ], JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    error_log('Co-Curricular notification endpoint error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error processing notification request.',
    ], JSON_THROW_ON_ERROR);
}
