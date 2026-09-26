<?php
/**
 * Test Suite for Faculty Adviser Events Table Action Button Alignment
 */
declare(strict_types=1);

$baseDir = 'c:/xampp/htdocs/sms2-capstone';
require_once $baseDir . '/config/config.php';
require_once $baseDir . '/config/database.php';
require_once $baseDir . '/modules/cocurricular/includes/cocurricular-db.php';

$pdo = cocurricularDb();
$mainDb = db();

if (!$pdo || !$mainDb) {
    echo "ERROR: Database connection failed.\n";
    exit(1);
}

$testResults = [];
$cleanupClubIds = [];
$cleanupEventIds = [];
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

function makeCurl(string $path, string $sessId, string $method = 'GET', $data = null, array $headers = []): array {
    $url = 'http://localhost/sms2-capstone/' . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, "SMS2SESSID=$sessId");
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } else if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    
    return [
        'status' => $status,
        'body' => (string) $body,
        'contentType' => $contentType,
        'json' => json_decode((string) $body, true)
    ];
}

echo "====================================================================\n";
echo " TEST SUITE: FACULTY ADVISER EVENTS ACTION BUTTON ALIGNMENT\n";
echo "====================================================================\n\n";

try {
    $facultyId = 53; // Dr. Roberto M. Santos
    $randSuffix = bin2hex(random_bytes(4));
    $clubName = 'Test Alignment Club ' . $randSuffix;

    // Create temporary club
    $stmt = $pdo->prepare('INSERT INTO clubs (club_name, description, category, status, adviser, adviser_id, adviser_email) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$clubName, 'Testing Event Button Alignment', 'Academic', 'Active', 'Dr. Roberto M. Santos', $facultyId, 'roberto@bcp.edu.ph']);
    $clubId = (int) $pdo->lastInsertId();
    $cleanupClubIds[] = $clubId;

    // Create 3 Events with different statuses
    // 1. Published event (all actions: Participants, Edit, Attendance, Cancel)
    $stmtEvent = $pdo->prepare('INSERT INTO club_events (club_id, title, description, event_type, event_date, start_time, end_time, venue, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmtEvent->execute([$clubId, 'Annual General Meeting ' . $randSuffix, 'Description 1', 'General Meeting', date('Y-m-d', strtotime('+3 days')), '09:00:00', '12:00:00', 'Auditorium', 'Published', $facultyId]);
    $event1_id = (int) $pdo->lastInsertId();
    $cleanupEventIds[] = $event1_id;

    // 2. Draft event (actions: Participants, Edit, Publish, Cancel)
    $stmtEvent->execute([$clubId, 'Hackathon Workshop ' . $randSuffix, 'Description 2', 'Workshop', date('Y-m-d', strtotime('+7 days')), '13:00:00', '17:00:00', 'Lab 3', 'Draft', $facultyId]);
    $event2_id = (int) $pdo->lastInsertId();
    $cleanupEventIds[] = $event2_id;

    // 3. Completed event (actions: Participants, Edit)
    $stmtEvent->execute([$clubId, 'Past Leadership Seminar ' . $randSuffix, 'Description 3', 'Seminar', date('Y-m-d', strtotime('-5 days')), '10:00:00', '14:00:00', 'Main Hall', 'Completed', $facultyId]);
    $event3_id = (int) $pdo->lastInsertId();
    $cleanupEventIds[] = $event3_id;

    // 4. Pending / In-Review event (actions: Participants, Edit, [Empty spacer slot 3], Cancel)
    $stmtEvent->execute([$clubId, 'Orientation Day ' . $randSuffix, 'Description 4', 'Orientation', date('Y-m-d', strtotime('+10 days')), '08:00:00', '11:00:00', 'Gymnasium', 'In-Review', $facultyId]);
    $event4_id = (int) $pdo->lastInsertId();
    $cleanupEventIds[] = $event4_id;

    // Make faculty session
    $facultySess = makeSessionFile($facultyId, 'adviser', 'Research Adviser');

    // Request events tab in Faculty Adviser workspace
    $res = makeCurl("modules/cocurricular/pages/faculty-adviser.php?club_id={$clubId}&tab=events", $facultySess['sessionId']);

    recordTest('HTTP status 200', $res['status'] === 200, "Status: {$res['status']}");
    recordTest('Page contains Events tab', strpos($res['body'], 'Club Activities & Events') !== false);
    recordTest('Page links Font Awesome stylesheet', strpos($res['body'], 'assets/vendor/fontawesome/css/all.min.css') !== false);
    recordTest('Actions table header uses event-actions-column class', strpos($res['body'], 'class="text-end event-actions-column"') !== false);
    recordTest('Actions table cell uses event-actions-cell class', strpos($res['body'], 'class="text-end event-actions-cell"') !== false);
    recordTest('Action group container uses event-actions class', strpos($res['body'], 'class="event-actions"') !== false);
    
    // Participants Action
    recordTest('Participants button uses event-action-participants class', strpos($res['body'], 'event-action-participants') !== false);
    recordTest('Participants button has aria-label for accessibility', strpos($res['body'], 'aria-label="View Participants"') !== false);
    recordTest('Participants button has title for accessibility', strpos($res['body'], 'title="View Participants"') !== false);
    recordTest('Participants button is icon-only (fa-users)', strpos($res['body'], '<i class="fas fa-users" aria-hidden="true"></i>') !== false);
    
    // Edit Action
    recordTest('Edit button uses event-action-edit class', strpos($res['body'], 'event-action-edit') !== false);
    recordTest('Edit button has aria-label for accessibility', strpos($res['body'], 'aria-label="Edit Event Details"') !== false);
    recordTest('Edit button has title for accessibility', strpos($res['body'], 'title="Edit Event Details"') !== false);
    recordTest('Edit button is icon-only (fa-edit)', strpos($res['body'], '<i class="fas fa-edit" aria-hidden="true"></i>') !== false);
    
    // Attendance Action
    recordTest('Attendance button uses event-action-attendance class', strpos($res['body'], 'event-action-attendance') !== false);
    recordTest('Attendance button has aria-label for accessibility', strpos($res['body'], 'aria-label="Manage Attendance"') !== false);
    recordTest('Attendance button has title for accessibility', strpos($res['body'], 'title="Manage Attendance"') !== false);
    recordTest('Attendance button is icon-only (fa-calendar-alt)', strpos($res['body'], '<i class="fas fa-calendar-alt" aria-hidden="true"></i>') !== false);
    
    // Publish Action
    recordTest('Publish button uses event-action-publish class', strpos($res['body'], 'event-action-publish') !== false);
    recordTest('Publish button has aria-label for accessibility', strpos($res['body'], 'aria-label="Publish Event"') !== false);
    recordTest('Publish button has title for accessibility', strpos($res['body'], 'title="Publish Event"') !== false);
    recordTest('Publish button is icon-only (fa-calendar-check)', strpos($res['body'], '<i class="fas fa-calendar-check" aria-hidden="true"></i>') !== false);
    
    // Cancel Action
    recordTest('Cancel button uses event-action-cancel class', strpos($res['body'], 'event-action-cancel') !== false);
    recordTest('Cancel button has aria-label for accessibility', strpos($res['body'], 'aria-label="Cancel Event"') !== false);
    recordTest('Cancel button has title for accessibility', strpos($res['body'], 'title="Cancel Event"') !== false);
    recordTest('Cancel button is icon-only (fa-trash-alt)', strpos($res['body'], '<i class="fas fa-trash-alt" aria-hidden="true"></i>') !== false);
    
    recordTest('Action form uses event-action-form class', strpos($res['body'], 'class="event-action-form') !== false);
    recordTest('No spacer elements rendered in event actions', strpos($res['body'], 'class="event-action-spacer"') === false);

    // Status Badge verification
    recordTest('Status column uses event-status-badge class', strpos($res['body'], 'class="event-status-badge') !== false);
    recordTest('Published event has event-status-published class', strpos($res['body'], 'event-status-published') !== false);
    recordTest('Draft event has event-status-draft class', strpos($res['body'], 'event-status-draft') !== false);
    recordTest('Completed event has event-status-completed class', strpos($res['body'], 'event-status-completed') !== false);
    recordTest('Invalid/empty status event renders safe event-status-unknown fallback', strpos($res['body'], 'event-status-unknown') !== false && strpos($res['body'], 'Unknown') !== false);
    recordTest('No empty badge dots in output', strpos($res['body'], '<span class="badge bg-dark"></span>') === false && strpos($res['body'], '<span class="event-status-badge"></span>') === false);

    // CSS file verification
    $cssContent = file_get_contents($baseDir . '/modules/cocurricular/assets/css/cocurricular.css');
    recordTest('CSS contains .event-actions flex rules', strpos($cssContent, 'display: flex') !== false);
    recordTest('CSS sets gap: 8px in .event-actions', strpos($cssContent, 'gap: 8px') !== false);
    recordTest('CSS sets justify-content: flex-end in .event-actions', strpos($cssContent, 'justify-content: flex-end') !== false);
    recordTest('CSS sets 40px width and height on square action buttons', strpos($cssContent, 'width: 40px') !== false && strpos($cssContent, 'height: 40px') !== false);
    recordTest('CSS sets 40px on square edit icon button', strpos($cssContent, '.event-action-edit') !== false && strpos($cssContent, 'width: 40px') !== false);
    recordTest('CSS centers action button icons', strpos($cssContent, '.event-actions .event-action-btn i') !== false && strpos($cssContent, 'justify-content: center') !== false);
    recordTest('CSS defines .event-status-badge styling', strpos($cssContent, '.event-status-badge') !== false && strpos($cssContent, 'border-radius: 999px') !== false && strpos($cssContent, 'padding: 4px 10px') !== false);
    recordTest('CSS defines .event-status-published', strpos($cssContent, '.event-status-published') !== false);
    recordTest('CSS defines .event-status-draft', strpos($cssContent, '.event-status-draft') !== false);
    recordTest('CSS defines .event-status-pending', strpos($cssContent, '.event-status-pending') !== false);
    recordTest('CSS defines .event-status-cancelled', strpos($cssContent, '.event-status-cancelled') !== false);
    recordTest('CSS defines .event-status-completed', strpos($cssContent, '.event-status-completed') !== false);
    recordTest('CSS defines .event-status-unknown fallback', strpos($cssContent, '.event-status-unknown') !== false);
    recordTest('CSS sets vertical-align: middle on table cells', strpos($cssContent, 'vertical-align: middle') !== false);

    // No PHP notices or warnings
    $hasWarnings = strpos($res['body'], '<b>Notice</b>') !== false
        || strpos($res['body'], '<b>Warning</b>') !== false
        || strpos($res['body'], '<b>Fatal error</b>') !== false;
    recordTest('No PHP warnings or notices in output', !$hasWarnings);

} finally {
    echo "\n--- Cleaning up temporary test records ---\n";
    if (!empty($cleanupEventIds)) {
        $pdo->query('DELETE FROM club_events WHERE id IN (' . implode(',', $cleanupEventIds) . ')');
    }
    if (!empty($cleanupClubIds)) {
        $pdo->query('DELETE FROM clubs WHERE id IN (' . implode(',', $cleanupClubIds) . ')');
    }
    foreach ($cleanupSessionFiles as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
    echo "Cleaned up all temporary events, clubs, and session files.\n";
}

echo "\n====================================================================\n";
echo " SUMMARY\n";
echo "====================================================================\n";
$totalTests = count($testResults);
$passedTests = count(array_filter($testResults, fn($t) => $t['passed']));
$failedTests = $totalTests - $passedTests;
echo "Total Tests: {$totalTests}\n";
echo "Passed:      {$passedTests}\n";
echo "Failed:      {$failedTests}\n";

if ($failedTests === 0) {
    echo "\n\033[32mALL EVENT ACTION BUTTON ALIGNMENT TESTS PASSED!\033[0m\n";
    exit(0);
} else {
    echo "\n\033[31mSOME TESTS FAILED!\033[0m\n";
    exit(1);
}
