<?php
/**
 * Co-Curricular Module — Server-Side FCM HTTP v1 Push Notification Dispatcher
 * Dispatches push notifications to registered student devices via Google Firebase Cloud Messaging HTTP v1 API.
 */
declare(strict_types=1);

if (file_exists(__DIR__ . '/../config/cocurricular-config.php')) {
    require_once __DIR__ . '/../config/cocurricular-config.php';
}

require_once __DIR__ . '/cocurricular-db.php';
require_once __DIR__ . '/cocurricular-notifications.php';

/**
 * Base64Url encoding helper for JWT generation.
 */
function cocurricularBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Retrieve Firebase Service Account Credentials from storage or environment.
 */
function cocurricularGetFirebaseServiceAccountCredentials(): ?array
{
    // 1. Try reading defined FIREBASE_SERVICE_ACCOUNT_PATH or storage/keys/firebase-service-account.json
    $jsonPath = defined('FIREBASE_SERVICE_ACCOUNT_PATH') && is_string(FIREBASE_SERVICE_ACCOUNT_PATH) && is_file(FIREBASE_SERVICE_ACCOUNT_PATH)
        ? FIREBASE_SERVICE_ACCOUNT_PATH
        : ROOT_PATH . '/storage/keys/firebase-service-account.json';

    if (is_file($jsonPath) && is_readable($jsonPath)) {
        $raw = file_get_contents($jsonPath);
        if ($raw !== false && $raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed) && !empty($parsed['project_id']) && !empty($parsed['client_email']) && !empty($parsed['private_key'])) {
                return [
                    'project_id' => trim((string) $parsed['project_id']),
                    'client_email' => trim((string) $parsed['client_email']),
                    'private_key' => str_replace('\n', "\n", (string) $parsed['private_key']),
                ];
            }
        }
    }

    // 2. Fall back to environment variables or constants
    $projectId = defined('FIREBASE_PROJECT_ID') ? (string) FIREBASE_PROJECT_ID : (string) sms2_env('FIREBASE_PROJECT_ID', '');
    $clientEmail = defined('FIREBASE_CLIENT_EMAIL') ? (string) FIREBASE_CLIENT_EMAIL : (string) sms2_env('FIREBASE_CLIENT_EMAIL', '');
    $privateKey = defined('FIREBASE_PRIVATE_KEY') ? (string) FIREBASE_PRIVATE_KEY : (string) sms2_env('FIREBASE_PRIVATE_KEY', '');

    if ($projectId !== '' && $clientEmail !== '' && $privateKey !== '') {
        return [
            'project_id' => trim($projectId),
            'client_email' => trim($clientEmail),
            'private_key' => str_replace('\n', "\n", $privateKey),
        ];
    }

    return null;
}

/**
 * Generate a short-lived OAuth 2.0 Access Token for FCM HTTP v1 using Service Account RS256 JWT.
 */
function cocurricularGetFcmOAuth2AccessToken(?array $credentials = null): ?string
{
    static $cachedToken = null;
    static $expiresAt = 0;

    if ($cachedToken !== null && time() < ($expiresAt - 60)) {
        return $cachedToken;
    }

    if ($credentials === null) {
        $credentials = cocurricularGetFirebaseServiceAccountCredentials();
    }
    if (!$credentials || empty($credentials['client_email']) || empty($credentials['private_key'])) {
        return null;
    }

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $payload = [
        'iss' => $credentials['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ];

    $encodedHeader = cocurricularBase64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $encodedPayload = cocurricularBase64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $toSign = $encodedHeader . '.' . $encodedPayload;

    $signature = '';
    $signSuccess = @openssl_sign($toSign, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
    if (!$signSuccess || $signature === '') {
        error_log('cocurricularGetFcmOAuth2AccessToken error: Unable to sign JWT with private key.');
        return null;
    }

    $jwt = $toSign . '.' . cocurricularBase64UrlEncode($signature);

    // Request OAuth 2.0 access token via Google OAuth endpoint
    $tokenUrl = 'https://oauth2.googleapis.com/token';
    $postFields = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]);

    $responseRaw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $tokenUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 10,
        ]);
        $responseRaw = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $postFields,
                'timeout' => 10,
            ],
        ]);
        $responseRaw = @file_get_contents($tokenUrl, false, $ctx);
    }

    if ($responseRaw === false || $responseRaw === '') {
        error_log('cocurricularGetFcmOAuth2AccessToken error: OAuth token request failed.');
        return null;
    }

    $res = json_decode((string) $responseRaw, true);
    if (!is_array($res) || empty($res['access_token'])) {
        error_log('cocurricularGetFcmOAuth2AccessToken error: Invalid OAuth response: ' . $responseRaw);
        return null;
    }

    $cachedToken = (string) $res['access_token'];
    $expiresIn = isset($res['expires_in']) ? (int) $res['expires_in'] : 3600;
    $expiresAt = $now + $expiresIn;

    return $cachedToken;
}

/**
 * Dispatch a single FCM push message to a specific registration token via FCM HTTP v1.
 */
