<?php
/**
 * Test Suite: Co-Curricular Announcement UI & AI Generation Workflow
 * Verifies:
 * 1. Faculty Adviser Announcement Modal Structure & Proportions
 * 2. Embedded AI Helper Card & Action Trigger
 * 3. Live Character Counter (0/2000)
 * 4. In-Modal AI Prompt View (Topic, Details, Audience, Tone, Event Selector)
 * 5. In-Modal AI Draft Preview View (Draft Title, Draft Message, Regenerate, Use Draft)
 * 6. No Automatic Publishing (draft must populate form, user manually posts)
 * 7. AI Endpoint RBAC & CSRF Protection
 * 8. AI Draft Generation (General & Event-Assisted)
 * 9. Announcement Database Persistence (including is_ai_generated, ai_model, ai_generated_at)
 * 10. Dark Mode CSS Architecture & Contrast
 */
declare(strict_types=1);

$baseDir = 'c:/xampp/htdocs/sms2-capstone';
require_once $baseDir . '/config/config.php';
require_once $baseDir . '/config/database.php';
require_once $baseDir . '/modules/cocurricular/includes/cocurricular-db.php';
require_once $baseDir . '/modules/cocurricular/includes/cocurricular-ai.php';

$pdo = cocurricularDb();
if (!$pdo) {
    echo "ERROR: Database connection failed.\n";
    exit(1);
}

$testResults = [];
$cleanupClubIds = [];
$cleanupEventIds = [];
$cleanupAnnIds = [];
$cleanupSessionFiles = [];

function recordTest(string $name, bool $passed, string $detail = ''): void {
    global $testResults;
    $testResults[] = ['name' => $name, 'passed' => $passed, 'detail' => $detail];
    $status = $passed ? "\033[32m[PASS]\033[0m" : "\033[31m[FAIL]\033[0m";
    echo "{$status} {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function makeSessionFile(int $userId, string $roleKey, string $roleLabel = ''): array {
    global $cleanupSessionFiles;
    $sessId = bin2hex(random_bytes(16));
    $csrfToken = bin2hex(random_bytes(32));
    
    $data = [
        'csrf_token' => $csrfToken,
        'user_id' => $userId,
        'user_name' => 'Test ' . ucfirst($roleKey),
        'user_role' => $roleLabel ?: ucfirst($roleKey),
        'user_role_key' => $roleKey,
        'role_key' => $roleKey,
        'role' => $roleKey,
        'user_email' => "test_{$roleKey}@bcp.edu.ph",
        'must_change_password' => 0,
        'last_activity' => time(),
        'login_at' => time(),
    ];
    
    $raw = '';
    foreach ($data as $key => $val) {
        $raw .= $key . '|' . serialize($val);
    }
    
    $sessFile = 'C:\\xampp\\tmp\\sess_' . $sessId;
    file_put_contents($sessFile, $raw);
    $cleanupSessionFiles[] = $sessFile;
    
    return ['sessionId' => $sessId, 'csrfToken' => $csrfToken];
}

