<?php
/**
 * Co-Curricular Module Configuration File
 *
 * Loads environment configuration dynamically from environment variables (.env).
 * Path: modules/cocurricular/config/cocurricular-config.php
 */
declare(strict_types=1);

require_once __DIR__ . '/env-loader.php';

// --------------------------------------------------------------------------
// 1. OpenAI API Configuration (GPT-4.1)
// --------------------------------------------------------------------------
if (!defined('OPENAI_API_KEY')) {
    $openaiKey = cocurricular_env('OPENAI_API_KEY');
    define('OPENAI_API_KEY', $openaiKey ?? '');
}

if (!defined('COCURRICULAR_OPENAI_MODEL')) {
    define('COCURRICULAR_OPENAI_MODEL', cocurricular_env('COCURRICULAR_OPENAI_MODEL', 'gpt-4.1'));
}

// --------------------------------------------------------------------------
// 2. Firebase Public Client Configuration (Web Push SDK)
// --------------------------------------------------------------------------
if (!defined('FIREBASE_API_KEY')) {
    define('FIREBASE_API_KEY', cocurricular_env('FIREBASE_API_KEY', ''));
}

if (!defined('FIREBASE_AUTH_DOMAIN')) {
    define('FIREBASE_AUTH_DOMAIN', cocurricular_env('FIREBASE_AUTH_DOMAIN', ''));
}

if (!defined('FIREBASE_PROJECT_ID')) {
    define('FIREBASE_PROJECT_ID', cocurricular_env('FIREBASE_PROJECT_ID', ''));
}

if (!defined('FIREBASE_STORAGE_BUCKET')) {
    define('FIREBASE_STORAGE_BUCKET', cocurricular_env('FIREBASE_STORAGE_BUCKET', ''));
}

if (!defined('FIREBASE_MESSAGING_SENDER_ID')) {
    define('FIREBASE_MESSAGING_SENDER_ID', cocurricular_env('FIREBASE_MESSAGING_SENDER_ID', ''));
}

if (!defined('FIREBASE_APP_ID')) {
    define('FIREBASE_APP_ID', cocurricular_env('FIREBASE_APP_ID', ''));
}

if (!defined('FIREBASE_VAPID_KEY')) {
    define('FIREBASE_VAPID_KEY', cocurricular_env('FIREBASE_VAPID_KEY', ''));
}

// --------------------------------------------------------------------------
// 3. Firebase Server-Side Service Account Credentials (FCM HTTP v1 Dispatcher)
// --------------------------------------------------------------------------
if (!defined('FIREBASE_SERVICE_ACCOUNT_PATH')) {
    $saPath = cocurricular_env('FIREBASE_SERVICE_ACCOUNT_PATH');
    if ($saPath === null || $saPath === '') {
        $saPath = __DIR__ . '/firebase-service-account.json';
    } elseif (!is_file($saPath)) {
        if (is_file(__DIR__ . '/' . $saPath)) {
            $saPath = __DIR__ . '/' . $saPath;
        } elseif (is_file(dirname(__DIR__) . '/' . $saPath)) {
            $saPath = dirname(__DIR__) . '/' . $saPath;
        }
    }
    define('FIREBASE_SERVICE_ACCOUNT_PATH', $saPath);
}

if (!defined('FIREBASE_CLIENT_EMAIL')) {
    $clientEmail = cocurricular_env('FIREBASE_CLIENT_EMAIL', '');
    if ($clientEmail !== '') {
        define('FIREBASE_CLIENT_EMAIL', $clientEmail);
    }
}

if (!defined('FIREBASE_PRIVATE_KEY')) {
    $privateKey = cocurricular_env('FIREBASE_PRIVATE_KEY', '');
    if ($privateKey !== '') {
        define('FIREBASE_PRIVATE_KEY', $privateKey);
    }
}