function cocurricularSendFcmMessageToToken(
    string $fcmToken,
    string $title,
    string $message,
    ?string $destinationUrl = null,
    array $data = [],
    ?array $credentials = null
): array {
    $fcmToken = trim($fcmToken);
    if ($fcmToken === '') {
        return ['success' => false, 'error' => 'empty_token'];
    }

    if ($credentials === null) {
        $credentials = cocurricularGetFirebaseServiceAccountCredentials();
    }
    if (!$credentials || empty($credentials['project_id'])) {
        return ['success' => false, 'error' => 'missing_credentials'];
    }

    $accessToken = cocurricularGetFcmOAuth2AccessToken($credentials);
    if (!$accessToken) {
        return ['success' => false, 'error' => 'oauth_auth_failed'];
    }

    $projectId = $credentials['project_id'];
    $fcmEndpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

    $baseUrl = defined('BASE_URL') ? (string) BASE_URL : '';
    $rawUrl = $destinationUrl ?: '/modules/cocurricular/pages/my-club.php';
    $fullUrl = function_exists('smsNotificationAppUrl')
        ? smsNotificationAppUrl($rawUrl)
        : (rtrim($baseUrl, '/') . '/' . ltrim($rawUrl, '/'));

    // Construct FCM HTTP v1 message payload
    $dataPayload = array_merge([
        'title' => $title,
        'message' => $message,
        'destination_url' => $fullUrl,
        'click_action' => $fullUrl,
    ], array_map('strval', $data));

    $payload = [
        'message' => [
            'token' => $fcmToken,
            'notification' => [
                'title' => $title,
                'body' => $message,
            ],
            'data' => $dataPayload,
            'webpush' => [
                'headers' => [
                    'Urgency' => 'high',
                ],
                'notification' => [
                    'icon' => '/sms2-capstone/images/bcp-logo-source.png',
                    'badge' => '/sms2-capstone/images/bcp-logo-source.png',
                    'require_interaction' => true,
                ],
                'fcm_options' => [
                    'link' => $fullUrl,
                ],
            ],
        ],
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ];

    $httpCode = 0;
    $responseRaw = false;

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $fcmEndpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
        ]);
        $responseRaw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $payloadJson,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);
        $responseRaw = @file_get_contents($fcmEndpoint, false, $ctx);
        if (isset($http_response_header) && is_array($http_response_header)) {
            preg_match('~HTTP/\d\.\d\s+(\d+)~i', $http_response_header[0] ?? '', $m);
            $httpCode = isset($m[1]) ? (int) $m[1] : 0;
        }
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        $res = json_decode((string) $responseRaw, true);
        $tokenFingerprint = strlen($fcmToken) > 12 ? substr($fcmToken, 0, 6) . '...' . substr($fcmToken, -6) : 'short_token';
        $msgId = (string) ($res['name'] ?? 'fcm_success');
        error_log("[Co-Curricular FCM] Dispatch HTTP {$httpCode} SUCCESS for token {$tokenFingerprint}, message_id='{$msgId}'");
        return [
            'success' => true,
            'message_id' => $msgId,
            'fcm_http_code' => $httpCode,
        ];
    }

    // Parse FCM error response
    $resError = json_decode((string) $responseRaw, true);
    $statusMsg = (string) ($resError['error']['status'] ?? $resError['error']['message'] ?? '');
    $errorCode = '';
    if (isset($resError['error']['details']) && is_array($resError['error']['details'])) {
        foreach ($resError['error']['details'] as $detail) {
            if (isset($detail['errorCode'])) {
                $errorCode = (string) $detail['errorCode'];
                break;
            }
        }
    }
    $rawErrorStr = strtolower((string) $responseRaw);

    $tokenFingerprint = strlen($fcmToken) > 12 ? substr($fcmToken, 0, 6) . '...' . substr($fcmToken, -6) : 'short_token';

    // Only deactivate if FCM explicitly confirms token is UNREGISTERED or NOT_FOUND
    $isPermanentlyInvalid = ($httpCode === 404 && ($statusMsg === 'NOT_FOUND' || $errorCode === 'NOT_FOUND'))
        || ($errorCode === 'UNREGISTERED')
        || (str_contains($rawErrorStr, 'unregistered') || str_contains($rawErrorStr, 'token_not_registered'));

    if ($isPermanentlyInvalid) {
        cocurricularDeactivateFcmToken($fcmToken);
        error_log("[Co-Curricular FCM] Token {$tokenFingerprint} deactivated. HTTP {$httpCode}, status: '{$statusMsg}', errorCode: '{$errorCode}'");
        return [
            'success' => false,
            'error' => 'invalid_token',
            'deactivated' => true,
            'fcm_http_code' => $httpCode,
            'fcm_status' => $statusMsg,
            'fcm_error_code' => $errorCode
        ];
    }

    error_log("[Co-Curricular FCM] Dispatch HTTP failure ({$httpCode}) for token {$tokenFingerprint}: status='{$statusMsg}', errorCode='{$errorCode}'");
    return [
        'success' => false,
        'error' => "http_error_{$httpCode}",
        'deactivated' => false,
        'fcm_http_code' => $httpCode,
        'fcm_status' => $statusMsg,
        'fcm_error_code' => $errorCode
    ];
}

