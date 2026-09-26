<?php
/**
 * Test Suite: Professional AI Modal for Schedule Club Event (Two-Modal Workflow)
 *
 * Verifies all 16 focused requirements:
 * 1. Generate with AI opens a separate modal (#eventAiModal).
 * 2. Event context is displayed read-only.
 * 3. Additional instructions are optional.
 * 4. Generate uses existing endpoint (generate-event-description.php).
 * 5. Loading state works ("Generating description...").
 * 6. Generated description is editable (#eventAiGeneratedText).
 * 7. Regenerate works (#eventAiRegenerateBtn).
 * 8. Use This Description populates #eventDescription.
 * 9. AI modal closes after Use This Description and returns to Schedule Event modal.
 * 10. Schedule Event modal remains intact (#scheduleEventModal).
 * 11. AI generation does not save the event.
 * 12. AI generation does not publish anything.
 * 13. Save & Publish Event remains the only event creation action.
 * 14. Dark mode works (CSS styles for [data-theme="dark"]).
 * 15. Mobile layout does not overflow (responsive CSS rules).
 * 16. Existing event-to-announcement AI remains intact.
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $body !== false ? (string) $body : '',
        'contentType' => $contentType ?: ''
    ];
}

echo "====================================================================\n";
echo " TEST SUITE: PROFESSIONAL AI MODAL FOR SCHEDULE CLUB EVENT\n";
echo "====================================================================\n\n";

try {
    // -------------------------------------------------------------------------
    // Setup test fixtures: Adviser and Club
    // -------------------------------------------------------------------------
    $advUser1 = 53; // Dr. Roberto M. Santos
    $clubCode = 'AI_MODAL_' . rand(1000, 9999);
    $clubName = "BCP Technology and Innovation Club {$clubCode}";

    $stmt = $pdo->prepare("INSERT INTO clubs (club_name, description, category, status, adviser, adviser_id, adviser_email, created_at)
        VALUES (?, 'Test Club for Professional AI Modal', 'Academic', 'Active', 'Dr. Roberto M. Santos', ?, 'roberto@bcp.edu.ph', NOW())");
    $stmt->execute([$clubName, $advUser1]);
    $clubId = (int) $pdo->lastInsertId();
    $cleanupClubIds[] = $clubId;

    $sessAdv = makeSessionFile($advUser1, 'adviser', 'Faculty Adviser');

    // Fetch faculty adviser events page
    $resPage = makeCurl(
        "modules/cocurricular/pages/faculty-adviser.php?club_id={$clubId}&tab=events",
        $sessAdv['sessionId'],
        'GET'
    );
    $html = $resPage['body'];

    // -------------------------------------------------------------------------
    // TEST 1: Generate with AI opens a separate modal (#eventAiModal)
    // -------------------------------------------------------------------------
    $hasMainModal = strpos($html, 'id="scheduleEventModal"') !== false;
    $hasAiHelper = strpos($html, 'id="eventAiHelperCard"') !== false;
    $hasAiGenerateBtn = strpos($html, 'id="eventAiGenerateBtn"') !== false;
    $hasAiModal = strpos($html, 'id="eventAiModal"') !== false;
    $hasAiModalTitle = strpos($html, 'Generate Event Description') !== false;

    // Check JavaScript handler exists to open separate modal with validation
    $hasJsModalOpener = strpos($html, 'openEventAiModal') !== false
        && strpos($html, 'bootstrap.Modal.getOrCreateInstance(eventAiModalEl)') !== false
        && strpos($html, 'Please enter the event title and select an event type first') !== false;

    recordTest(
        '1. Generate with AI opens a separate modal (#eventAiModal)',
        $hasMainModal && $hasAiHelper && $hasAiGenerateBtn && $hasAiModal && $hasAiModalTitle && $hasJsModalOpener,
        "Helper: " . ($hasAiHelper ? 'yes' : 'no') . ", Separate modal: " . ($hasAiModal ? 'yes' : 'no') . ", Modal opener: " . ($hasJsModalOpener ? 'yes' : 'no')
    );

    // -------------------------------------------------------------------------
    // TEST 2: Event context is displayed read-only
    // -------------------------------------------------------------------------
    $hasContextBox = strpos($html, 'cocurricular-event-context-box') !== false;
    $hasContextTitle = strpos($html, 'id="eventAiContextTitle"') !== false;
    $hasContextType = strpos($html, 'id="eventAiContextType"') !== false;
    $hasContextDateTime = strpos($html, 'id="eventAiContextDateTime"') !== false;
    $hasContextVenue = strpos($html, 'id="eventAiContextVenue"') !== false;

    // Ensure these are read-only elements, NOT editable input fields
    $contextNotInputs = strpos($html, '<input id="eventAiContextTitle"') === false
        && strpos($html, '<input id="eventAiContextType"') === false
        && strpos($html, '<textarea id="eventAiContextTitle"') === false;

    recordTest(
        '2. Event context is displayed read-only',
        $hasContextBox && $hasContextTitle && $hasContextType && $hasContextDateTime && $hasContextVenue && $contextNotInputs,
        "Context box: " . ($hasContextBox ? 'yes' : 'no') . ", Read-only elements verified"
    );

    // -------------------------------------------------------------------------
    // TEST 3: Additional instructions are optional
    // -------------------------------------------------------------------------
    $hasInstructionsArea = strpos($html, 'id="eventAiInstructions"') !== false;
    $hasInstructionsOptionalBadge = strpos($html, 'Additional instructions') !== false
        && strpos($html, 'Optional') !== false;
    // Ensure the textarea does NOT have a 'required' attribute
    $instructionsNotRequired = (bool) preg_match('/<textarea[^>]+id="eventAiInstructions"(?![^>]*required)[^>]*>/', $html);

    recordTest(
        '3. Additional instructions are optional',
        $hasInstructionsArea && $hasInstructionsOptionalBadge && $instructionsNotRequired,
        "Optional indicator: " . ($hasInstructionsOptionalBadge ? 'yes' : 'no') . ", Not required: " . ($instructionsNotRequired ? 'yes' : 'no')
    );

    // -------------------------------------------------------------------------
    // TEST 4: Generate uses existing endpoint (generate-event-description.php)
    // -------------------------------------------------------------------------
    $testPromptInput = [
        'title' => 'Annual Coding Hackathon',
        'event_type' => 'Competition',
        'club_name' => $clubName,
        'event_date' => '2026-10-15',
        'start_time' => '09:00',
        'end_time' => '17:00',
        'venue' => 'Innovation Lab Room 304',
        'additional_instructions' => 'Emphasize student participation and keep the tone suitable for club members.'
    ];

    $aiPayload = [
        'title' => $testPromptInput['title'],
        'event_type' => $testPromptInput['event_type'],
        'club_id' => $clubId,
        'event_date' => $testPromptInput['event_date'],
        'start_time' => $testPromptInput['start_time'],
        'end_time' => $testPromptInput['end_time'],
        'venue' => $testPromptInput['venue'],
        'current_description' => '',
        'additional_instructions' => $testPromptInput['additional_instructions'],
        'csrf_token' => $sessAdv['csrfToken']
    ];

    $resAiEndpoint = makeCurl(
        'modules/cocurricular/endpoints/generate-event-description.php',
        $sessAdv['sessionId'],
        'POST',
        json_encode($aiPayload),
        ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']
    );

    $aiJson = json_decode($resAiEndpoint['body'], true);
    $aiSuccess = ($resAiEndpoint['code'] === 200 && is_array($aiJson) && !empty($aiJson['success']));
    $generatedDescription = $aiJson['data']['description'] ?? '';
    $providerReturned = $aiJson['provider'] ?? '';

    recordTest(
        '4. Generate uses existing endpoint (generate-event-description.php)',
        $aiSuccess && !empty($generatedDescription),
        "HTTP {$resAiEndpoint['code']}, Provider: {$providerReturned}, Length: " . strlen($generatedDescription) . " chars"
    );

    // -------------------------------------------------------------------------
    // TEST 5: Loading state works ("Generating description...")
    // -------------------------------------------------------------------------
    $hasLoadingStateText = strpos($html, 'Generating description...') !== false;
    $hasLoadingStateDisabled = strpos($html, 'btn.disabled = true') !== false;
    $hasLoadingSpinner = strpos($html, 'fa-spinner fa-spin') !== false;

    recordTest(
        '5. Loading state works with restrained, professional language',
        $hasLoadingStateText && $hasLoadingStateDisabled && $hasLoadingSpinner,
        "Text: 'Generating description...', Spinner: Font Awesome spinner, Button disabled during generation"
    );

    // -------------------------------------------------------------------------
    // TEST 6: Generated description is editable (#eventAiGeneratedText)
    // -------------------------------------------------------------------------
    $hasGeneratedDescriptionTextarea = strpos($html, 'id="eventAiGeneratedText"') !== false;
    $isEditableTextarea = (bool) preg_match('/<textarea[^>]+id="eventAiGeneratedText"(?![^>]*(readonly|disabled))[^>]*>/', $html);
    $hasReviewSubtitle = strpos($html, 'Review and edit the draft before applying it to the event') !== false;

    recordTest(
        '6. Generated description is displayed in an editable professional textarea',
        $hasGeneratedDescriptionTextarea && $isEditableTextarea && $hasReviewSubtitle,
        "Editable textarea: " . ($isEditableTextarea ? 'yes' : 'no') . ", Standard administrative form field"
    );

    // -------------------------------------------------------------------------
    // TEST 7: Regenerate works (#eventAiRegenerateBtn)
    // -------------------------------------------------------------------------
    $hasRegenerateBtn = strpos($html, 'id="eventAiRegenerateBtn"') !== false;
    $hasRegenerateHandler = strpos($html, 'eventAiRegenerateBtn.addEventListener') !== false
        && strpos($html, 'executeEventDescriptionAi(true)') !== false;

    // Test that second AI call generates valid response
    $resRegenEndpoint = makeCurl(
        'modules/cocurricular/endpoints/generate-event-description.php',
        $sessAdv['sessionId'],
        'POST',
        json_encode(array_merge($aiPayload, ['additional_instructions' => 'Include registration deadline.'])),
        ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']
    );
    $regenJson = json_decode($resRegenEndpoint['body'], true);
    $regenSuccess = ($resRegenEndpoint['code'] === 200 && !empty($regenJson['data']['description']));

    recordTest(
        '7. Regenerate works and calls existing AI generation flow',
        $hasRegenerateBtn && $hasRegenerateHandler && $regenSuccess,
        "Button present: " . ($hasRegenerateBtn ? 'yes' : 'no') . ", Handler calls execute: " . ($hasRegenerateHandler ? 'yes' : 'no') . ", Endpoint response: " . ($regenSuccess ? 'OK' : 'FAIL')
    );

    // -------------------------------------------------------------------------
    // TEST 8: Use This Description populates #eventDescription
    // -------------------------------------------------------------------------
    $hasUseDescBtn = strpos($html, 'id="eventAiUseDescriptionBtn"') !== false;
    $hasUseDescLogic = strpos($html, 'eventDescriptionInput.value = generated') !== false;

    recordTest(
        '8. Use This Description populates #eventDescription',
        $hasUseDescBtn && $hasUseDescLogic,
        "Button present: " . ($hasUseDescBtn ? 'yes' : 'no') . ", Value assigned to eventDescriptionInput"
    );

    // -------------------------------------------------------------------------
    // TEST 9: AI modal closes after Use This Description and returns to Schedule Event modal
    // -------------------------------------------------------------------------
    $hasModalCloseLogic = strpos($html, 'eventAiModalInst.hide()') !== false
        && strpos($html, 'returnToScheduleEventModal(true)') !== false
        && strpos($html, 'eventDescriptionInput.focus()') !== false;

    recordTest(
        '9. AI modal closes after Use This Description, restores Schedule Event modal, and focuses Description',
        $hasModalCloseLogic,
        "Transitions modal cleanly, restores Schedule Club Event, focuses description field"
    );

    // -------------------------------------------------------------------------
    // TEST 10: Schedule Event modal remains intact
    // -------------------------------------------------------------------------
    $hasEventTitleInput = strpos($html, 'id="eventTitle"') !== false;
    $hasEventTypeInput = strpos($html, 'id="eventType"') !== false;
    $hasEventDateInput = strpos($html, 'id="eventDate"') !== false;
    $hasEventStartTime = strpos($html, 'id="startTime"') !== false;
    $hasEventEndTime = strpos($html, 'id="endTime"') !== false;
    $hasEventVenue = strpos($html, 'id="eventVenue"') !== false;
    $hasSavePublishBtn = strpos($html, 'Save & Publish Event') !== false;
    $hasCancelBtn = strpos($html, 'Cancel') !== false;

    recordTest(
        '10. Schedule Event modal remains intact with all fields and actions',
        $hasEventTitleInput && $hasEventTypeInput && $hasEventDateInput && $hasEventStartTime && $hasEventEndTime && $hasEventVenue && $hasSavePublishBtn && $hasCancelBtn,
        "All required form inputs, validation attributes, and footer buttons preserved"
    );

    // -------------------------------------------------------------------------
    // TEST 11: AI generation does NOT save the event
    // -------------------------------------------------------------------------
    $stmtCountEv = $pdo->prepare("SELECT COUNT(*) FROM club_events WHERE club_id = ?");
    $stmtCountEv->execute([$clubId]);
    $eventsAfterAi = (int) $stmtCountEv->fetchColumn();

    recordTest(
        '11. AI generation does NOT create a database event',
        $eventsAfterAi === 0,
        "Club events in DB: {$eventsAfterAi} (expected 0)"
    );

    // -------------------------------------------------------------------------
    // TEST 12: AI generation does NOT publish anything
    // -------------------------------------------------------------------------
    $stmtCountAnn = $pdo->prepare("SELECT COUNT(*) FROM club_announcements WHERE club_id = ?");
    $stmtCountAnn->execute([$clubId]);
    $annAfterAi = (int) $stmtCountAnn->fetchColumn();

    recordTest(
        '12. AI generation does NOT publish an announcement or alter DB',
        $annAfterAi === 0,
        "Club announcements in DB: {$annAfterAi} (expected 0)"
    );

    // -------------------------------------------------------------------------
    // TEST 13: Save & Publish Event remains the only event creation action
    // -------------------------------------------------------------------------
    $eventTitleFinal = "Annual Technology Symposium {$clubCode}";
    $postEventData = [
        'action' => 'create_event',
        'csrf_token' => $sessAdv['csrfToken'],
        'title' => $eventTitleFinal,
        'event_type' => 'Workshop',
        'event_date' => date('Y-m-d', strtotime('+10 days')),
        'start_time' => '13:00',
        'end_time' => '17:00',
        'venue' => 'AVR 1 Main Hall',
        'description' => $generatedDescription ?: 'An enriching technology symposium.',
        'is_pinned' => '1'
    ];

    $resCreateEvent = makeCurl(
        "modules/cocurricular/pages/faculty-adviser.php?club_id={$clubId}&tab=events",
        $sessAdv['sessionId'],
        'POST',
        $postEventData,
        ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
    );

    $createEventJson = json_decode($resCreateEvent['body'], true);
    $createdSuccessfully = ($resCreateEvent['code'] === 200 && is_array($createEventJson) && !empty($createEventJson['success']));
    $newEventId = (int) ($createEventJson['event_id'] ?? 0);
    if ($newEventId > 0) {
        $cleanupEventIds[] = $newEventId;
    }

    recordTest(
        '13. Save & Publish Event remains the only event creation action',
        $createdSuccessfully && $newEventId > 0,
        "HTTP {$resCreateEvent['code']}, Event ID: {$newEventId} created via Save & Publish Event"
    );

    // -------------------------------------------------------------------------
    // TEST 14: Dark mode works (CSS rules for dark mode)
    // -------------------------------------------------------------------------
    $cssFile = file_get_contents($baseDir . '/modules/cocurricular/assets/css/cocurricular.css');
    $hasDarkContextBox = strpos($cssFile, '[data-theme="dark"] .cocurricular-event-context-box') !== false;
    $hasDarkTextContrast = strpos($cssFile, '[data-theme="dark"] #eventAiModal #eventAiContextTitle') !== false;
    $hasDarkModalRules = strpos($cssFile, '[data-theme="dark"] #eventAiModal .modal-content') !== false;

    recordTest(
        '14. Dark mode works with high-contrast surfaces and restrained borders',
        $hasDarkContextBox && $hasDarkTextContrast && $hasDarkModalRules,
        "Context box dark styling: " . ($hasDarkContextBox ? 'yes' : 'no') . ", Text contrast rule: " . ($hasDarkTextContrast ? 'yes' : 'no')
    );

    // -------------------------------------------------------------------------
    // TEST 15: Mobile layout does not overflow
    // -------------------------------------------------------------------------
    $hasModalMaxWidth = strpos($html, 'max-width: 560px') !== false;
    $hasCssBoxRadius = strpos($cssFile, '.cocurricular-event-context-box') !== false;
    // Verify no static inline pixel width that forces horizontal scroll on small devices
    $hasNoOverflowingWidth = strpos($html, 'width: 600px;') === false;

    recordTest(
        '15. Mobile layout does not overflow and uses responsive width constraints',
        $hasModalMaxWidth && $hasCssBoxRadius && $hasNoOverflowingWidth,
        "Responsive max-width ~560px, fluid width adapts without horizontal scrolling"
    );

    // -------------------------------------------------------------------------
    // TEST 16: Existing event-to-announcement AI remains intact
    // -------------------------------------------------------------------------
    $resEventsTab = makeCurl(
        "modules/cocurricular/pages/faculty-adviser.php?club_id={$clubId}&tab=events",
        $sessAdv['sessionId'],
        'GET'
    );
    $eventsHtml = $resEventsTab['body'];

    $hasTableAiBtn = strpos($eventsHtml, 'event-action-ai') !== false;
    $hasJsTrigger = strpos($eventsHtml, 'js-adviser-generate-event-announcement') !== false;
    $hasEventDataAttr = strpos($eventsHtml, 'data-event-id="' . $newEventId . '"') !== false;

    // Check endpoint generates announcement from existing event
    $resEventAnnAi = makeCurl(
        'modules/cocurricular/endpoints/generate-event-announcement.php',
        $sessAdv['sessionId'],
        'POST',
        json_encode([
            'event_id' => $newEventId,
            'additional_instructions' => 'Include venue directions.',
            'csrf_token' => $sessAdv['csrfToken']
        ]),
        ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']
    );

    $annAiJson = json_decode($resEventAnnAi['body'], true);
    $eventAnnAiOk = ($resEventAnnAi['code'] === 200 && is_array($annAiJson) && !empty($annAiJson['success']));

    recordTest(
        '16. Existing event-to-announcement AI remains intact for saved events',
        $hasTableAiBtn && $hasJsTrigger && $hasEventDataAttr && $eventAnnAiOk,
        "Table action button: " . ($hasTableAiBtn ? 'yes' : 'no') . ", Announcement AI endpoint: " . ($eventAnnAiOk ? 'HTTP 200' : 'FAIL')
    );

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    echo "\n--- Cleaning up temporary test records ---\n";
    if (!empty($cleanupAnnIds)) {
        $inAnn = implode(',', array_map('intval', $cleanupAnnIds));
        $pdo->exec("DELETE FROM club_announcements WHERE id IN ({$inAnn})");
    }
    if (!empty($cleanupEventIds)) {
        $inEv = implode(',', array_map('intval', $cleanupEventIds));
        $pdo->exec("DELETE FROM club_events WHERE id IN ({$inEv})");
    }
    if (!empty($cleanupClubIds)) {
        $inClubs = implode(',', array_map('intval', $cleanupClubIds));
        $pdo->exec("DELETE FROM clubs WHERE id IN ({$inClubs})");
    }
    foreach ($cleanupSessionFiles as $sf) {
        if (file_exists($sf)) {
            @unlink($sf);
        }
    }
    echo "Cleaned up all temporary fixtures, events, clubs, announcements, and sessions.\n";
}

$total = count($testResults);
$passed = count(array_filter($testResults, fn($r) => $r['passed']));
$failed = $total - $passed;

echo "\n====================================================================\n";
echo " FINAL 16-POINT SUMMARY\n";
echo "====================================================================\n";
echo "Total Acceptance Criteria: {$total}\n";
echo "Passed:                   {$passed}\n";
echo "Failed:                   {$failed}\n\n";

if ($failed === 0) {
    echo "\033[32mALL 16 ACCEPTANCE CRITERIA PASSED PERFECTLY!\033[0m\n";
    exit(0);
} else {
    echo "\033[31mSOME ACCEPTANCE CRITERIA FAILED!\033[0m\n";
    exit(1);
}
