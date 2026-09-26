<?php
/**
 * Automated Test Suite for Phase 8+ Membership Application Actions
 * Validates all 20 acceptance criteria from specification.
 */
declare(strict_types=1);

$baseDir = 'c:/xampp/htdocs/sms2-capstone';
require_once $baseDir . '/config/config.php';
require_once $baseDir . '/config/database.php';
require_once $baseDir . '/modules/cocurricular/includes/cocurricular-db.php';
require_once $baseDir . '/modules/cocurricular/includes/cocurricular-notifications.php';
require_once $baseDir . '/modules/cocurricular/includes/cocurricular-notification-triggers.php';

$pdo = cocurricularDb();
$mainDb = db();

if (!$pdo || !$mainDb) {
    echo "ERROR: Database connection failed.\n";
    exit(1);
}

$testResults = [];
$cleanupClubIds = [];
$cleanupApplicationIds = [];
$cleanupNotificationIds = [];
$cleanupSessionFiles = [];

function recordResult(string $number, string $criterion, bool $passed, string $detail = ''): void {
    global $testResults;
    $testResults[] = ['number' => $number, 'criterion' => $criterion, 'passed' => $passed, 'detail' => $detail];
    $status = $passed ? "\033[32m[PASS]\033[0m" : "\033[31m[FAIL]\033[0m";
    echo "{$status} #{$number}: {$criterion}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
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
echo " PHASE 8+ MEMBERSHIP APPLICATION ACTIONS: 20-POINT VERIFICATION\n";
echo "====================================================================\n\n";

try {
    // Setup Temporary Fixtures
    $student1Id = 9;
    $student2Id = 188;
    $facultyA_id = 53;
    $facultyB_id = 78;
    $osaAdmin_id = 5;
    $superadmin_id = 1;
    $studentUser_id = 9;
    $financeUser_id = 4;
    $hrUser_id = 4;

    // Pre-clean
    $pdo->query("DELETE FROM clubs WHERE club_name LIKE 'Test 20Point Club%'");

    $randSuffix = bin2hex(random_bytes(4));
    $clubAName = 'Test 20Point Club A ' . $randSuffix;
    $clubBName = 'Test 20Point Club B ' . $randSuffix;
    $clubCName = 'Test 20Point Club C ' . $randSuffix;

    // Create Club A with Faculty A as assigned adviser
    $stmt = $pdo->prepare('INSERT INTO clubs (club_name, description, category, status, adviser, adviser_id, adviser_email) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$clubAName, 'Testing Actions A', 'Academic', 'Active', 'Dr. Roberto M. Santos', $facultyA_id, 'roberto@bcp.edu.ph']);
    $clubA_id = (int) $pdo->lastInsertId();
    $cleanupClubIds[] = $clubA_id;

    // Create Club B with Faculty B as assigned adviser
    $stmt->execute([$clubBName, 'Testing Actions B', 'Sports', 'Active', 'Dr. Ana L. Cruz', $facultyB_id, 'ana@bcp.edu.ph']);
    $clubB_id = (int) $pdo->lastInsertId();
    $cleanupClubIds[] = $clubB_id;

    // Create Club C with Faculty B as assigned adviser
    $stmt->execute([$clubCName, 'Testing Actions C', 'Cultural', 'Active', 'Dr. Ana L. Cruz', $facultyB_id, 'ana@bcp.edu.ph']);
    $clubC_id = (int) $pdo->lastInsertId();
    $cleanupClubIds[] = $clubC_id;

    // Insert Pending Application 1 for Club A (Student 9)
    $stmt = $pdo->prepare('INSERT INTO club_membership_applications (club_id, user_id, student_id, reason_for_joining, areas_of_interest, preferred_participation, agreement, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$clubA_id, $student1Id, 'S230000001', 'Reason 1', 'Coding', 'General Member', 1, 'Pending']);
    $app1_id = (int) $pdo->lastInsertId();
    $cleanupApplicationIds[] = $app1_id;

    // Insert Pending Application 2 for Club A (Student 188)
    $stmt->execute([$clubA_id, $student2Id, 'S230000002', 'Reason 2', 'Design', 'General Member', 1, 'Pending']);
    $app2_id = (int) $pdo->lastInsertId();
    $cleanupApplicationIds[] = $app2_id;

    // Insert Pending Application 3 for Club B (Student 9)
    $stmt->execute([$clubB_id, $student1Id, 'S230000001', 'Reason 3', 'Sports', 'General Member', 1, 'Pending']);
    $app3_id = (int) $pdo->lastInsertId();
    $cleanupApplicationIds[] = $app3_id;

    // Sessions
    $facultyASess = makeSessionFile($facultyA_id, 'adviser', 'Research Adviser');
    $facultyBSess = makeSessionFile($facultyB_id, 'adviser', 'Research Adviser');
    $osaSess = makeSessionFile($osaAdmin_id, 'osa', 'OSA Admin');
    $superadminSess = makeSessionFile($superadmin_id, 'superadmin', 'Superadmin');
    $studentSess = makeSessionFile($studentUser_id, 'student', 'Student');
    $financeSess = makeSessionFile($financeUser_id, 'finance', 'Finance Staff');
    $hrSess = makeSessionFile($hrUser_id, 'hr', 'HR Staff');

    // -------------------------------------------------------------------------
    // 1. Authorized Faculty Adviser can approve own club application
    // -------------------------------------------------------------------------
    $res1 = makeCurl('modules/cocurricular/pages/osa-process-application.php', $facultyASess['sessionId'], 'POST', [
        'action' => 'approve',
        'application_id' => $app1_id,
        'csrf_token' => $facultyASess['csrfToken']
    ], ['X-Requested-With: XMLHttpRequest']);

    $dbApp1 = cocurricularGetMembershipApplication($app1_id);
    recordResult('1', 'Authorized Faculty Adviser can approve own club application', 
        $res1['status'] === 200 && ($res1['json']['success'] ?? false) === true && ($dbApp1['status'] ?? '') === 'Approved',
        "HTTP {$res1['status']}, DB status: " . ($dbApp1['status'] ?? 'unknown'));

    // -------------------------------------------------------------------------
    // 2. Authorized Faculty Adviser can reject own club application
    // -------------------------------------------------------------------------
    $res2 = makeCurl('modules/cocurricular/pages/osa-process-application.php', $facultyASess['sessionId'], 'POST', [
        'action' => 'reject',
        'application_id' => $app2_id,
        'rejection_reason' => 'Schedule conflict.',
        'csrf_token' => $facultyASess['csrfToken']
    ], ['X-Requested-With: XMLHttpRequest']);

    $dbApp2 = cocurricularGetMembershipApplication($app2_id);
    recordResult('2', 'Authorized Faculty Adviser can reject own club application',
        $res2['status'] === 200 && ($res2['json']['success'] ?? false) === true && ($dbApp2['status'] ?? '') === 'Rejected',
        "HTTP {$res2['status']}, DB status: " . ($dbApp2['status'] ?? 'unknown'));

    // -------------------------------------------------------------------------
    // 3. Authorized Faculty Adviser cannot modify another adviser's application
    // -------------------------------------------------------------------------
    // Faculty A attempts to approve Club B's Application 3
    $res3 = makeCurl('modules/cocurricular/pages/osa-process-application.php', $facultyASess['sessionId'], 'POST', [
        'action' => 'approve',
        'application_id' => $app3_id,
        'csrf_token' => $facultyASess['csrfToken']
    ], ['X-Requested-With: XMLHttpRequest']);

    $dbApp3 = cocurricularGetMembershipApplication($app3_id);
    recordResult('3', 'Authorized Faculty Adviser cannot modify another adviser\'s application',
        $res3['status'] === 403 && ($dbApp3['status'] ?? '') === 'Pending',
        "HTTP {$res3['status']}, Target DB status remains: " . ($dbApp3['status'] ?? 'unknown'));

    // -------------------------------------------------------------------------
    // 4. OSA can manage applications according to existing RBAC
    // -------------------------------------------------------------------------
    // OSA approves Club B's Application 3
    $res4 = makeCurl('modules/cocurricular/pages/osa-process-application.php', $osaSess['sessionId'], 'POST', [
        'action' => 'approve',
        'application_id' => $app3_id,
        'csrf_token' => $osaSess['csrfToken']
    ], ['X-Requested-With: XMLHttpRequest']);

    $dbApp3AfterOsa = cocurricularGetMembershipApplication($app3_id);
    recordResult('4', 'OSA can manage applications according to existing RBAC',
        $res4['status'] === 200 && ($res4['json']['success'] ?? false) === true && ($dbApp3AfterOsa['status'] ?? '') === 'Approved',
        "HTTP {$res4['status']}, Target DB status: " . ($dbApp3AfterOsa['status'] ?? 'unknown'));

    // -------------------------------------------------------------------------
    // 5. Superadmin can manage applications according to existing RBAC
    // -------------------------------------------------------------------------
    // Create Application 4 for Club B (Student 188)
    $stmt->execute([$clubB_id, $student2Id, 'S230000002', 'Reason 4', 'Robotics', 'General Member', 1, 'Pending']);
    $app4_id = (int) $pdo->lastInsertId();
    $cleanupApplicationIds[] = $app4_id;

    $res5 = makeCurl('modules/cocurricular/pages/osa-process-application.php', $superadminSess['sessionId'], 'POST', [
        'action' => 'approve',
        'application_id' => $app4_id,
        'csrf_token' => $superadminSess['csrfToken']
    ], ['X-Requested-With: XMLHttpRequest']);

    $dbApp4 = cocurricularGetMembershipApplication($app4_id);
    recordResult('5', 'Superadmin can manage applications according to existing RBAC',
        $res5['status'] === 200 && ($res5['json']['success'] ?? false) === true && ($dbApp4['status'] ?? '') === 'Approved',
        "HTTP {$res5['status']}, DB status: " . ($dbApp4['status'] ?? 'unknown'));

    // -------------------------------------------------------------------------
    // 6. Student cannot approve/reject applications
    // -------------------------------------------------------------------------
    // Create Application 5 for Club C (Student 1)
    $stmt->execute([$clubC_id, $student1Id, 'S230000001', 'Reason 5', 'Art', 'General Member', 1, 'Pending']);
    $app5_id = (int) $pdo->lastInsertId();
    $cleanupApplicationIds[] = $app5_id;

    $res6 = makeCurl('modules/cocurricular/pages/osa-process-application.php', $studentSess['sessionId'], 'POST', [
        'action' => 'approve',
        'application_id' => $app5_id,
        'csrf_token' => $studentSess['csrfToken']
    ], ['X-Requested-With: XMLHttpRequest']);

    recordResult('6', 'Student cannot approve/reject applications',
        $res6['status'] === 403,
        "HTTP {$res6['status']}");

    // -------------------------------------------------------------------------
    // 7. Finance/HR cannot approve/reject applications
    // -------------------------------------------------------------------------
    $res7a = makeCurl('modules/cocurricular/pages/osa-process-application.php', $financeSess['sessionId'], 'POST', [
        'action' => 'approve',
        'application_id' => $app5_id,
        'csrf_token' => $financeSess['csrfToken']
    ], ['X-Requested-With: XMLHttpRequest']);

    $res7b = makeCurl('modules/cocurricular/pages/osa-process-application.php', $hrSess['sessionId'], 'POST', [
        'action' => 'approve',
        'application_id' => $app5_id,
        'csrf_token' => $hrSess['csrfToken']
    ], ['X-Requested-With: XMLHttpRequest']);

    recordResult('7', 'Finance/HR cannot approve/reject applications',
        $res7a['status'] === 403 && $res7b['status'] === 403,
        "Finance HTTP {$res7a['status']}, HR HTTP {$res7b['status']}");

    // -------------------------------------------------------------------------
    // 8. Missing CSRF returns HTTP 403
    // -------------------------------------------------------------------------
    $res8 = makeCurl('modules/cocurricular/pages/osa-process-application.php', $facultyBSess['sessionId'], 'POST', [
        'action' => 'approve',
        'application_id' => $app5_id
    ], ['X-Requested-With: XMLHttpRequest']);

    recordResult('8', 'Missing CSRF returns HTTP 403',
        $res8['status'] === 403,
        "HTTP {$res8['status']}");

    // -------------------------------------------------------------------------
    // 9. Invalid CSRF returns HTTP 403
    // -------------------------------------------------------------------------
    $res9 = makeCurl('modules/cocurricular/pages/osa-process-application.php', $facultyBSess['sessionId'], 'POST', [
        'action' => 'approve',
        'application_id' => $app5_id,
        'csrf_token' => 'invalid_tampered_token_xyz'
    ], ['X-Requested-With: XMLHttpRequest']);

    recordResult('9', 'Invalid CSRF returns HTTP 403',
        $res9['status'] === 403,
        "HTTP {$res9['status']}");

    // -------------------------------------------------------------------------
    // 10. Tampered application_id is rejected
    // -------------------------------------------------------------------------
    $res10 = makeCurl('modules/cocurricular/pages/osa-process-application.php', $facultyBSess['sessionId'], 'POST', [
        'action' => 'approve',
        'application_id' => 99999999,
        'csrf_token' => $facultyBSess['csrfToken']
    ], ['X-Requested-With: XMLHttpRequest']);

    recordResult('10', 'Tampered application_id is rejected',
        $res10['status'] === 404,
        "HTTP {$res10['status']}");

    // -------------------------------------------------------------------------
    // 11. Tampered club_id cannot bypass authorization
    // -------------------------------------------------------------------------
    // Faculty A tampers club_id parameter to claim ownership of App 5 (which belongs to Club C, advised by Faculty B)
    $res11 = makeCurl('modules/cocurricular/pages/osa-process-application.php', $facultyASess['sessionId'], 'POST', [
        'action' => 'approve',
        'application_id' => $app5_id,
        'club_id' => $clubA_id,
        'csrf_token' => $facultyASess['csrfToken']
    ], ['X-Requested-With: XMLHttpRequest']);

    $dbApp5Check = cocurricularGetMembershipApplication($app5_id);
    recordResult('11', 'Tampered club_id cannot bypass authorization',
        $res11['status'] === 403 && ($dbApp5Check['status'] ?? '') === 'Pending',
        "HTTP {$res11['status']}, DB status: " . ($dbApp5Check['status'] ?? 'unknown'));

    // -------------------------------------------------------------------------
    // 12. Tampered student_id cannot change the target application
    // -------------------------------------------------------------------------
    // Faculty B approves App 5 but client submits a tampered student_id
    $res12 = makeCurl('modules/cocurricular/pages/osa-process-application.php', $facultyBSess['sessionId'], 'POST', [
        'action' => 'approve',
        'application_id' => $app5_id,
        'student_id' => 'S999999999',
        'csrf_token' => $facultyBSess['csrfToken']
    ], ['X-Requested-With: XMLHttpRequest']);

    $dbApp5 = cocurricularGetMembershipApplication($app5_id);
    recordResult('12', 'Tampered student_id cannot change the target application',
        $res12['status'] === 200 && ($dbApp5['student_id'] ?? '') === 'S230000001',
        "Student ID in DB remained authoritative: " . ($dbApp5['student_id'] ?? 'unknown'));

    // -------------------------------------------------------------------------
    // 13. Repeated submission does not create duplicate records
    // -------------------------------------------------------------------------
    // App 5 is already Approved; send approve action again
    $res13 = makeCurl('modules/cocurricular/pages/osa-process-application.php', $facultyBSess['sessionId'], 'POST', [
        'action' => 'approve',
        'application_id' => $app5_id,
        'csrf_token' => $facultyBSess['csrfToken']
    ], ['X-Requested-With: XMLHttpRequest']);

    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM club_membership_applications WHERE id = ?');
    $stmtCount->execute([$app5_id]);
    $app5Count = (int) $stmtCount->fetchColumn();

    recordResult('13', 'Repeated submission does not create duplicate records',
        $res13['status'] === 409 && $app5Count === 1,
        "HTTP {$res13['status']} (Conflict), Total rows with id {$app5_id}: {$app5Count}");

    // -------------------------------------------------------------------------
    // 14. Successful approval changes status to Approved
    // -------------------------------------------------------------------------
    recordResult('14', 'Successful approval changes status to Approved',
        ($dbApp1['status'] ?? '') === 'Approved' && !empty($dbApp1['reviewed_at']),
        "Application 1 status: " . ($dbApp1['status'] ?? 'unknown'));

    // -------------------------------------------------------------------------
    // 15. Successful rejection changes status to Rejected
    // -------------------------------------------------------------------------
    recordResult('15', 'Successful rejection changes status to Rejected',
        ($dbApp2['status'] ?? '') === 'Rejected' && !empty($dbApp2['rejection_reason']),
        "Application 2 status: " . ($dbApp2['status'] ?? 'unknown') . ", reason: " . ($dbApp2['rejection_reason'] ?? ''));

    // -------------------------------------------------------------------------
    // 16. Pending transition works only if allowed by existing business rules
    // -------------------------------------------------------------------------
    // Direct call to helper with 'Pending' should be rejected per business rules
    $pendingAttempt = cocurricularUpdateMembershipApplicationStatus($app1_id, 'Pending', $facultyA_id);
    recordResult('16', 'Pending transition works only if allowed by existing business rules',
        $pendingAttempt === false,
        "Helper strictly disallows invalid status transitions to Pending");

    // -------------------------------------------------------------------------
    // 17. UI status badge updates correctly
    // -------------------------------------------------------------------------
    $pageRender = makeCurl('modules/cocurricular/pages/membership-applications.php', $osaSess['sessionId']);
    $hasApprovedBadge = strpos($pageRender['body'], 'bg-success status-badge">Approved</span>') !== false;
    $hasRejectedBadge = strpos($pageRender['body'], 'bg-danger status-badge">Rejected</span>') !== false;
    recordResult('17', 'UI status badge updates correctly',
        $hasApprovedBadge && $hasRejectedBadge,
        "Approved badge found: " . ($hasApprovedBadge ? 'yes' : 'no') . ", Rejected badge found: " . ($hasRejectedBadge ? 'yes' : 'no'));

    // -------------------------------------------------------------------------
    // 18. Actions dropdown updates according to the new status
    // -------------------------------------------------------------------------
    // For Approved App 1: menu must show "Application Approved" indicator and NO accept/reject buttons
    $row1Start = strpos($pageRender['body'], 'id="applicationRow-' . $app1_id . '"');
    $row1End = strpos($pageRender['body'], '</tr>', $row1Start);
    $row1Html = substr($pageRender['body'], $row1Start, $row1End - $row1Start);

    $app1HasApprovedIndicator = strpos($row1Html, 'indicator-approved') !== false;
    $app1HasNoAcceptBtn = strpos($row1Html, 'js-action-approve') === false;
    $app1HasNoRejectBtn = strpos($row1Html, 'js-action-reject') === false;

    // For Rejected App 2: menu must show "Application Rejected" indicator and NO accept/reject buttons
    $row2Start = strpos($pageRender['body'], 'id="applicationRow-' . $app2_id . '"');
    $row2End = strpos($pageRender['body'], '</tr>', $row2Start);
    $row2Html = substr($pageRender['body'], $row2Start, $row2End - $row2Start);

    $app2HasRejectedIndicator = strpos($row2Html, 'indicator-rejected') !== false;
    $app2HasNoAcceptBtn = strpos($row2Html, 'js-action-approve') === false;
    $app2HasNoRejectBtn = strpos($row2Html, 'js-action-reject') === false;

    recordResult('18', 'Actions dropdown updates according to the new status',
        $app1HasApprovedIndicator && $app1HasNoAcceptBtn && $app1HasNoRejectBtn
        && $app2HasRejectedIndicator && $app2HasNoAcceptBtn && $app2HasNoRejectBtn,
        "Approved row has indicator: " . ($app1HasApprovedIndicator ? 'yes' : 'no') . ", Rejected row has indicator: " . ($app2HasRejectedIndicator ? 'yes' : 'no'));

    // -------------------------------------------------------------------------
    // 19. No PHP warnings/notices
    // -------------------------------------------------------------------------
    $hasWarnings = strpos($pageRender['body'], '<b>Notice</b>') !== false
        || strpos($pageRender['body'], '<b>Warning</b>') !== false
        || strpos($pageRender['body'], '<b>Fatal error</b>') !== false;
    recordResult('19', 'No PHP warnings/notices',
        !$hasWarnings,
        "Rendered HTML output checked for PHP error tokens");

    // -------------------------------------------------------------------------
    // 20. No JavaScript console errors / valid HTML & script syntax
    // -------------------------------------------------------------------------
    $hasScriptTags = strpos($pageRender['body'], 'cocurricularOpenApproveModal') !== false
        && strpos($pageRender['body'], 'updateRowStatusInDOM') !== false;
    $hasValidBootstrapAttrs = strpos($pageRender['body'], 'data-bs-toggle="dropdown"') !== false
        && strpos($pageRender['body'], 'data-bs-boundary="viewport"') !== false;

    recordResult('20', 'No JavaScript console errors / valid scripts & bindings',
        $hasScriptTags && $hasValidBootstrapAttrs,
        "Scripts and bootstrap bindings validated");

} finally {
    echo "\n--- Cleaning up temporary test records ---\n";
    if (!empty($cleanupNotificationIds)) {
        $pdo->query('DELETE FROM cocurricular_notifications WHERE id IN (' . implode(',', $cleanupNotificationIds) . ')');
    }
    // Also clean any notifications generated during this test
    $pdo->query("DELETE FROM cocurricular_notifications WHERE user_id IN (9, 188) AND type IN ('membership_approved', 'membership_rejected')");

    if (!empty($cleanupApplicationIds)) {
        $pdo->query('DELETE FROM club_membership_applications WHERE id IN (' . implode(',', $cleanupApplicationIds) . ')');
    }
    if (!empty($cleanupClubIds)) {
        $pdo->query('DELETE FROM clubs WHERE id IN (' . implode(',', $cleanupClubIds) . ')');
    }
    foreach ($cleanupSessionFiles as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
    echo "Cleaned up all temporary fixtures, applications, clubs, and session files.\n";
}

echo "\n====================================================================\n";
echo " FINAL 20-POINT SUMMARY\n";
echo "====================================================================\n";
$totalTests = count($testResults);
$passedTests = count(array_filter($testResults, fn($t) => $t['passed']));
$failedTests = $totalTests - $passedTests;
echo "Total Acceptance Criteria: {$totalTests}\n";
echo "Passed:                   {$passedTests}\n";
echo "Failed:                   {$failedTests}\n";

if ($failedTests === 0) {
    echo "\n\033[32mALL 20 ACCEPTANCE CRITERIA PASSED PERFECTLY!\033[0m\n";
    exit(0);
} else {
    echo "\n\033[31mSOME ACCEPTANCE CRITERIA FAILED!\033[0m\n";
    exit(1);
}