/**
 * Send an FCM push notification to all active devices of one or more student users.
 */
function cocurricularSendFcmNotification(
    array $userIds,
    string $title,
    string $message,
    ?string $destinationUrl = null,
    array $data = []
): array {
    $credentials = cocurricularGetFirebaseServiceAccountCredentials();
    if (!$credentials) {
        return [
            'success' => false,
            'attempted' => 0,
            'sent' => 0,
            'failed' => 0,
            'deactivated' => 0,
            'message' => 'Firebase service account credentials not configured.',
        ];
    }

    $activeTokens = cocurricularGetActiveFcmTokensForUsers($userIds);
    if (empty($activeTokens)) {
        return [
            'success' => true,
            'attempted' => 0,
            'sent' => 0,
            'failed' => 0,
            'deactivated' => 0,
            'message' => 'No active FCM tokens found for target recipients.',
        ];
    }

    $attempted = 0;
    $sent = 0;
    $failed = 0;
    $deactivated = 0;

    foreach ($activeTokens as $tokenRecord) {
        $token = (string) ($tokenRecord['fcm_token'] ?? '');
        if ($token === '') {
            continue;
        }

        $attempted++;
        $result = cocurricularSendFcmMessageToToken($token, $title, $message, $destinationUrl, $data, $credentials);
        if (!empty($result['success'])) {
            $sent++;
        } else {
            $failed++;
            if (!empty($result['deactivated'])) {
                $deactivated++;
            }
        }
    }

    return [
        'success' => $sent > 0 || $attempted === 0,
        'attempted' => $attempted,
        'sent' => $sent,
        'failed' => $failed,
        'deactivated' => $deactivated,
    ];
}

/**
 * Send an FCM push notification using an existing stored database notification record.
 */
function cocurricularSendStoredNotification(int $notificationId): array
{
    if ($notificationId <= 0) {
        return ['success' => false, 'message' => 'Invalid notification ID.'];
    }

    $pdo = cocurricularDb();
    if (!$pdo) {
        return ['success' => false, 'message' => 'Database connection unavailable.'];
    }

    try {
        $stmt = $pdo->prepare('
            SELECT id, user_id, title, message, type, related_id, related_module, destination_url
            FROM cocurricular_notifications
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $notificationId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['success' => false, 'message' => 'Stored notification record not found.'];
        }

        $recipientUserId = (int) $row['user_id'];
        $title = (string) $row['title'];
        $message = (string) $row['message'];
        $destinationUrl = (string) ($row['destination_url'] ?? '/modules/cocurricular/pages/my-club.php');

        $announcementId = 0;
        $clubId = 0;
        if (($row['type'] ?? '') === 'announcement_new') {
            $announcementId = (int) ($row['related_id'] ?? 0);
        }
        if (preg_match('/club_id=(\d+)/', $destinationUrl, $matches)) {
            $clubId = (int) $matches[1];
        }

        $clubName = '';
        if ($clubId > 0 && function_exists('cocurricularGetClubById')) {
            $clubInfo = cocurricularGetClubById($clubId);
            if ($clubInfo && !empty($clubInfo['club_name'])) {
                $clubName = (string) $clubInfo['club_name'];
            }
        }

        $data = [
            'notification_id' => (string) $row['id'],
            'type' => (string) ($row['type'] ?? 'general'),
            'related_id' => (string) ($row['related_id'] ?? 0),
            'related_module' => (string) ($row['related_module'] ?? 'cocurricular'),
            'announcement_id' => (string) $announcementId,
            'club_id' => (string) $clubId,
            'club_name' => (string) $clubName,
            'destination_url' => (string) $destinationUrl,
        ];

        $dispatchResult = cocurricularSendFcmNotification([$recipientUserId], $title, $message, $destinationUrl, $data);
        $activeTokens = cocurricularGetActiveFcmTokensForUsers([$recipientUserId]);
        $tokenCount = count($activeTokens);
        $firstToken = $tokenCount > 0 ? (string) ($activeTokens[0]['fcm_token'] ?? '') : '';
        $fp = strlen($firstToken) > 12 ? substr($firstToken, 0, 6) . '...' . substr($firstToken, -6) : 'none';

        error_log(sprintf(
            "[Co-Curricular FCM Diagnostics] Notification ID: %d, Recipient User: %d, Type: '%s', Related ID: %d, Active Tokens: %d, Token FP: %s, Dispatch: %s (sent=%d, failed=%d)",
            $notificationId,
            $recipientUserId,
            $data['type'],
            (int) $data['related_id'],
            $tokenCount,
            $fp,
            !empty($dispatchResult['success']) ? 'SUCCESS' : 'FAILED',
            $dispatchResult['sent'] ?? 0,
            $dispatchResult['failed'] ?? 0
        ));

        return $dispatchResult;
    } catch (Throwable $e) {
        error_log('cocurricularSendStoredNotification error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Error querying stored notification record.'];
    }
}