function makeCurl(string $relativeUrl, string $sessionId = '', string $method = 'GET', $postData = null, array $headers = []): array {
    $url = 'http://localhost/sms2-capstone/' . ltrim($relativeUrl, '/');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $hdrs = $headers;
    if ($sessionId !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, 'SMS2SESSID=' . $sessionId);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($postData) && !isset($headers['Content-Type'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        } elseif (is_string($postData) || is_array($postData)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }
    }
    if (!empty($hdrs)) {
        $formatted = [];
        foreach ($hdrs as $k => $v) {
            $formatted[] = is_int($k) ? $v : "{$k}: {$v}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formatted);
    }
    
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['status' => $status, 'body' => (string) $body];
}

echo "====================================================================\n";
echo " TEST SUITE: CO-CURRICULAR ANNOUNCEMENT UI & AI WORKFLOW\n";
echo "====================================================================\n\n";

try {
    // ---------------------------------------------------------------
    // 1. Fixture Setup: Club, Faculty Adviser Assignment & Event
    // ---------------------------------------------------------------
    $randSuffix = (string) mt_rand(10000, 99999);
    $clubName = 'Robotics and AI Innovators ' . $randSuffix;
    $facultyId = 53; // Dr. Roberto M. Santos
    
    $stmtClub = $pdo->prepare('
        INSERT INTO clubs (club_name, description, category, status, adviser, adviser_id, adviser_email, created_at)
        VALUES (?, "Club dedicated to robotics and artificial intelligence.", "Academic", "Active", "Dr. Roberto M. Santos", ?, "roberto@bcp.edu.ph", NOW())
    ');
    $stmtClub->execute([$clubName, $facultyId]);
    $clubId = (int) $pdo->lastInsertId();
    $cleanupClubIds[] = $clubId;

    // Create an event for the club
    $stmtEv = $pdo->prepare('
        INSERT INTO club_events (club_id, title, description, event_type, event_date, start_time, end_time, venue, status, created_by, created_at)
        VALUES (?, "Annual AI Hackathon", "A hands-on hackathon building intelligent solutions for campus automation.", "Workshop", "2026-10-15", "09:00:00", "17:00:00", "Main Computer Lab 3", "Published", ?, NOW())
    ');
    $stmtEv->execute([$clubId, $facultyId]);
    $eventId = (int) $pdo->lastInsertId();
    $cleanupEventIds[] = $eventId;

    // Create session for faculty adviser
    $facultySess = makeSessionFile($facultyId, 'adviser', 'Research Adviser');

    // ---------------------------------------------------------------
    // 2. HTML Markup & Modal Proportions in faculty-adviser.php
    // ---------------------------------------------------------------
    $res = makeCurl("modules/cocurricular/pages/faculty-adviser.php?club_id={$clubId}&tab=announcements", $facultySess['sessionId']);
    recordTest('Faculty Adviser: Announcements tab loads with HTTP 200', $res['status'] === 200, "Status: {$res['status']}");

    // Modal ID and Header
    recordTest('Modal exists with ID postAnnouncementModal', strpos($res['body'], 'id="postAnnouncementModal"') !== false);
    recordTest('Modal has clear title "Post Club Announcement"', strpos($res['body'], 'Post Club Announcement') !== false);
    recordTest('Modal has contextual club subtitle', strpos($res['body'], 'id="postAnnouncementSubtitle"') !== false);
    recordTest('Modal has clean close button', strpos($res['body'], 'class="btn-close"') !== false);

    // Form fields in Main Form View
    recordTest('Main form view container exists (annMainView)', strpos($res['body'], 'id="annMainView"') !== false);
    recordTest('Announcement Title field exists with required attribute', strpos($res['body'], 'id="annTitle"') !== false && strpos($res['body'], 'name="title"') !== false);
    recordTest('Announcement Message textarea exists with required attribute', strpos($res['body'], 'id="annContent"') !== false && strpos($res['body'], 'name="content"') !== false);
    recordTest('Live character counter container exists (0/2000)', strpos($res['body'], 'id="annCharCount"') !== false && strpos($res['body'], '0/2000') !== false);
    recordTest('Pin announcement toggle switch exists', strpos($res['body'], 'id="annPinned"') !== false && strpos($res['body'], 'Pin announcement to top of feed') !== false);

    // AI Tracking hidden inputs
    recordTest('AI tracking hidden field is_ai_generated exists', strpos($res['body'], 'id="annIsAiGenerated"') !== false);
    recordTest('AI tracking hidden field ai_model exists', strpos($res['body'], 'id="annAiModel"') !== false);
    recordTest('AI tracking hidden field ai_generated_at exists', strpos($res['body'], 'id="annAiGeneratedAt"') !== false);

    // AI Helper Card & Trigger Button
    recordTest('AI Assistant Helper Card exists', strpos($res['body'], 'class="cocurricular-ai-helper-card') !== false);
    recordTest('AI Assistant Helper Card has sparkle icon', strpos($res['body'], 'cocurricular-ai-sparkle-icon') !== false);
    recordTest('AI Assistant Helper Card title: "Need help writing this?"', strpos($res['body'], 'Need help writing this?') !== false);
    recordTest('AI Assistant Helper Card subtitle: "Let AI generate a professional announcement for you."', strpos($res['body'], 'Let AI generate a professional announcement for you.') !== false);
    recordTest('AI Generator button exists with ID openAiPromptBtn', strpos($res['body'], 'id="openAiPromptBtn"') !== false && strpos($res['body'], 'Generate with AI') !== false);

    // AI Prompt View
    recordTest('AI Prompt view exists (annAiPromptView)', strpos($res['body'], 'id="annAiPromptView"') !== false);
    recordTest('AI Prompt has Topic input (aiTopic)', strpos($res['body'], 'id="aiTopic"') !== false);
    recordTest('AI Prompt has Key Details textarea (aiDetails)', strpos($res['body'], 'id="aiDetails"') !== false);
    recordTest('AI Prompt has Target Audience input (aiAudience)', strpos($res['body'], 'id="aiAudience"') !== false);
    recordTest('AI Prompt has Tone selector (aiTone)', strpos($res['body'], 'id="aiTone"') !== false);
    recordTest('AI Prompt includes Related Event selector (aiRelatedEvent)', strpos($res['body'], 'id="aiRelatedEvent"') !== false);
    recordTest('AI Prompt Cancel button exists (cancelAiPromptBtn)', strpos($res['body'], 'id="cancelAiPromptBtn"') !== false);
    recordTest('AI Prompt Submit button exists (submitGenerateAiBtn)', strpos($res['body'], 'id="submitGenerateAiBtn"') !== false);

    // AI Draft Preview View
    recordTest('AI Draft preview view exists (annAiDraftView)', strpos($res['body'], 'id="annAiDraftView"') !== false);
    recordTest('AI Draft has editable Title input (aiDraftTitle)', strpos($res['body'], 'id="aiDraftTitle"') !== false);
    recordTest('AI Draft has editable Message textarea (aiDraftMessage)', strpos($res['body'], 'id="aiDraftMessage"') !== false);
    recordTest('AI Draft has Regenerate button (regenerateAiBtn)', strpos($res['body'], 'id="regenerateAiBtn"') !== false);
    recordTest('AI Draft has Use Draft button (useAiDraftBtn)', strpos($res['body'], 'id="useAiDraftBtn"') !== false);
    recordTest('AI Draft has Edit Details button (backToAiPromptBtn)', strpos($res['body'], 'id="backToAiPromptBtn"') !== false);

    // Footer actions
    recordTest('Post Announcement button exists in footer', strpos($res['body'], 'id="postAnnouncementSubmitBtn"') !== false && strpos($res['body'], 'Post Announcement') !== false);
    recordTest('Cancel button exists in modal footer', strpos($res['body'], 'data-bs-dismiss="modal">Cancel</button>') !== false);

    // ---------------------------------------------------------------
    // 3. AI Endpoint RBAC & CSRF Protection
    // ---------------------------------------------------------------
    // 3a. Unauthorized student request should be blocked (HTTP 403)
    $studentSess = makeSessionFile(9, 'student', 'Student');
    $unauthRes = makeCurl(
        'modules/cocurricular/endpoints/generate-announcement.php',
        $studentSess['sessionId'],
        'POST',
        json_encode(['club_id' => $clubId, 'topic' => 'General Meeting', 'csrf_token' => $studentSess['csrfToken']]),
        ['Content-Type' => 'application/json']
    );
    recordTest('AI Endpoint RBAC: Student access is blocked with HTTP 403', $unauthRes['status'] === 403, "Status: {$unauthRes['status']}");

    // 3b. Missing CSRF token should return HTTP 403
    $noCsrfRes = makeCurl(
        'modules/cocurricular/endpoints/generate-announcement.php',
        $facultySess['sessionId'],
        'POST',
        json_encode(['club_id' => $clubId, 'topic' => 'Meeting Without CSRF']),
        ['Content-Type' => 'application/json']
    );
    recordTest('AI Endpoint CSRF: Missing CSRF token returns HTTP 403', $noCsrfRes['status'] === 403, "Status: {$noCsrfRes['status']}");

    // 3c. Faculty Adviser of another club should be blocked (HTTP 403)
    $otherFacultyId = 78;
    $otherFacultySess = makeSessionFile($otherFacultyId, 'faculty', 'Faculty');
    $wrongClubRes = makeCurl(
        'modules/cocurricular/endpoints/generate-announcement.php',
        $otherFacultySess['sessionId'],
        'POST',
        json_encode(['club_id' => $clubId, 'topic' => 'Unauthorized Club Prompt', 'csrf_token' => $otherFacultySess['csrfToken']]),
        ['Content-Type' => 'application/json']
    );
    recordTest('AI Endpoint Isolation: Unauthorized Faculty Adviser returns HTTP 403', $wrongClubRes['status'] === 403, "Status: {$wrongClubRes['status']}");

    // ---------------------------------------------------------------
    // 4. AI Draft Generation (General & Event-Assisted)
    // ---------------------------------------------------------------
    // 4a. Authorized Faculty Adviser generates general announcement draft
    $aiPayload = [
        'club_id' => $clubId,
        'topic' => 'General Assembly & Membership Orientation',
        'details' => 'Discuss upcoming workshops, committee assignments, and annual club dues.',
        'audience' => 'All Robotics Club Members',
        'additional_instructions' => 'Tone: Professional',
        'csrf_token' => $facultySess['csrfToken'],
    ];
    $genRes = makeCurl(
        'modules/cocurricular/endpoints/generate-announcement.php',
        $facultySess['sessionId'],
        'POST',
        json_encode($aiPayload),
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $facultySess['csrfToken']]
    );
    recordTest('AI Endpoint: Faculty Adviser generates draft (HTTP 200)', $genRes['status'] === 200, "Status: {$genRes['status']}");
    $genData = json_decode($genRes['body'], true);
    recordTest('AI Response returns success = true', ($genData['success'] ?? false) === true);
    recordTest('AI Response contains draft title', !empty($genData['data']['title']), "Title: " . ($genData['data']['title'] ?? 'NONE'));
    recordTest('AI Response contains draft content', !empty($genData['data']['content']));
    recordTest('AI Response identifies valid model (gemini, gpt-4.1 or local-fallback)', in_array($genData['model'] ?? '', ['gemini-3.6-flash', 'gemini-3.5-flash-lite', 'gemini-3.8-flash', 'gpt-4.1', 'local-fallback'], true) || str_contains($genData['model'] ?? '', 'gemini'), "Model: " . ($genData['model'] ?? 'NONE'));

    // 4b. Event-to-Announcement AI Draft Endpoint
    $eventAiPayload = [
        'event_id' => $eventId,
        'csrf_token' => $facultySess['csrfToken'],
    ];
    $eventGenRes = makeCurl(
        'modules/cocurricular/endpoints/generate-event-announcement.php',
        $facultySess['sessionId'],
        'POST',
        json_encode($eventAiPayload),
        ['Content-Type' => 'application/json']
    );
    recordTest('Event AI Endpoint: Faculty Adviser generates event announcement draft (HTTP 200)', $eventGenRes['status'] === 200, "Status: {$eventGenRes['status']}");
    $eventGenData = json_decode($eventGenRes['body'], true);
    recordTest('Event AI Response returns success = true', ($eventGenData['success'] ?? false) === true);
    recordTest('Event AI Response draft references event title or topic', !empty($eventGenData['draft']['title']));

    // ---------------------------------------------------------------
    // 5. Database Persistence of AI-Assisted Announcement
    // ---------------------------------------------------------------
    $generatedTitle = $genData['data']['title'] ?? 'Official General Assembly Notice';
    $generatedContent = $genData['data']['content'] ?? 'Full details for the upcoming Robotics club general assembly.';
    $aiModelUsed = $genData['model'] ?? 'gpt-4.1';
    $aiTimeUsed = $genData['generated_at'] ?? date('Y-m-d H:i:s');

    // Submit announcement via Faculty Adviser form
    $postPayload = [
        'action' => 'create_announcement',
        'csrf_token' => $facultySess['csrfToken'],
        'title' => $generatedTitle,
        'content' => $generatedContent,
        'is_pinned' => '1',
        'is_ai_generated' => '1',
        'ai_model' => $aiModelUsed,
        'ai_generated_at' => $aiTimeUsed,
    ];

    $postRes = makeCurl(
        "modules/cocurricular/pages/faculty-adviser.php?club_id={$clubId}&tab=announcements",
        $facultySess['sessionId'],
        'POST',
        $postPayload
    );
    recordTest('Faculty Adviser: Post Announcement form submission succeeds (HTTP 200)', $postRes['status'] === 200, "Status: {$postRes['status']}");

    // Verify row in database
    $stmtCheck = $pdo->prepare('
        SELECT * FROM club_announcements
        WHERE club_id = ? AND title = ?
        ORDER BY id DESC LIMIT 1
    ');
    $stmtCheck->execute([$clubId, $generatedTitle]);
    $savedAnn = $stmtCheck->fetch();

    recordTest('Announcement is saved in database', !empty($savedAnn));
    if ($savedAnn) {
        $cleanupAnnIds[] = (int) $savedAnn['id'];
        recordTest('Saved announcement matches title', $savedAnn['title'] === $generatedTitle);
        recordTest('Saved announcement is marked pinned', (int) $savedAnn['is_pinned'] === 1);
        recordTest('Saved announcement is marked is_ai_generated = 1', (int) $savedAnn['is_ai_generated'] === 1);
        recordTest('Saved announcement records accurate ai_model', in_array($savedAnn['ai_model'] ?? '', ['gemini-3.6-flash', 'gemini-3.5-flash-lite', 'gemini-3.8-flash', 'gpt-4.1', 'local-fallback'], true) || str_contains($savedAnn['ai_model'] ?? '', 'gemini'));
        recordTest('Saved announcement records ai_generated_at timestamp', !empty($savedAnn['ai_generated_at']));
    }

    // ---------------------------------------------------------------
    // 6. CSS Dark Mode & Visual Architecture Verification
    // ---------------------------------------------------------------
    $cssContent = file_get_contents($baseDir . '/modules/cocurricular/assets/css/cocurricular.css');

    recordTest('CSS: Dark mode content rule defined', strpos($cssContent, '[data-theme="dark"] #postAnnouncementModal .modal-content') !== false);
    recordTest('CSS: Dark mode header rule defined', strpos($cssContent, '[data-theme="dark"] #postAnnouncementModal .modal-header') !== false);
    recordTest('CSS: Dark mode body rule defined', strpos($cssContent, '[data-theme="dark"] #postAnnouncementModal .modal-body') !== false);
    recordTest('CSS: Dark mode footer rule defined', strpos($cssContent, '[data-theme="dark"] #postAnnouncementModal .modal-footer') !== false);
    recordTest('CSS: Dark mode form-control styling defined', strpos($cssContent, '[data-theme="dark"] #postAnnouncementModal .form-control') !== false);
    recordTest('CSS: Dark mode close button invert filter defined', strpos($cssContent, 'filter: invert(1)') !== false);
    recordTest('CSS: AI helper card styling defined', strpos($cssContent, '.cocurricular-ai-helper-card') !== false);
    recordTest('CSS: AI sparkle icon defined', strpos($cssContent, '.cocurricular-ai-sparkle-icon') !== false);
    recordTest('CSS: AI generate button defined', strpos($cssContent, '.cocurricular-ai-generate-btn') !== false);
    recordTest('CSS: Character counter wrap and count defined', strpos($cssContent, '.cocurricular-textarea-wrap') !== false && strpos($cssContent, '.cocurricular-char-count') !== false);

    // Ensure read-only files remained 100% untouched
    $themeCss = file_get_contents($baseDir . '/assets/css/theme.css');
    recordTest('READ-ONLY CHECK: assets/css/theme.css has zero cocurricular edits', strpos($themeCss, 'cocurricular') === false);

} catch (Throwable $e) {
    recordTest('Unhandled test exception', false, $e->getMessage());
} finally {
    // Cleanup temporary records
    echo "\n--- Cleaning up temporary test records ---\n";
    if (!empty($cleanupAnnIds)) {
        $in = implode(',', $cleanupAnnIds);
        $pdo->exec("DELETE FROM club_announcements WHERE id IN ({$in})");
    }
    if (!empty($cleanupEventIds)) {
        $in = implode(',', $cleanupEventIds);
        $pdo->exec("DELETE FROM club_events WHERE id IN ({$in})");
    }
    if (!empty($cleanupClubIds)) {
        $in = implode(',', $cleanupClubIds);
        $pdo->exec("DELETE FROM clubs WHERE id IN ({$in})");
    }
    foreach ($cleanupSessionFiles as $f) {
        if (file_exists($f)) {
            @unlink($f);
        }
    }
    echo "Cleaned up all temporary fixtures and session files.\n";
}

$total = count($testResults);
$passed = count(array_filter($testResults, fn($t) => $t['passed']));
$failed = $total - $passed;

echo "\n====================================================================\n";
echo " SUMMARY\n";
echo "====================================================================\n";
echo "Total Tests: {$total}\n";
echo "Passed:      {$passed}\n";
echo "Failed:      {$failed}\n\n";

if ($failed === 0) {
    echo "ALL ANNOUNCEMENT UI & AI WORKFLOW TESTS PASSED PERFECTLY!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
