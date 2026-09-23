<?php
/**
 * Co-Curricular Module Configuration File
 *
 * Put your OpenAI API Key and Firebase API keys inside this file.
 * Path: modules/cocurricular/config/cocurricular-config.php
 */
declare(strict_types=1);

// --------------------------------------------------------------------------
// 1. OpenAI API Configuration (GPT-4.1)
// --------------------------------------------------------------------------
if (!defined('OPENAI_API_KEY')) {
    define('OPENAI_API_KEY', 'YOUR_OPENAI_API_KEY_HERE');
}

if (!defined('COCURRICULAR_OPENAI_MODEL')) {
    define('COCURRICULAR_OPENAI_MODEL', 'gpt-4.1');
}

// --------------------------------------------------------------------------
// 2. Firebase Public Client Configuration (Web Push SDK)
// --------------------------------------------------------------------------
if (!defined('FIREBASE_API_KEY')) {
    define('FIREBASE_API_KEY', 'AIzaSyBBxVoH7mLn_u1xZwTywNczCdkMLfpXQqc');
}

if (!defined('FIREBASE_AUTH_DOMAIN')) {
    define('FIREBASE_AUTH_DOMAIN', 'co-curricular-management-ed6aa.firebaseapp.com');
}

if (!defined('FIREBASE_PROJECT_ID')) {
    define('FIREBASE_PROJECT_ID', 'co-curricular-management-ed6aa');
}

if (!defined('FIREBASE_STORAGE_BUCKET')) {
    define('FIREBASE_STORAGE_BUCKET', 'co-curricular-management-ed6aa.firebasestorage.app');
}

if (!defined('FIREBASE_MESSAGING_SENDER_ID')) {
    define('FIREBASE_MESSAGING_SENDER_ID', '982710359986');
}

if (!defined('FIREBASE_APP_ID')) {
    define('FIREBASE_APP_ID', '1:982710359986:web:bad1f738d2a5577bd0f6ab');
}

if (!defined('FIREBASE_VAPID_KEY')) {
    define('FIREBASE_VAPID_KEY', 'BAOUkvB4IfiTZltQ-Luku6yKX6BlEOFva_jFmHbDxJ2MB36MBL5uGjOH3xeIbIlIU6nyh6PtY6OIum3kA_SLQXM');
}

// --------------------------------------------------------------------------
// 3. Firebase Server-Side Service Account Credentials (FCM HTTP v1 Dispatcher)
// --------------------------------------------------------------------------
if (!defined('FIREBASE_SERVICE_ACCOUNT_PATH')) {
    define('FIREBASE_SERVICE_ACCOUNT_PATH', __DIR__ . '/firebase-service-account.json');
}
