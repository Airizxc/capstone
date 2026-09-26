<?php
/**
 * SMS 2 - Faculty Portal: Co-Curricular Assigned Club Workspace
 * Module: Co-Curricular
 *
 * Provides assigned Faculty Advisers with access to their specific club:
 * 1. Assigned Club Details & Officers
 * 2. Club Members (Approved Roster & Pending Applications)
 * 3. Activities / Events (Scheduling & Management)
 * 4. Announcements (Publishing & Feed)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

requireAuth();

$currentUserId = (int) getCurrentUserId();
$roleKey = getCurrentUserRoleKey();

// Only faculty workspace users and system admins may access this portal view
$isFacultyUser = in_array($roleKey, ['faculty', 'adviser', 'panel', 'grammarian', 'research_director', 'hr', 'department_chair'], true)
    || cocurricularIsValidFacultyUser($currentUserId)
    || smsRoleAllowedForModule(['osa'], 'cocurricular');

if (!$isFacultyUser) {
    http_response_code(403);
    header('Location: ' . BASE_URL . '/dashboard/index.php');
    exit;
}

// Retrieve clubs assigned to this faculty user
$assignedClubs = cocurricularGetClubsForFacultyAdviser($currentUserId);
$hasAssignedClub = !empty($assignedClubs);

// Handle club selection if multiple assigned clubs exist or a specific club is requested
$selectedClubId = (int) ($_GET['club_id'] ?? 0);
$activeClub = null;

if ($selectedClubId > 0) {
    // Strict server-side verification: the requested club MUST belong to this faculty adviser
    $isAuthorized = false;
    foreach ($assignedClubs as $clubCandidate) {
        if ((int) $clubCandidate['id'] === $selectedClubId) {
            $activeClub = $clubCandidate;
            $isAuthorized = true;
            break;
        }
    }

    // Allow system admins to view any club if inspecting
    if (!$isAuthorized && smsRoleAllowedForModule(['osa'], 'cocurricular')) {
        $activeClub = cocurricularGetClubById($selectedClubId);
        $isAuthorized = ($activeClub !== null);
    }

    if (!$isAuthorized) {
        http_response_code(403);
        $pageTitle    = 'Access Denied — Co-Curricular';
        $activeModule = 'faculty';
        $activePage   = 'faculty-adviser';
        $breadcrumbs  = [
            ['label' => 'Faculty Portal', 'url' => BASE_URL . '/modules/faculty/index.php'],
            ['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/pages/faculty-adviser.php'],
            ['label' => 'Access Denied', 'url' => null],
        ];
        require_once __DIR__ . '/../../../includes/breadcrumbs.php';
        require_once __DIR__ . '/../../../includes/layout-start.php';
        ?>
        <link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css" rel="stylesheet">
        <?php renderBreadcrumbs($breadcrumbs); ?>
        <div class="alert alert-danger shadow-sm">
            <div class="d-flex align-items-center gap-3">
                <div class="fs-2 text-danger"><i class="fas fa-shield-alt"></i></div>
                <div>
                    <h4 class="alert-heading mb-1">Access Denied</h4>
                    <p class="mb-1">You are not authorized to view or manage this student organization.</p>
                    <small class="text-muted">Faculty Advisers may only access the specific club assigned to their account by the Office of Student Affairs.</small>
                </div>
            </div>
        </div>
        <div class="mt-3">
            <a href="<?= BASE_URL ?>/modules/cocurricular/pages/faculty-adviser.php" class="btn btn-primary">
                <i class="fas fa-arrow-left me-1"></i> Return to My Assigned Club
            </a>
        </div>
        <?php
        require_once __DIR__ . '/../../../includes/layout-end.php';
        exit;
    }
} elseif ($hasAssignedClub) {
    $activeClub = $assignedClubs[0];
}

$notice = '';
$noticeType = 'success';
if (isset($_GET['reviewed'])) {
    $notice = 'Membership application has been processed successfully.';
    $noticeType = 'success';
}

// Handle AJAX fetch for event participants
if ($hasAssignedClub && $activeClub && (($_GET['action'] ?? '') === 'fetch_event_participants' || ($_POST['action'] ?? '') === 'fetch_event_participants')) {
    $fetchEventId = (int) ($_REQUEST['event_id'] ?? 0);
    $event = cocurricularGetEventById($fetchEventId);
    if (!$event) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Event not found.']);
        exit;
    }
    if (!cocurricularIsFacultyAdviserOfClub($currentUserId, (int) $event['club_id']) && !smsRoleAllowedForModule(['osa'], 'cocurricular')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized: You are not authorized to view participants for this club event.']);
        exit;
    }
    $participants = cocurricularFetchEventParticipants($fetchEventId);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'participants' => $participants, 'event' => $event]);
    exit;
}

// Handle POST actions for Faculty Adviser (Event creation, editing, publishing, cancellation, participant review, Announcement publishing)
if ($hasAssignedClub && $activeClub && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!csrfVerify($token)) {
        $notice = 'Security token expired or invalid. Please try again.';
        $noticeType = 'danger';
    } else {
        $postAction = (string) ($_POST['action'] ?? '');
        $targetClubId = (int) $activeClub['id'];

        // Strict server-side club isolation verification
        if (!cocurricularIsFacultyAdviserOfClub($currentUserId, $targetClubId) && !smsRoleAllowedForModule(['osa'], 'cocurricular')) {
            http_response_code(403);
            exit('Unauthorized: You are not the assigned faculty adviser for this club.');
        }

        if ($postAction === 'create_event') {
            $eventResult = cocurricularCreateAdviserEvent($targetClubId, $currentUserId, $_POST);
            $notice = (string) ($eventResult['message'] ?? 'Event processed.');
            $noticeType = ($eventResult['success'] ?? false) ? 'success' : 'danger';
            $createdEventId = !empty($eventResult['success']) ? (int) ($eventResult['event_id'] ?? 0) : 0;

            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
            if ($isAjax) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Content-Type: application/json; charset=utf-8');
                $evRecord = $createdEventId > 0 ? cocurricularGetEventById($createdEventId) : null;
                echo json_encode([
                    'success'  => $eventResult['success'] ?? false,
                    'message'  => $notice,
                    'event_id' => $createdEventId,
                    'event'    => $evRecord ? [
                        'id'         => (int) $evRecord['id'],
                        'title'      => (string) $evRecord['title'],
                        'event_type' => (string) ($evRecord['event_type'] ?? 'Event'),
                        'event_date' => !empty($evRecord['event_date']) ? date('M j, Y', strtotime((string) $evRecord['event_date'])) : '',
                        'start_time' => !empty($evRecord['start_time']) ? date('g:i A', strtotime((string) $evRecord['start_time'])) : '',
                        'end_time'   => !empty($evRecord['end_time']) ? date('g:i A', strtotime((string) $evRecord['end_time'])) : '',
                        'venue'      => (string) ($evRecord['venue'] ?? ''),
                    ] : null,
                ]);
                exit;
            }
        } elseif ($postAction === 'edit_event') {
            $editEventId = (int) ($_POST['event_id'] ?? 0);
            $eventResult = cocurricularUpdateAdviserEvent($editEventId, $currentUserId, $_POST);
            $notice = (string) ($eventResult['message'] ?? 'Event updated.');
            $noticeType = ($eventResult['success'] ?? false) ? 'success' : 'danger';
        } elseif ($postAction === 'set_event_status') {
            $statusEventId = (int) ($_POST['event_id'] ?? 0);
            $newStatus = (string) ($_POST['new_status'] ?? '');
            $statusResult = cocurricularSetEventStatus($statusEventId, $newStatus, $currentUserId);
            $notice = (string) ($statusResult['message'] ?? 'Event status updated.');
            $noticeType = ($statusResult['success'] ?? false) ? 'success' : 'danger';
        } elseif ($postAction === 'review_event_participant') {
            $participantId = (int) ($_POST['participant_id'] ?? 0);
            $reviewStatus = (string) ($_POST['status'] ?? 'Approved');
            $rejectionNote = (string) ($_POST['rejection_note'] ?? '');
            $reviewResult = cocurricularReviewEventParticipant($participantId, $currentUserId, $reviewStatus, $rejectionNote);
            $isSuccess = in_array((string) ($reviewResult['status'] ?? ''), ['approved', 'rejected'], true);
            if ($isSuccess) {
                $notice = 'Participant request has been ' . strtolower($reviewStatus) . '.';
                $noticeType = 'success';
            } elseif (($reviewResult['status'] ?? '') === 'invalid_reason') {
                $notice = 'A rejection note is required when rejecting a participant.';
                $noticeType = 'danger';
            } elseif (($reviewResult['status'] ?? '') === 'unauthorized') {
                $notice = 'Unauthorized: You are not authorized to review this participant.';
                $noticeType = 'danger';
            } else {
                $notice = 'Unable to review participant (only pending requests can be reviewed).';
                $noticeType = 'danger';
            }
        } elseif ($postAction === 'create_announcement') {
            $annResult = cocurricularCreateAdviserAnnouncement($targetClubId, $currentUserId, $_POST);
            $notice = (string) ($annResult['message'] ?? 'Announcement processed.');
            $noticeType = ($annResult['success'] ?? false) ? 'success' : 'danger';
        }
    }
}

// Active Tab navigation
$activeTab = (string) ($_GET['tab'] ?? 'overview');
if (!in_array($activeTab, ['overview', 'members', 'events', 'announcements'], true)) {
    $activeTab = 'overview';
}

// Pre-load data for active club if present
$approvedMembers = [];
$pendingApplications = [];
$clubEvents = [];
$clubAnnouncements = [];
$clubOfficers = [];

if ($activeClub) {
    $clubId = (int) $activeClub['id'];
    $approvedMembers = cocurricularGetClubApprovedMembersEnriched($clubId);
    $pendingApplications = cocurricularGetClubPendingApplicationsEnriched($clubId);
    $clubEvents = cocurricularFetchEventsForAssignedClub($clubId);
    $clubAnnouncements = cocurricularGetClubAnnouncements($clubId);
    $clubOfficers = cocurricularFetchClubOfficers($clubId);
}

// Page layout context
$pageTitle    = $hasAssignedClub && $activeClub
    ? (string) $activeClub['club_name'] . ' — Faculty Adviser'
    : 'Co-Curricular — Faculty Adviser';
$activeModule = 'faculty';
$activePage   = 'faculty-adviser';

$breadcrumbs  = [
    ['label' => 'Faculty Portal', 'url' => BASE_URL . '/modules/faculty/index.php'],
    ['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/pages/faculty-adviser.php'],
];
if ($hasAssignedClub && $activeClub) {
    $breadcrumbs[] = ['label' => (string) $activeClub['club_name'], 'url' => null];
} else {
    $breadcrumbs[] = ['label' => 'Assigned Club', 'url' => null];
}

$pageBannerDescription = $hasAssignedClub && $activeClub
    ? 'Official Faculty Adviser Workspace for ' . (string) $activeClub['club_name'] . '. Manage club details, student members, activities, and announcements.'
    : 'Co-Curricular Faculty Adviser access and club organizational management.';
$pageBannerIcon = 'fa-users';

if (isset($_GET['theme']) && in_array($_GET['theme'], ['light', 'dark'], true)) {
    $forceTheme = (string) $_GET['theme'];
}

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
?>
<link href="<?= BASE_URL ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css?v=<?= file_exists(__DIR__ . '/../assets/css/cocurricular.css') ? filemtime(__DIR__ . '/../assets/css/cocurricular.css') : '4' ?>" rel="stylesheet">

<?php renderBreadcrumbs($breadcrumbs); ?>

<?php if ($notice !== ''): ?>
    <div class="alert alert-<?= htmlspecialchars($noticeType) ?> alert-dismissible fade show mb-4" role="alert">
        <i class="fas fa-<?= $noticeType === 'success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
        <?= htmlspecialchars($notice) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!$hasAssignedClub || !$activeClub): ?>
    <!-- CASE 2: FACULTY HAS NO ASSIGNED CLUB -->
    <div class="card cocurricular-card border-0 shadow-sm mb-4">
        <div class="card-body p-4 p-md-5 text-center">
            <div class="mb-3">
                <span class="d-inline-flex align-items-center justify-content-center bg-light text-muted rounded-circle" style="width: 72px; height: 72px; font-size: 2rem;">
                    <i class="fas fa-user-shield"></i>
                </span>
            </div>
            <h3 class="fw-bold mb-2">No Co-Curricular Club Assigned</h3>
            <p class="text-muted mx-auto mb-4" style="max-width: 620px;">
                You are currently not assigned as an official Faculty Adviser to any student club or organization. Club adviser appointments are verified and recorded by the <strong>Office of Student Affairs (OSA)</strong>.
            </p>

            <div class="card bg-light border-0 mx-auto text-start p-3 mb-4" style="max-width: 580px;">
                <div class="small fw-semibold text-uppercase text-muted mb-2">
                    <i class="fas fa-info-circle text-primary me-1"></i> How Faculty Advising Works
                </div>
                <ul class="small text-muted mb-0 ps-3">
                    <li class="mb-1">Student clubs and organizations are assigned verified faculty advisers by the OSA Administrator.</li>
                    <li class="mb-1">Once assigned, your assigned organization’s member roster, activities, events, and announcements will automatically appear here in your Faculty Portal.</li>
                    <li>If you have been appointed to advise a club, please contact the Office of Student Affairs to update your assignment.</li>
                </ul>
            </div>

            <div>
                <a href="<?= BASE_URL ?>/modules/faculty/index.php" class="btn btn-primary px-4">
                    <i class="fas fa-arrow-left me-1"></i> Return to Faculty Portal
                </a>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- CASE 1: FACULTY HAS AN ASSIGNED CLUB -->

    <!-- Club Workspace Context Card -->
    <div class="card cocurricular-card faculty-club-context-card border-0 shadow-sm mb-4">
        <div class="card-body p-3 px-md-4 py-md-3">
            <div class="faculty-club-info">
                <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                    <span class="badge bg-primary text-white"><i class="fas fa-user-tie me-1"></i>Faculty Adviser</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle"><?= htmlspecialchars((string) $activeClub['category']) ?></span>
                    <span class="badge bg-success text-white"><?= htmlspecialchars((string) $activeClub['status']) ?></span>
                </div>
                <h2 class="h5 fw-bold text-dark mb-1"><?= htmlspecialchars((string) $activeClub['club_name']) ?></h2>
                <p class="text-muted small mb-0">
                    <i class="fas fa-phone-alt me-1 text-muted"></i> <?= htmlspecialchars((string) ($activeClub['contact_phone'] ?: 'N/A')) ?>
                    <span class="mx-2 text-muted">·</span>
                    <i class="fas fa-envelope me-1 text-muted"></i> <?= htmlspecialchars((string) ($activeClub['adviser_email'] ?: 'No email on record')) ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card cocurricular-card border-0 shadow-sm h-100 faculty-stat-card">
                <div class="card-body p-3">
                    <div class="text-muted small mb-2">Approved Members</div>
                    <div class="h3 fw-bold text-dark mb-0"><?= count($approvedMembers) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card cocurricular-card border-0 shadow-sm h-100 faculty-stat-card">
                <div class="card-body p-3">
                    <div class="text-muted small mb-2">Pending Applicants</div>
                    <div class="h3 fw-bold text-dark mb-0"><?= count($pendingApplications) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card cocurricular-card border-0 shadow-sm h-100 faculty-stat-card">
                <div class="card-body p-3">
                    <div class="text-muted small mb-2">Scheduled Events</div>
                    <div class="h3 fw-bold text-dark mb-0"><?= count($clubEvents) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card cocurricular-card border-0 shadow-sm h-100 faculty-stat-card">
                <div class="card-body p-3">
                    <div class="text-muted small mb-2">Announcements</div>
                    <div class="h3 fw-bold text-dark mb-0"><?= count($clubAnnouncements) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills cocurricular-nav-pills mb-4 gap-3 flex-wrap">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'overview' ? 'active' : '' ?>"
               href="?club_id=<?= (int) $activeClub['id'] ?>&tab=overview">
                <i class="fas fa-users me-1"></i> Assigned Club
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'members' ? 'active' : '' ?>"
               href="?club_id=<?= (int) $activeClub['id'] ?>&tab=members">
                <i class="fas fa-users me-1"></i> Members
                <span class="badge ms-1"><?= count($approvedMembers) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'events' ? 'active' : '' ?>"
               href="?club_id=<?= (int) $activeClub['id'] ?>&tab=events">
                <i class="fas fa-calendar-alt me-1"></i> Activities / Events
                <span class="badge ms-1"><?= count($clubEvents) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'announcements' ? 'active' : '' ?>"
               href="?club_id=<?= (int) $activeClub['id'] ?>&tab=announcements">
                <i class="fas fa-bullhorn me-1"></i> Announcements
                <span class="badge ms-1"><?= count($clubAnnouncements) ?></span>
            </a>
        </li>
    </ul>

    <!-- TAB 1: ASSIGNED CLUB OVERVIEW / WORKSPACE SELECTION -->
    <?php if ($activeTab === 'overview'): ?>
        <div class="mb-3">
            <h5 class="fw-bold mb-1 text-dark">Assigned Clubs</h5>
            <p class="text-muted small mb-0">Switch between your assigned clubs to manage their workspaces.</p>
        </div>
        <div class="assigned-clubs-list d-flex flex-column gap-3 mb-4">
            <?php foreach ($assignedClubs as $clubItem): ?>
                <?php $isCurrent = ((int) $clubItem['id'] === (int) $activeClub['id']); ?>
                <div class="card cocurricular-card border-0 shadow-sm assigned-club-card <?= $isCurrent ? 'is-active-workspace' : '' ?>">
                    <div class="card-body p-3 px-md-4 py-md-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div class="assigned-club-details">
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                <h5 class="assigned-club-name mb-0 fw-bold text-dark"><?= htmlspecialchars((string) $clubItem['club_name']) ?></h5>
                                <span class="badge bg-<?= $clubItem['status'] === 'Active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars((string) $clubItem['status']) ?></span>
                                <?php if ($isCurrent): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                        Currently Active
                                    </span>
                                <?php endif; ?>
                            </div>
                            <p class="small text-muted mb-0">
                                Category: <?= htmlspecialchars((string) $clubItem['category']) ?>
                                <span class="mx-2 text-muted">·</span>
                                Members: <?= (int) ($clubItem['member_count'] ?? 0) ?>
                                <span class="mx-2 text-muted">·</span>
                                Pending: <?= (int) ($clubItem['pending_count'] ?? 0) ?>
                            </p>
                        </div>
                        <?php if (!$isCurrent): ?>
                            <div class="assigned-club-action">
                                <a href="?club_id=<?= (int) $clubItem['id'] ?>&tab=overview" class="btn btn-primary px-3">
                                    <i class="fas fa-arrow-right me-1" aria-hidden="true"></i> Switch to This Club
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <!-- TAB 2: MEMBERS -->
    <?php elseif ($activeTab === 'members'): ?>
        <div class="card cocurricular-card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="card-title mb-0 fw-bold">Approved Club Members</h5>
                    <small class="text-muted">Students registered and active in <?= htmlspecialchars((string) $activeClub['club_name']) ?></small>
                </div>
                <span class="badge bg-success text-white px-3 py-2 rounded-pill"><?= count($approvedMembers) ?> Approved</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($approvedMembers)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-users-slash fs-3 d-block mb-2 text-secondary"></i>
                        No approved members in this club yet.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Student Name</th>
                                    <th>Student ID</th>
                                    <th>Email</th>
                                    <th>Preferred Participation</th>
                                    <th>Date Joined</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($approvedMembers as $idx => $member): ?>
                                    <tr>
                                        <td class="text-muted"><?= $idx + 1 ?></td>
                                        <td class="fw-bold"><?= htmlspecialchars((string) $member['student_name']) ?></td>
                                        <td><code><?= htmlspecialchars((string) $member['student_id']) ?></code></td>
                                        <td><?= htmlspecialchars((string) ($member['student_email'] ?: '—')) ?></td>
                                        <td><?= htmlspecialchars((string) ($member['preferred_participation'] ?: 'General Member')) ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime((string) ($member['reviewed_at'] ?? $member['submitted_at'])))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pending Applications View for Adviser Review -->
        <div class="card cocurricular-card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h6 class="card-title mb-0 fw-bold text-warning-emphasis">
                        <i class="fas fa-clock me-1 text-warning"></i> Pending Membership Applications
                    </h6>
                    <small class="text-muted">Review, approve, or reject student membership applications for <?= htmlspecialchars((string) $activeClub['club_name']) ?>.</small>
                </div>
                <span class="badge bg-warning text-dark px-3 py-2 rounded-pill"><?= count($pendingApplications) ?> Awaiting Review</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($pendingApplications)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-check-circle fs-3 d-block mb-2 text-success"></i>
                        No pending membership applications awaiting review for this club.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Student Name</th>
                                    <th>Student ID</th>
                                    <th>Email</th>
                                    <th>Preferred Participation</th>
                                    <th>Submitted Date</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingApplications as $pIdx => $app): ?>
                                    <tr>
                                        <td class="text-muted"><?= $pIdx + 1 ?></td>
                                        <td class="fw-bold"><?= htmlspecialchars((string) $app['student_name']) ?></td>
                                        <td><code><?= htmlspecialchars((string) $app['student_id']) ?></code></td>
                                        <td><?= htmlspecialchars((string) ($app['student_email'] ?: '—')) ?></td>
                                        <td><?= htmlspecialchars((string) ($app['preferred_participation'] ?: 'General Member')) ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime((string) $app['submitted_at']))) ?></td>
                                        <td class="text-end">
                                            <div class="cocurricular-table-actions justify-content-end">
                                                <button type="button" class="btn btn-outline-primary cocurricular-icon-btn js-adviser-view-app"
                                                        data-application-id="<?= (int) $app['id'] ?>"
                                                        data-bs-toggle="modal" data-bs-target="#adviserAppDetailsModal"
                                                        aria-label="View Application Details"
                                                        title="View Application Details">
                                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                                </button>
                                                <form method="POST" action="<?= BASE_URL ?>/modules/cocurricular/pages/osa-process-application.php" class="d-inline" onsubmit="return confirm('Approve this membership application?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                                    <input type="hidden" name="return_to" value="faculty-adviser">
                                                    <button type="submit" class="btn btn-outline-success cocurricular-icon-btn" aria-label="Approve Application" title="Approve Application">
                                                        <i class="fas fa-check" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                                <button type="button" class="btn btn-outline-danger cocurricular-icon-btn js-adviser-reject-btn"
                                                        data-application-id="<?= (int) $app['id'] ?>"
                                                        data-student-name="<?= htmlspecialchars((string) $app['student_name']) ?>"
                                                        data-bs-toggle="modal" data-bs-target="#adviserRejectModal"
                                                        aria-label="Reject Application"
                                                        title="Reject Application">
                                                    <i class="fas fa-times" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <!-- TAB 3: ACTIVITIES / EVENTS -->
    <?php elseif ($activeTab === 'events'): ?>
        <div class="card cocurricular-card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="card-title mb-0 fw-bold">Club Activities & Events</h5>
                    <small class="text-muted">Events scheduled for <?= htmlspecialchars((string) $activeClub['club_name']) ?></small>
                </div>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#scheduleEventModal">
                    <i class="fas fa-calendar-plus me-1"></i> Schedule Event
                </button>
            </div>
            <div class="card-body p-0">
                <div class="p-3 bg-light-subtle border-bottom small text-muted">
                    <i class="fas fa-info-circle text-primary me-1"></i>
                    Manage event attendance, view participant check-ins, and generate attendance codes or QR passes for your club's scheduled activities below.
                </div>

                <?php if (empty($clubEvents)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-calendar-times fs-3 d-block mb-2 text-secondary"></i>
                        No events scheduled for this club yet. Click "Schedule Event" above to post an activity.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small club-events-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Event Title</th>
                                    <th>Type</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Venue</th>
                                    <th>Participants</th>
                                    <th>Status</th>
                                    <th class="text-end event-actions-column">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clubEvents as $event): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars((string) $event['title']) ?></div>
                                            <?php if (!empty($event['is_pinned'])): ?>
                                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle" style="font-size: 0.65rem;">
                                                    <i class="fas fa-thumbtack me-1"></i>Pinned
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars((string) $event['event_type']) ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime((string) $event['event_date']))) ?></td>
                                        <td>
                                            <?= htmlspecialchars(date('g:i A', strtotime((string) $event['start_time']))) ?>
                                            – <?= htmlspecialchars(date('g:i A', strtotime((string) $event['end_time']))) ?>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($event['venue'] ?: 'Campus')) ?></td>
                                        <td>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle">
                                                <i class="fas fa-user-check me-1"></i><?= (int) $event['participant_count'] ?> registered
                                            </span>
                                        </td>
                                        <td class="event-status-cell">
                                            <?php
                                            $rawStatus = trim((string) ($event['status'] ?? ''));
                                            $statusClass = 'event-status-unknown';
                                            $statusLabel = 'Unknown';

                                            if ($rawStatus !== '') {
                                                $normStatus = strtolower($rawStatus);
                                                if ($normStatus === 'published') {
                                                    $statusClass = 'event-status-published';
                                                    $statusLabel = 'Published';
                                                } elseif ($normStatus === 'draft') {
                                                    $statusClass = 'event-status-draft';
                                                    $statusLabel = 'Draft';
                                                } elseif ($normStatus === 'pending' || $normStatus === 'in review' || $normStatus === 'in-review') {
                                                    $statusClass = 'event-status-pending';
                                                    $statusLabel = ($normStatus === 'in-review' || $normStatus === 'in review') ? 'In Review' : $rawStatus;
                                                } elseif ($normStatus === 'cancelled' || $normStatus === 'canceled') {
                                                    $statusClass = 'event-status-cancelled';
                                                    $statusLabel = 'Cancelled';
                                                } elseif ($normStatus === 'completed') {
                                                    $statusClass = 'event-status-completed';
                                                    $statusLabel = 'Completed';
                                                } else {
                                                    $statusClass = 'event-status-' . preg_replace('/[^a-z0-9_-]/', '-', $normStatus);
                                                    $statusLabel = $rawStatus;
                                                }
                                            }
                                            ?>
                                            <span class="event-status-badge <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                        </td>
                                        <td class="text-end event-actions-cell">
                                            <div class="event-actions">
                                                <button type="button" class="btn btn-outline-info event-action-btn event-action-participants js-adviser-view-participants"
                                                        data-event-id="<?= (int) $event['id'] ?>"
                                                        data-event-title="<?= htmlspecialchars((string) $event['title'], ENT_QUOTES) ?>"
                                                        data-bs-toggle="modal" data-bs-target="#eventParticipantsModal"
                                                        aria-label="View Participants"
                                                        title="View Participants">
                                                    <i class="fas fa-users" aria-hidden="true"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary event-action-btn event-action-edit js-adviser-edit-event"
                                                        data-event-id="<?= (int) $event['id'] ?>"
                                                        data-title="<?= htmlspecialchars((string) $event['title'], ENT_QUOTES) ?>"
                                                        data-event-type="<?= htmlspecialchars((string) $event['event_type'], ENT_QUOTES) ?>"
                                                        data-event-date="<?= htmlspecialchars((string) $event['event_date'], ENT_QUOTES) ?>"
                                                        data-start-time="<?= htmlspecialchars((string) $event['start_time'], ENT_QUOTES) ?>"
                                                        data-end-time="<?= htmlspecialchars((string) $event['end_time'], ENT_QUOTES) ?>"
                                                        data-venue="<?= htmlspecialchars((string) ($event['venue'] ?? ''), ENT_QUOTES) ?>"
                                                        data-description="<?= htmlspecialchars((string) ($event['description'] ?? ''), ENT_QUOTES) ?>"
                                                        data-is-pinned="<?= !empty($event['is_pinned']) ? '1' : '0' ?>"
                                                        data-bs-toggle="modal" data-bs-target="#editEventModal"
                                                        aria-label="Edit Event Details"
                                                        title="Edit Event Details">
                                                    <i class="fas fa-edit" aria-hidden="true"></i>
                                                </button>

                                                <?php
                                                $dateFormatted = !empty($event['event_date']) ? date('M j, Y', strtotime((string) $event['event_date'])) : '';
                                                $timeFormatted = (!empty($event['start_time']) ? date('g:i A', strtotime((string) $event['start_time'])) : '') .
                                                    (!empty($event['end_time']) ? ' – ' . date('g:i A', strtotime((string) $event['end_time'])) : '');
                                                ?>
                                                <button type="button" class="btn btn-outline-primary event-action-btn event-action-ai js-adviser-generate-event-announcement"
                                                        data-event-id="<?= (int) $event['id'] ?>"
                                                        data-event-title="<?= htmlspecialchars((string) $event['title'], ENT_QUOTES) ?>"
                                                        data-event-type="<?= htmlspecialchars((string) ($event['event_type'] ?? 'Event'), ENT_QUOTES) ?>"
                                                        data-event-date="<?= htmlspecialchars($dateFormatted, ENT_QUOTES) ?>"
                                                        data-event-time="<?= htmlspecialchars($timeFormatted, ENT_QUOTES) ?>"
                                                        data-event-venue="<?= htmlspecialchars((string) ($event['venue'] ?? 'Campus'), ENT_QUOTES) ?>"
                                                        aria-label="Generate Announcement with AI"
                                                        title="Generate Announcement with AI">
                                                    <i class="fas fa-magic" aria-hidden="true"></i>
                                                </button>

                                                <?php
                                                $isPublished = (strcasecmp($rawStatus, 'Published') === 0);
                                                $isDraft = (strcasecmp($rawStatus, 'Draft') === 0);
                                                $isCancelled = (strcasecmp($rawStatus, 'Cancelled') === 0 || strcasecmp($rawStatus, 'Canceled') === 0);
                                                $isCompleted = (strcasecmp($rawStatus, 'Completed') === 0);
                                                ?>
                                                <?php if ($isPublished): ?>
                                                    <a href="<?= BASE_URL ?>/modules/cocurricular/pages/attendance-tracker.php?club_id=<?= (int) $activeClub['id'] ?>&event_id=<?= (int) $event['id'] ?>"
                                                       class="btn btn-outline-primary event-action-btn event-action-attendance"
                                                       aria-label="Manage Attendance"
                                                       title="Manage Attendance">
                                                        <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                                                    </a>
                                                <?php elseif ($isDraft): ?>
                                                    <form method="POST" action="?club_id=<?= (int) $activeClub['id'] ?>&tab=events" class="event-action-form event-action-publish-form" onsubmit="return confirm('Publish this event now?');">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="set_event_status">
                                                        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                                        <input type="hidden" name="new_status" value="Published">
                                                        <button type="submit" class="btn btn-outline-success event-action-btn event-action-publish" aria-label="Publish Event" title="Publish Event">
                                                            <i class="fas fa-calendar-check" aria-hidden="true"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if (!$isCancelled && !$isCompleted): ?>
                                                    <form method="POST" action="?club_id=<?= (int) $activeClub['id'] ?>&tab=events" class="event-action-form event-action-cancel-form" onsubmit="return confirm('Cancel this event? The event record and participant history will be preserved as Cancelled.');">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="set_event_status">
                                                        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                                        <input type="hidden" name="new_status" value="Cancelled">
                                                        <button type="submit" class="btn btn-outline-danger event-action-btn event-action-cancel" aria-label="Cancel Event" title="Cancel Event">
                                                            <i class="fas fa-trash-alt" aria-hidden="true"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <!-- TAB 4: ANNOUNCEMENTS -->
    <?php elseif ($activeTab === 'announcements'): ?>
        <div class="card cocurricular-card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="card-title mb-0 fw-bold">Club Announcements</h5>
                    <small class="text-muted">Official notices published to members of <?= htmlspecialchars((string) $activeClub['club_name']) ?></small>
                </div>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#postAnnouncementModal">
                    <i class="fas fa-bullhorn me-1"></i> Post Announcement
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($clubAnnouncements)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-comment-slash fs-3 d-block mb-2 text-secondary"></i>
                        No announcements posted for this club yet. Click "Post Announcement" above to notify your members.
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($clubAnnouncements as $announcement): ?>
                            <div class="list-group-item p-3">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <div>
                                        <h6 class="mb-1 fw-bold"><?= htmlspecialchars((string) $announcement['title']) ?></h6>
                                        <small class="text-muted">
                                            <i class="fas fa-clock me-1"></i> Posted on <?= htmlspecialchars(date('M j, Y \a\t g:i A', strtotime((string) $announcement['posted_at']))) ?>
                                        </small>
                                    </div>
                                    <?php if (!empty($announcement['is_pinned'])): ?>
                                        <span class="badge bg-warning text-dark"><i class="fas fa-thumbtack me-1"></i> Pinned</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-muted small mb-0"><?= nl2br(htmlspecialchars((string) $announcement['content'])) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal: Schedule Event -->
    <div class="modal fade cocurricular-modal" id="scheduleEventModal" tabindex="-1" aria-labelledby="scheduleEventModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="?club_id=<?= (int) $activeClub['id'] ?>&tab=events" id="scheduleEventForm">
                    <input type="hidden" name="action" value="create_event">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrfToken') ? csrfToken() : '') ?>">

                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="scheduleEventModalLabel">Schedule Club Event</h5>
                            <p class="small text-muted mb-0" id="scheduleEventSubtitle"><?= htmlspecialchars((string) $activeClub['club_name']) ?></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div id="scheduleEventErrorAlert" class="alert alert-danger py-1 px-2 small mb-3 d-none"></div>

                        <div class="mb-3">
                            <label for="eventTitle" class="form-label fw-semibold small">Event Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="eventTitle" name="title" required placeholder="e.g., General Assembly & Orientation">
                        </div>
                        <div class="mb-3">
                            <label for="eventType" class="form-label fw-semibold small">Event Type</label>
                            <select class="form-select" id="eventType" name="event_type">
                                <option value="General Assembly">General Assembly</option>
                                <option value="Workshop">Workshop / Seminar</option>
                                <option value="Meeting">Officer / Committee Meeting</option>
                                <option value="Competition">Competition / Contest</option>
                                <option value="Community Activity">Outreach / Community Service</option>
                            </select>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label for="eventDate" class="form-label fw-semibold small">Event Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="eventDate" name="event_date" required min="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-sm-6">
                                <label for="eventVenue" class="form-label fw-semibold small">Venue</label>
                                <input type="text" class="form-control" id="eventVenue" name="venue" placeholder="e.g., AVR 1 / Main Hall">
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label for="startTime" class="form-label fw-semibold small">Start Time</label>
                                <input type="time" class="form-control" id="startTime" name="start_time" value="09:00">
                            </div>
                            <div class="col-sm-6">
                                <label for="endTime" class="form-label fw-semibold small">End Time</label>
                                <input type="time" class="form-control" id="endTime" name="end_time" value="12:00">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="eventDescription" class="form-label fw-semibold small">Description</label>
                            <textarea class="form-control" id="eventDescription" name="description" rows="3" placeholder="Brief outline of the activity..."></textarea>
                        </div>

                        <!-- Compact AI Assistant Helper Card -->
                        <div class="cocurricular-ai-helper-card mb-3" id="eventAiHelperCard">
                            <div class="cocurricular-ai-helper-content">
                                <span class="cocurricular-ai-sparkle-icon" aria-hidden="true">✦</span>
                                <div class="cocurricular-ai-helper-text">
                                    <div class="cocurricular-ai-helper-title">Need help writing this?</div>
                                    <div class="cocurricular-ai-helper-subtitle">Let AI help draft a professional event description based on the event details above.</div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm cocurricular-ai-generate-btn" id="eventAiGenerateBtn">
                                <i class="fas fa-magic me-1"></i> Generate with AI
                            </button>
                        </div>

                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="eventPinned" name="is_pinned" value="1">
                            <label class="form-check-label small" for="eventPinned">Pin to top of calendar</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="scheduleEventSubmitBtn">
                            <i class="fas fa-save me-1" id="scheduleEventSubmitIcon"></i>
                            <span id="scheduleEventSubmitText">Save & Publish Event</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal 2: AI Event Description Assistant (#eventAiModal) -->
    <div class="modal fade cocurricular-modal" id="eventAiModal" tabindex="-1" aria-labelledby="eventAiModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 560px;">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="eventAiModalLabel">Generate Event Description</h5>
                        <p class="small text-muted mb-0" id="eventAiModalSubtitle"><?= htmlspecialchars((string) $activeClub['club_name']) ?></p>
                    </div>
                    <button type="button" class="btn-close" id="closeEventAiModalBtn" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- VIEW 1: AI Generation Setup -->
                <div id="eventAiSetupView">
                    <div class="modal-body">
                        <!-- Read-only Event Details Context -->
                        <div class="p-3 rounded mb-3 cocurricular-event-context-box">
                            <div class="text-uppercase small fw-semibold text-muted mb-2" style="font-size: 0.72rem; letter-spacing: 0.05em;">Event details</div>
                            <div class="fw-bold mb-1" id="eventAiContextTitle" style="font-size: 0.98rem;">—</div>
                            <div class="d-flex flex-wrap gap-2 align-items-center text-muted small mb-1">
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 small" id="eventAiContextType">Event</span>
                                <span id="eventAiContextDateTime"><i class="far fa-calendar-alt me-1"></i>—</span>
                            </div>
                            <div class="text-muted small" id="eventAiContextVenue">
                                <i class="fas fa-map-marker-alt me-1 text-secondary"></i><span>—</span>
                            </div>
                        </div>

                        <!-- Optional Instructions -->
                        <div class="mb-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label for="eventAiInstructions" class="form-label small fw-semibold mb-0">Additional instructions</label>
                                <span class="small text-muted" style="font-size: 0.75rem;">Optional</span>
                            </div>
                            <textarea class="form-control form-control-sm" id="eventAiInstructions" rows="3" placeholder="Example: Emphasize student participation and keep the tone suitable for club members."></textarea>
                        </div>

                        <div id="eventAiSetupAlert" class="alert alert-danger py-2 px-3 small mt-3 mb-0 d-none"></div>
                    </div>
                    <div class="modal-footer d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="cancelEventAiBtn" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary btn-sm px-3" id="executeEventAiBtn">
                            <i class="fas fa-magic me-1" id="executeEventAiIcon"></i>
                            <span id="executeEventAiText">Generate</span>
                        </button>
                    </div>
                </div>

                <!-- VIEW 2: Review Generated Description -->
                <div id="eventAiReviewView" class="d-none">
                    <div class="modal-body">
                        <div class="mb-2">
                            <label for="eventAiGeneratedText" class="form-label small fw-semibold mb-1">Generated description</label>
                            <textarea class="form-control form-control-sm cocurricular-ai-result-textarea" id="eventAiGeneratedText" rows="6" placeholder="Generated description will appear here..."></textarea>
                        </div>

                        <!-- Model Metadata -->
                        <div class="d-flex justify-content-between align-items-center pt-1 text-muted" style="font-size: 0.75rem;">
                            <span id="eventAiMetadata">Gemini · Generated just now</span>
                        </div>

                        <div id="eventAiReviewAlert" class="alert alert-danger py-2 px-3 small mt-2 mb-0 d-none"></div>
                    </div>
                    <div class="modal-footer d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="eventAiRegenerateBtn">
                            <i class="fas fa-sync-alt me-1" id="eventAiRegenIcon"></i>
                            <span id="eventAiRegenText">Regenerate</span>
                        </button>
                        <button type="button" class="btn btn-primary btn-sm px-3" id="eventAiUseDescriptionBtn">
                            <i class="fas fa-check me-1"></i> Use This Description
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Modal: Post Announcement -->
    <div class="modal fade cocurricular-modal" id="postAnnouncementModal" tabindex="-1" aria-labelledby="postAnnouncementModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="?club_id=<?= (int) $activeClub['id'] ?>&tab=announcements" id="postAnnouncementForm">
                    <input type="hidden" name="action" value="create_announcement">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrfToken') ? csrfToken() : '') ?>">
                    <input type="hidden" id="annIsAiGenerated" name="is_ai_generated" value="0">
                    <input type="hidden" id="annAiModel" name="ai_model" value="">
                    <input type="hidden" id="annAiGeneratedAt" name="ai_generated_at" value="">

                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="postAnnouncementModalLabel">Post Club Announcement</h5>
                            <p class="small text-muted mb-0" id="postAnnouncementSubtitle"><?= htmlspecialchars((string) $activeClub['club_name']) ?></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <!-- VIEW 1: Main Announcement Form (Default) -->
                    <div id="annMainView">
                        <div class="modal-body">
                            <div id="annAiSuccessBanner" class="alert alert-success py-1 px-2 small mb-3 d-none align-items-center justify-content-between">
                                <span><i class="fas fa-check-circle me-1"></i> AI draft applied. Review and edit before posting.</span>
                                <button type="button" class="btn-close btn-close-sm ms-2" id="dismissAiSuccessBtn" aria-label="Close"></button>
                            </div>

                            <div class="mb-3">
                                <label for="annTitle" class="form-label fw-semibold small">Announcement Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="annTitle" name="title" required placeholder="e.g., Notice of Monthly Meeting">
                            </div>

                            <div class="mb-3">
                                <label for="annContent" class="form-label fw-semibold small">Announcement Message <span class="text-danger">*</span></label>
                                <div class="cocurricular-textarea-wrap">
                                    <textarea class="form-control" id="annContent" name="content" rows="4" maxlength="2000" required placeholder="Write your announcement details here..."></textarea>
                                    <span class="cocurricular-char-count" id="annCharCount">0/2000</span>
                                </div>
                            </div>

                            <!-- Embedded AI Assistant Helper Card -->
                            <div class="cocurricular-ai-helper-card mb-3">
                                <div class="cocurricular-ai-helper-content">
                                    <span class="cocurricular-ai-sparkle-icon" aria-hidden="true">✦</span>
                                    <div class="cocurricular-ai-helper-text">
                                        <div class="cocurricular-ai-helper-title">Need help writing this?</div>
                                        <div class="cocurricular-ai-helper-subtitle">Let AI generate a professional announcement for you.</div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm cocurricular-ai-generate-btn" id="openAiPromptBtn">
                                    <span class="me-1">✦</span> Generate with AI
                                </button>
                            </div>

                            <div class="form-check form-switch mb-1">
                                <input class="form-check-input" type="checkbox" id="annPinned" name="is_pinned" value="1">
                                <label class="form-check-label small" for="annPinned">Pin announcement to top of feed</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="postAnnouncementSubmitBtn">
                                <i class="fas fa-paper-plane me-1"></i> Post Announcement
                            </button>
                        </div>
                    </div>

                    <!-- VIEW 2: AI Generation Prompt -->
                    <div id="annAiPromptView" class="d-none">
                        <div class="modal-body">
                            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="cocurricular-ai-sparkle-icon">✦</span>
                                    <h6 class="fw-bold mb-0">Generate Announcement with AI</h6>
                                </div>
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 small">AI Assistant</span>
                            </div>

                            <?php if (!empty($clubEvents)): ?>
                                <div class="mb-3">
                                    <label for="aiRelatedEvent" class="form-label fw-semibold small">Related Event / Activity (Optional)</label>
                                    <select class="form-select form-select-sm" id="aiRelatedEvent">
                                        <option value="">-- None (General Announcement) --</option>
                                        <?php foreach ($clubEvents as $eventItem): ?>
                                            <?php
                                            $evDate = !empty($eventItem['event_date']) ? date('M j, Y', strtotime((string) $eventItem['event_date'])) : '';
                                            $evVenue = (string) ($eventItem['venue'] ?? '');
                                            $evDesc = (string) ($eventItem['description'] ?? '');
                                            ?>
                                            <option value="<?= (int) $eventItem['id'] ?>"
                                                    data-title="<?= htmlspecialchars((string) $eventItem['title'], ENT_QUOTES) ?>"
                                                    data-date="<?= htmlspecialchars($evDate, ENT_QUOTES) ?>"
                                                    data-venue="<?= htmlspecialchars($evVenue, ENT_QUOTES) ?>"
                                                    data-desc="<?= htmlspecialchars($evDesc, ENT_QUOTES) ?>">
                                                <?= htmlspecialchars((string) $eventItem['title']) ?> (<?= htmlspecialchars($evDate) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text small">Select an existing event to automatically pre-fill details.</div>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="aiTopic" class="form-label fw-semibold small">Announcement Topic / Subject <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="aiTopic" placeholder="e.g., Monthly General Assembly">
                            </div>

                            <div class="mb-3">
                                <label for="aiDetails" class="form-label fw-semibold small">Key Details / Points <span class="text-danger">*</span></label>
                                <textarea class="form-control form-control-sm" id="aiDetails" rows="3" placeholder="Discuss upcoming activities, membership updates, schedule, agenda..."></textarea>
                            </div>

                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label for="aiAudience" class="form-label fw-semibold small">Target Audience</label>
                                    <input type="text" class="form-control form-control-sm" id="aiAudience" value="All club members" placeholder="e.g., All club members">
                                </div>
                                <div class="col-md-6">
                                    <label for="aiTone" class="form-label fw-semibold small">Tone</label>
                                    <select class="form-select form-select-sm" id="aiTone">
                                        <option value="Professional" selected>Professional</option>
                                        <option value="Enthusiastic">Enthusiastic</option>
                                        <option value="Formal Academic">Formal Academic</option>
                                        <option value="Urgent Notice">Urgent Notice</option>
                                        <option value="Friendly & Welcoming">Friendly & Welcoming</option>
                                    </select>
                                </div>
                            </div>

                            <div id="aiPromptAlert" class="alert alert-danger py-1 px-2 small mt-2 d-none"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="cancelAiPromptBtn">Cancel</button>
                            <button type="button" class="btn btn-primary btn-sm" id="submitGenerateAiBtn">
                                <span id="generateAiBtnIcon" class="me-1">✦</span>
                                <span id="generateAiBtnText">Generate</span>
                            </button>
                        </div>
                    </div>

                    <!-- VIEW 3: AI Generated Draft Preview -->
                    <div id="annAiDraftView" class="d-none">
                        <div class="modal-body">
                            <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="cocurricular-ai-sparkle-icon">✦</span>
                                    <h6 class="fw-bold mb-0">AI Generated Draft</h6>
                                </div>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 small">
                                    <i class="fas fa-check-circle me-1"></i> Draft Ready
                                </span>
                            </div>

                            <p class="text-muted small mb-2">Review or edit this generated announcement before using it.</p>

                            <div class="mb-3">
                                <label for="aiDraftTitle" class="form-label fw-semibold small">Title</label>
                                <input type="text" class="form-control form-control-sm" id="aiDraftTitle">
                            </div>

                            <div class="mb-2">
                                <label for="aiDraftMessage" class="form-label fw-semibold small">Message</label>
                                <textarea class="form-control form-control-sm" id="aiDraftMessage" rows="5"></textarea>
                            </div>

                            <div class="d-flex justify-content-between align-items-center text-muted small mb-2">
                                <span id="aiDraftModelBadge"><i class="fas fa-magic me-1"></i> Gemini</span>
                                <span id="aiDraftTimeBadge"></span>
                            </div>

                            <div id="aiDraftAlert" class="alert alert-danger py-1 px-2 small mt-2 d-none"></div>
                        </div>
                        <div class="modal-footer d-flex justify-content-between">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="backToAiPromptBtn">
                                    <i class="fas fa-arrow-left me-1"></i> Edit Details
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" id="regenerateAiBtn">
                                    <i class="fas fa-sync-alt me-1" id="regenIcon"></i>
                                    <span id="regenText">Regenerate</span>
                                </button>
                            </div>
                            <button type="button" class="btn btn-success btn-sm" id="useAiDraftBtn">
                                <i class="fas fa-check me-1"></i> Use Draft
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Modal: View Application Details -->
    <div class="modal fade cocurricular-modal" id="adviserAppDetailsModal" tabindex="-1" aria-labelledby="adviserAppDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="adviserAppDetailsModalLabel">Applicant Details</h5>
                        <p class="small text-muted mb-0">Membership application submission</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="adviserAppDetailsBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status" aria-label="Loading details"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Reject Application with Reason -->
    <div class="modal fade cocurricular-modal" id="adviserRejectModal" tabindex="-1" aria-labelledby="adviserRejectModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="<?= BASE_URL ?>/modules/cocurricular/pages/osa-process-application.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="application_id" id="adviserRejectAppId" value="">
                    <input type="hidden" name="return_to" value="faculty-adviser">

                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="adviserRejectModalLabel">Reject Application</h5>
                            <p class="small text-muted mb-0">State the reason for rejecting this application.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">Applicant: <strong id="adviserRejectStudentName">—</strong></p>
                        <div class="mb-3">
                            <label for="adviserRejectionReason" class="form-label small fw-semibold">Reason for Rejection <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="adviserRejectionReason" name="rejection_reason" rows="3" required placeholder="Provide an explanation that will be visible to the student..."></textarea>
                            <div class="form-text small">This reason will be displayed to the student on their membership status dashboard.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-times me-1"></i> Confirm Rejection
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Edit Event -->
    <div class="modal fade cocurricular-modal" id="editEventModal" tabindex="-1" aria-labelledby="editEventModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="?club_id=<?= (int) $activeClub['id'] ?>&tab=events">
                    <input type="hidden" name="action" value="edit_event">
                    <input type="hidden" name="event_id" id="editEventId" value="">
                    <?= csrfField() ?>

                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="editEventModalLabel">Edit Activity / Event</h5>
                            <p class="small text-muted mb-0"><?= htmlspecialchars((string) $activeClub['club_name']) ?></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="editEventTitle" class="form-label fw-semibold small">Event Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="editEventTitle" name="title" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label for="editEventType" class="form-label fw-semibold small">Event Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="editEventType" name="event_type" required>
                                    <option value="General Meeting">General Meeting</option>
                                    <option value="Workshop">Workshop</option>
                                    <option value="Competition">Competition</option>
                                    <option value="Seminar">Seminar</option>
                                    <option value="Social Event">Social Event</option>
                                    <option value="Community Outreach">Community Outreach</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label for="editEventDate" class="form-label fw-semibold small">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="editEventDate" name="event_date" required>
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label for="editEventStartTime" class="form-label fw-semibold small">Start Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="editEventStartTime" name="start_time" required>
                            </div>
                            <div class="col-sm-6">
                                <label for="editEventEndTime" class="form-label fw-semibold small">End Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="editEventEndTime" name="end_time" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="editEventVenue" class="form-label fw-semibold small">Venue / Room</label>
                            <input type="text" class="form-control" id="editEventVenue" name="venue">
                        </div>
                        <div class="mb-3">
                            <label for="editEventDescription" class="form-label fw-semibold small">Description</label>
                            <textarea class="form-control" id="editEventDescription" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="editEventPinned" name="is_pinned" value="1">
                            <label class="form-check-label small" for="editEventPinned">Pin to top of calendar</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Update Event
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: View & Review Event Participants -->
    <div class="modal fade cocurricular-modal" id="eventParticipantsModal" tabindex="-1" aria-labelledby="eventParticipantsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="eventParticipantsModalLabel">Event Participants</h5>
                        <p class="small text-muted mb-0" id="eventParticipantsTitle">—</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="eventParticipantsBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status" aria-label="Loading participants"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Reject Event Participant with Note -->
    <div class="modal fade cocurricular-modal" id="adviserRejectParticipantModal" tabindex="-1" aria-labelledby="adviserRejectParticipantModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="?club_id=<?= (int) $activeClub['id'] ?>&tab=events">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="review_event_participant">
                    <input type="hidden" name="status" value="Rejected">
                    <input type="hidden" name="participant_id" id="adviserRejectParticipantId" value="">

                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="adviserRejectParticipantModalLabel">Reject Event Registration</h5>
                            <p class="small text-muted mb-0">State the reason for rejecting this student's participation.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">Student: <strong id="adviserRejectParticipantName">—</strong></p>
                        <div class="mb-3">
                            <label for="adviserParticipantRejectionNote" class="form-label small fw-semibold">Reason / Rejection Note <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="adviserParticipantRejectionNote" name="rejection_note" rows="3" required placeholder="Provide an explanation for rejecting this request..."></textarea>
                            <div class="form-text small">This note is required and will be saved in the participant record.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-times me-1"></i> Confirm Rejection
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // Handle Details Modal fetch
        var detailsBody = document.getElementById('adviserAppDetailsBody');
        document.querySelectorAll('.js-adviser-view-app').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var appId = btn.getAttribute('data-application-id');
                if (!detailsBody) return;
                detailsBody.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';

                fetch('<?= BASE_URL ?>/modules/cocurricular/pages/osa-application-details.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: 'application_id=' + encodeURIComponent(appId)
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success || !data.application) {
                        throw new Error(data.message || 'Unable to load application details.');
                    }
                    var a = data.application;
                    var html = '<div class="row g-3">' +
                        '<div class="col-md-6"><h6 class="fw-bold">Student Profile</h6>' +
                        '<p class="mb-1"><strong>Name:</strong> ' + escapeHtml(a.student_name) + '</p>' +
                        '<p class="mb-1"><strong>Student ID:</strong> <code>' + escapeHtml(a.student_id) + '</code></p>' +
                        '<p class="mb-0"><strong>Email:</strong> ' + escapeHtml(a.student_email || '—') + '</p></div>' +
                        '<div class="col-md-6"><h6 class="fw-bold">Submission Details</h6>' +
                        '<p class="mb-1"><strong>Date:</strong> ' + escapeHtml(a.submitted_at) + '</p>' +
                        '<p class="mb-1"><strong>Participation:</strong> ' + escapeHtml(a.preferred_participation || '—') + '</p>' +
                        '<p class="mb-0"><strong>Agreement:</strong> ' + (a.agreement ? '<span class="badge bg-success">Confirmed</span>' : '<span class="badge bg-secondary">None</span>') + '</p></div>' +
                        '<div class="col-12"><hr class="my-2"><h6 class="fw-bold">Reason for Joining</h6>' +
                        '<p class="text-muted small mb-3">' + nl2brHtml(escapeHtml(a.reason_for_joining || '')) + '</p>' +
                        '<h6 class="fw-bold">Areas of Interest</h6>' +
                        '<p class="text-muted small mb-0">' + nl2brHtml(escapeHtml(a.areas_of_interest || '')) + '</p></div>' +
                        '</div>';
                    detailsBody.innerHTML = html;
                })
                .catch(function (err) {
                    detailsBody.innerHTML = '<div class="alert alert-danger mb-0">' + escapeHtml(err.message) + '</div>';
                });
            });
        });

        // Handle Reject Membership Modal data setup
        document.querySelectorAll('.js-adviser-reject-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var appId = btn.getAttribute('data-application-id');
                var studentName = btn.getAttribute('data-student-name') || 'Applicant';
                var appIdInput = document.getElementById('adviserRejectAppId');
                var nameSpan = document.getElementById('adviserRejectStudentName');
                var reasonInput = document.getElementById('adviserRejectionReason');
                if (appIdInput) appIdInput.value = appId;
                if (nameSpan) nameSpan.textContent = studentName;
                if (reasonInput) reasonInput.value = '';
            });
        });

        // Handle Edit Event Modal prefill
        document.querySelectorAll('.js-adviser-edit-event').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var eventId = btn.getAttribute('data-event-id');
                var title = btn.getAttribute('data-title') || '';
                var eventType = btn.getAttribute('data-event-type') || 'General Meeting';
                var eventDate = btn.getAttribute('data-event-date') || '';
                var startTime = btn.getAttribute('data-start-time') || '';
                var endTime = btn.getAttribute('data-end-time') || '';
                var venue = btn.getAttribute('data-venue') || '';
                var description = btn.getAttribute('data-description') || '';
                var isPinned = btn.getAttribute('data-is-pinned') === '1';

                var idInput = document.getElementById('editEventId');
                var titleInput = document.getElementById('editEventTitle');
                var typeInput = document.getElementById('editEventType');
                var dateInput = document.getElementById('editEventDate');
                var startInput = document.getElementById('editEventStartTime');
                var endInput = document.getElementById('editEventEndTime');
                var venueInput = document.getElementById('editEventVenue');
                var descInput = document.getElementById('editEventDescription');
                var pinnedInput = document.getElementById('editEventPinned');

                if (idInput) idInput.value = eventId;
                if (titleInput) titleInput.value = title;
                if (typeInput) typeInput.value = eventType;
                if (dateInput) dateInput.value = eventDate;
                if (startInput) startInput.value = startTime;
                if (endInput) endInput.value = endTime;
                if (venueInput) venueInput.value = venue;
                if (descInput) descInput.value = description;
                if (pinnedInput) pinnedInput.checked = isPinned;
            });
        });

        // Handle Event Participants Modal fetch
        var partBody = document.getElementById('eventParticipantsBody');
        var partTitle = document.getElementById('eventParticipantsTitle');
        document.querySelectorAll('.js-adviser-view-participants').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var eventId = btn.getAttribute('data-event-id');
                var title = btn.getAttribute('data-event-title') || 'Event';
                if (partTitle) partTitle.textContent = title;
                if (!partBody) return;

                partBody.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';

                fetch('?club_id=<?= (int) $activeClub['id'] ?>&tab=events&action=fetch_event_participants&event_id=' + encodeURIComponent(eventId), {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin'
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success) {
                        throw new Error(data.message || 'Unable to load participants.');
                    }
                    var list = data.participants || [];
                    if (list.length === 0) {
                        partBody.innerHTML = '<div class="p-4 text-center text-muted"><i class="fas fa-users-slash fs-3 d-block mb-2"></i>No student registrations for this event yet.</div>';
                        return;
                    }

                    var csrfHtml = '<?= csrfField() ?>';
                    var html = '<div class="table-responsive"><table class="table table-hover align-middle mb-0 small"><thead class="table-light"><tr>' +
                        '<th>Student</th><th>Student ID</th><th>Registered</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>';

                    list.forEach(function (p) {
                        var statusBadge = '';
                        if (p.status === 'Approved') {
                            statusBadge = '<span class="badge bg-success">Approved</span>';
                        } else if (p.status === 'Rejected') {
                            statusBadge = '<span class="badge bg-danger">Rejected</span>';
                            if (p.rejection_note) {
                                statusBadge += '<div class="small text-danger mt-1"><em>' + escapeHtml(p.rejection_note) + '</em></div>';
                            }
                        } else {
                            statusBadge = '<span class="badge bg-warning text-dark">Pending</span>';
                        }

                        var actionCol = '';
                        if (p.status === 'Pending') {
                            actionCol = '<div class="cocurricular-table-actions justify-content-end">' +
                                '<form method="POST" action="?club_id=<?= (int) $activeClub['id'] ?>&tab=events" onsubmit="return confirm(\'Approve this participant?\');">' +
                                csrfHtml +
                                '<input type="hidden" name="action" value="review_event_participant">' +
                                '<input type="hidden" name="participant_id" value="' + encodeURIComponent(p.id) + '">' +
                                '<input type="hidden" name="status" value="Approved">' +
                                '<button type="submit" class="btn btn-outline-success cocurricular-icon-btn" title="Approve Participant" aria-label="Approve Participant"><i class="fas fa-check" aria-hidden="true"></i></button>' +
                                '</form>' +
                                '<button type="button" class="btn btn-outline-danger cocurricular-icon-btn js-part-reject-trigger" ' +
                                'data-participant-id="' + encodeURIComponent(p.id) + '" ' +
                                'data-student-name="' + escapeHtml(p.full_name) + '" title="Reject Participant" aria-label="Reject Participant">' +
                                '<i class="fas fa-times" aria-hidden="true"></i></button>' +
                                '</div>';
                        } else {
                            actionCol = '<span class="text-muted small">Reviewed</span>';
                        }

                        html += '<tr>' +
                            '<td class="fw-semibold">' + escapeHtml(p.full_name) + '</td>' +
                            '<td><code>' + escapeHtml(p.student_id) + '</code></td>' +
                            '<td>' + escapeHtml(p.registered_at) + '</td>' +
                            '<td>' + statusBadge + '</td>' +
                            '<td class="text-end">' + actionCol + '</td>' +
                            '</tr>';
                    });

                    html += '</tbody></table></div>';
                    partBody.innerHTML = html;

                    // Bind reject triggers
                    partBody.querySelectorAll('.js-part-reject-trigger').forEach(function (rBtn) {
                        rBtn.addEventListener('click', function () {
                            var pId = rBtn.getAttribute('data-participant-id');
                            var sName = rBtn.getAttribute('data-student-name') || 'Student';
                            var pIdInput = document.getElementById('adviserRejectParticipantId');
                            var pNameSpan = document.getElementById('adviserRejectParticipantName');
                            var noteInput = document.getElementById('adviserParticipantRejectionNote');
                            if (pIdInput) pIdInput.value = pId;
                            if (pNameSpan) pNameSpan.textContent = sName;
                            if (noteInput) noteInput.value = '';

                            var rejectModalEl = document.getElementById('adviserRejectParticipantModal');
                            if (rejectModalEl && typeof bootstrap !== 'undefined') {
                                var partModalEl = document.getElementById('eventParticipantsModal');
                                if (partModalEl) {
                                    var partModal = bootstrap.Modal.getInstance(partModalEl);
                                    if (partModal) partModal.hide();
                                }
                                var rejectModal = new bootstrap.Modal(rejectModalEl);
                                rejectModal.show();
                            }
                        });
                    });
                })
                .catch(function (err) {
                    partBody.innerHTML = '<div class="alert alert-danger mb-0">' + escapeHtml(err.message) + '</div>';
                });
            });
        });

        // ==========================================
        // Announcement Creation & AI Generation Flow
        // ==========================================
        var postAnnModalEl = document.getElementById('postAnnouncementModal');
        var annMainView = document.getElementById('annMainView');
        var annAiPromptView = document.getElementById('annAiPromptView');
        var annAiDraftView = document.getElementById('annAiDraftView');
        var annSubtitle = document.getElementById('postAnnouncementSubtitle');
        var clubOriginalName = '<?= htmlspecialchars((string) ($activeClub['club_name'] ?? 'Club'), ENT_QUOTES) ?>';

        var annTitleInput = document.getElementById('annTitle');
        var annContentInput = document.getElementById('annContent');
        var annCharCountSpan = document.getElementById('annCharCount');
        var annIsAiGenerated = document.getElementById('annIsAiGenerated');
        var annAiModel = document.getElementById('annAiModel');
        var annAiGeneratedAt = document.getElementById('annAiGeneratedAt');
        var annAiSuccessBanner = document.getElementById('annAiSuccessBanner');

        var openAiPromptBtn = document.getElementById('openAiPromptBtn');
        var cancelAiPromptBtn = document.getElementById('cancelAiPromptBtn');
        var submitGenerateAiBtn = document.getElementById('submitGenerateAiBtn');
        var generateAiBtnIcon = document.getElementById('generateAiBtnIcon');
        var generateAiBtnText = document.getElementById('generateAiBtnText');

        var aiRelatedEvent = document.getElementById('aiRelatedEvent');
        var aiTopicInput = document.getElementById('aiTopic');
        var aiDetailsInput = document.getElementById('aiDetails');
        var aiAudienceInput = document.getElementById('aiAudience');
        var aiToneInput = document.getElementById('aiTone');
        var aiPromptAlert = document.getElementById('aiPromptAlert');

        var aiDraftTitleInput = document.getElementById('aiDraftTitle');
        var aiDraftMessageInput = document.getElementById('aiDraftMessage');
        var aiDraftModelBadge = document.getElementById('aiDraftModelBadge');
        var aiDraftTimeBadge = document.getElementById('aiDraftTimeBadge');
        var aiDraftAlert = document.getElementById('aiDraftAlert');
        var backToAiPromptBtn = document.getElementById('backToAiPromptBtn');
        var regenerateAiBtn = document.getElementById('regenerateAiBtn');
        var regenIcon = document.getElementById('regenIcon');
        var regenText = document.getElementById('regenText');
        var useAiDraftBtn = document.getElementById('useAiDraftBtn');
        var dismissAiSuccessBtn = document.getElementById('dismissAiSuccessBtn');

        function updateAnnCharCounter() {
            if (!annContentInput || !annCharCountSpan) return;
            var len = annContentInput.value.length;
            annCharCountSpan.textContent = len + '/2000';
        }

        if (annContentInput) {
            annContentInput.addEventListener('input', updateAnnCharCounter);
            updateAnnCharCounter();
        }

        if (dismissAiSuccessBtn && annAiSuccessBanner) {
            dismissAiSuccessBtn.addEventListener('click', function () {
                annAiSuccessBanner.classList.add('d-none');
                annAiSuccessBanner.classList.remove('d-flex');
            });
        }

        var postAnnForm = document.getElementById('postAnnouncementForm');
        var currentAnnView = 'main';

        function showAnnView(viewName) {
            currentAnnView = viewName;
            if (annMainView) annMainView.classList.add('d-none');
            if (annAiPromptView) annAiPromptView.classList.add('d-none');
            if (annAiDraftView) annAiDraftView.classList.add('d-none');

            // Toggle HTML5 required attributes so hidden inputs do not fail form validation or trigger unhandled browser errors
            if (annTitleInput) annTitleInput.required = (viewName === 'main');
            if (annContentInput) annContentInput.required = (viewName === 'main');

            if (viewName === 'prompt' && annAiPromptView) {
                annAiPromptView.classList.remove('d-none');
                if (annSubtitle) annSubtitle.textContent = clubOriginalName + ' — AI Assistant';
                if (aiTopicInput) aiTopicInput.focus();
            } else if (viewName === 'draft' && annAiDraftView) {
                annAiDraftView.classList.remove('d-none');
                if (annSubtitle) annSubtitle.textContent = clubOriginalName + ' — Draft Review';
                if (aiDraftTitleInput) aiDraftTitleInput.focus();
            } else if (annMainView) {
                annMainView.classList.remove('d-none');
                if (annSubtitle) annSubtitle.textContent = clubOriginalName;
            }
        }

        if (postAnnForm) {
            postAnnForm.addEventListener('submit', function (e) {
                if (currentAnnView === 'prompt') {
                    e.preventDefault();
                    if (submitGenerateAiBtn) submitGenerateAiBtn.click();
                    return false;
                }
                if (currentAnnView === 'draft') {
                    e.preventDefault();
                    return false;
                }
            });
        }

        [aiTopicInput, aiAudienceInput].forEach(function (inp) {
            if (inp) {
                inp.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        if (submitGenerateAiBtn) submitGenerateAiBtn.click();
                    }
                });
            }
        });

        if (openAiPromptBtn) {
            openAiPromptBtn.addEventListener('click', function () {
                if (aiPromptAlert) aiPromptAlert.classList.add('d-none');
                if (aiTopicInput && !aiTopicInput.value.trim() && annTitleInput && annTitleInput.value.trim()) {
                    aiTopicInput.value = annTitleInput.value.trim();
                }
                if (aiDetailsInput && !aiDetailsInput.value.trim() && annContentInput && annContentInput.value.trim()) {
                    aiDetailsInput.value = annContentInput.value.trim();
                }
                showAnnView('prompt');
            });
        }

        if (cancelAiPromptBtn) {
            cancelAiPromptBtn.addEventListener('click', function () {
                showAnnView('main');
            });
        }

        if (aiRelatedEvent) {
            aiRelatedEvent.addEventListener('change', function () {
                var selected = aiRelatedEvent.options[aiRelatedEvent.selectedIndex];
                if (!selected || !selected.value) return;
                var eTitle = selected.getAttribute('data-title') || '';
                var eDate = selected.getAttribute('data-date') || '';
                var eVenue = selected.getAttribute('data-venue') || '';
                var eDesc = selected.getAttribute('data-desc') || '';

                if (aiTopicInput) aiTopicInput.value = eTitle;
                var detailsArr = [];
                if (eDesc) detailsArr.push(eDesc);
                if (eDate) detailsArr.push('Date: ' + eDate);
                if (eVenue) detailsArr.push('Venue: ' + eVenue);
                if (aiDetailsInput) aiDetailsInput.value = detailsArr.join('\n');
            });
        }

        function executeAiGeneration(btn, icon, textSpan, isRegen) {
            var topic = aiTopicInput ? aiTopicInput.value.trim() : '';
            var details = aiDetailsInput ? aiDetailsInput.value.trim() : '';
            var targetAlert = isRegen ? aiDraftAlert : aiPromptAlert;

            if (targetAlert) targetAlert.classList.add('d-none');

            if (!topic && !details && (!aiRelatedEvent || !aiRelatedEvent.value)) {
                if (targetAlert) {
                    targetAlert.textContent = 'Please enter a topic or key details for the AI announcement.';
                    targetAlert.classList.remove('d-none');
                }
                return;
            }

            if (btn) btn.disabled = true;
            if (icon) icon.className = 'fas fa-spinner fa-spin me-1';
            if (textSpan) textSpan.textContent = isRegen ? 'Regenerating...' : 'Generating...';

            var selectedEventId = (aiRelatedEvent && aiRelatedEvent.value) ? parseInt(aiRelatedEvent.value, 10) : 0;

            var payload = {
                club_id: <?= (int) ($activeClub['id'] ?? 0) ?>,
                event_id: selectedEventId,
                topic: topic,
                details: details,
                audience: aiAudienceInput ? aiAudienceInput.value.trim() : '',
                additional_instructions: 'Tone: ' + (aiToneInput ? aiToneInput.value.trim() : 'Professional'),
                csrf_token: '<?= htmlspecialchars(function_exists('csrfToken') ? csrfToken() : '', ENT_QUOTES) ?>'
            };

            fetch('<?= BASE_URL ?>/modules/cocurricular/endpoints/generate-announcement.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': '<?= htmlspecialchars(function_exists('csrfToken') ? csrfToken() : '', ENT_QUOTES) ?>'
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            })
            .then(function (res) {
                return res.text().then(function (text) {
                    var data = null;
                    try {
                        data = text ? JSON.parse(text) : {};
                    } catch (e) {
                        data = null;
                    }
                    return {
                        ok: res.ok,
                        status: res.status,
                        data: data,
                        rawText: text
                    };
                });
            })
            .then(function (resObj) {
                if (resObj.data === null) {
                    throw new Error('AI generation failed because the server returned an invalid response. Please try again.');
                }
                var data = resObj.data;
                var draft = data.data || data.draft;
                if (!resObj.ok || !data.success || !draft) {
                    throw new Error(data.error || data.message || 'Unable to generate announcement draft.');
                }

                if (aiDraftTitleInput) aiDraftTitleInput.value = draft.title || '';
                if (aiDraftMessageInput) aiDraftMessageInput.value = draft.content || '';

                var provider = (data.provider || '').toLowerCase();
                var model = data.model || '';
                var isFallback = !!(data.fallback_used || provider === 'local' || model === 'local-fallback');

                var modelLabel = 'AI Assistant';
                var modelIcon = 'fas fa-robot';
                var modelBadgeClass = 'badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 small';

                if (provider === 'gemini') {
                    var displayModel = model ? model.replace(/^models\//, '') : 'Gemini';
                    modelLabel = 'Gemini ' + displayModel;
                    modelIcon = 'fas fa-magic';
                    modelBadgeClass = 'badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 small';
                } else if (provider === 'openai') {
                    modelLabel = 'OpenAI ' + model;
                    modelIcon = 'fas fa-robot';
                    modelBadgeClass = 'badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 small';
                } else if (isFallback) {
                    modelLabel = 'Local fallback';
                    modelIcon = 'fas fa-cogs';
                    modelBadgeClass = 'badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 small';
                } else if (model) {
                    modelLabel = model;
                }

                if (aiDraftModelBadge) {
                    aiDraftModelBadge.className = '';
                    aiDraftModelBadge.innerHTML = '<span class="' + modelBadgeClass + '"><i class="' + modelIcon + ' me-1"></i> ' + escapeHtml(modelLabel) + '</span>';
                }
                if (aiDraftTimeBadge) {
                    var now = new Date();
                    aiDraftTimeBadge.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                }
                if (annAiModel) annAiModel.value = model || (isFallback ? 'local-fallback' : (provider || 'gemini'));
                if (annAiGeneratedAt) annAiGeneratedAt.value = data.generated_at || new Date().toISOString();

                showAnnView('draft');
            })
            .catch(function (err) {
                if (targetAlert) {
                    targetAlert.textContent = err.message || 'Failed to generate announcement. Please try again.';
                    targetAlert.classList.remove('d-none');
                }
            })
            .finally(function () {
                if (btn) btn.disabled = false;
                if (icon) icon.className = isRegen ? 'fas fa-sync-alt me-1' : 'me-1';
                if (!isRegen && icon) icon.textContent = '✦';
                if (textSpan) textSpan.textContent = isRegen ? 'Regenerate' : 'Generate';
            });
        }

        if (submitGenerateAiBtn) {
            submitGenerateAiBtn.addEventListener('click', function () {
                executeAiGeneration(submitGenerateAiBtn, generateAiBtnIcon, generateAiBtnText, false);
            });
        }

        if (regenerateAiBtn) {
            regenerateAiBtn.addEventListener('click', function () {
                executeAiGeneration(regenerateAiBtn, regenIcon, regenText, true);
            });
        }

        if (backToAiPromptBtn) {
            backToAiPromptBtn.addEventListener('click', function () {
                showAnnView('prompt');
            });
        }

        if (useAiDraftBtn) {
            useAiDraftBtn.addEventListener('click', function () {
                var draftTitle = aiDraftTitleInput ? aiDraftTitleInput.value.trim() : '';
                var draftMsg = aiDraftMessageInput ? aiDraftMessageInput.value.trim() : '';

                if (annTitleInput) annTitleInput.value = draftTitle;
                if (annContentInput) {
                    annContentInput.value = draftMsg;
                    updateAnnCharCounter();
                }
                if (annIsAiGenerated) annIsAiGenerated.value = '1';

                if (annAiSuccessBanner) {
                    annAiSuccessBanner.classList.remove('d-none');
                    annAiSuccessBanner.classList.add('d-flex');
                }

                showAnnView('main');
            });
        }

        if (postAnnModalEl) {
            postAnnModalEl.addEventListener('hidden.bs.modal', function () {
                showAnnView('main');
                if (aiPromptAlert) aiPromptAlert.classList.add('d-none');
                if (aiDraftAlert) aiDraftAlert.classList.add('d-none');
            });

            var annUrlParams = new URLSearchParams(window.location.search);
            if (annUrlParams.get('open_modal') === 'postAnnouncementModal' || annUrlParams.get('open_ann_modal') === '1') {
                var bsModal = new bootstrap.Modal(postAnnModalEl);
                bsModal.show();
                if (annUrlParams.get('ai_view') === 'prompt') {
                    showAnnView('prompt');
                } else if (annUrlParams.get('ai_view') === 'draft') {
                    if (aiDraftTitleInput) aiDraftTitleInput.value = annUrlParams.get('draft_title') || 'Notice of Special Innovation Workshop';
                    if (aiDraftMessageInput) aiDraftMessageInput.value = annUrlParams.get('draft_msg') || 'We are pleased to invite all club members to the upcoming hands-on workshop on Friday at the Innovation Lab.';
                    showAnnView('draft');
                }
            }
        }

        // ==========================================
        // SCHEDULE EVENT MODAL & SEPARATE AI MODAL FLOW
        // ==========================================
        var scheduleEventModalEl = document.getElementById('scheduleEventModal');
        var scheduleEventForm = document.getElementById('scheduleEventForm');
        var scheduleEventErrorAlert = document.getElementById('scheduleEventErrorAlert');
        var scheduleEventSubmitBtn = document.getElementById('scheduleEventSubmitBtn');
        var scheduleEventSubmitIcon = document.getElementById('scheduleEventSubmitIcon');
        var scheduleEventSubmitText = document.getElementById('scheduleEventSubmitText');

        var eventTitleInput = document.getElementById('eventTitle');
        var eventTypeSelect = document.getElementById('eventType');
        var eventDateInput = document.getElementById('eventDate');
        var eventStartTimeInput = document.getElementById('startTime');
        var eventEndTimeInput = document.getElementById('endTime');
        var eventVenueInput = document.getElementById('eventVenue');
        var eventDescriptionInput = document.getElementById('eventDescription');

        var eventAiGenerateBtn = document.getElementById('eventAiGenerateBtn');
        var eventAiModalEl = document.getElementById('eventAiModal');
        var eventAiModalLabel = document.getElementById('eventAiModalLabel');
        var eventAiModalSubtitle = document.getElementById('eventAiModalSubtitle');
        var closeEventAiModalBtn = document.getElementById('closeEventAiModalBtn');
        var cancelEventAiBtn = document.getElementById('cancelEventAiBtn');

        var eventAiSetupView = document.getElementById('eventAiSetupView');
        var eventAiReviewView = document.getElementById('eventAiReviewView');

        var eventAiContextTitle = document.getElementById('eventAiContextTitle');
        var eventAiContextType = document.getElementById('eventAiContextType');
        var eventAiContextDateTime = document.getElementById('eventAiContextDateTime');
        var eventAiContextVenue = document.getElementById('eventAiContextVenue');
        var eventAiInstructionsInput = document.getElementById('eventAiInstructions');

        var eventAiSetupAlert = document.getElementById('eventAiSetupAlert');
        var eventAiReviewAlert = document.getElementById('eventAiReviewAlert');

        var executeEventAiBtn = document.getElementById('executeEventAiBtn');
        var executeEventAiIcon = document.getElementById('executeEventAiIcon');
        var executeEventAiText = document.getElementById('executeEventAiText');

        var eventAiGeneratedText = document.getElementById('eventAiGeneratedText');
        var eventAiMetadata = document.getElementById('eventAiMetadata');
        var eventAiRegenerateBtn = document.getElementById('eventAiRegenerateBtn');
        var eventAiRegenIcon = document.getElementById('eventAiRegenIcon');
        var eventAiRegenText = document.getElementById('eventAiRegenText');
        var eventAiUseDescriptionBtn = document.getElementById('eventAiUseDescriptionBtn');

        function formatEventDate(dateStr) {
            if (!dateStr) return '—';
            try {
                var parts = String(dateStr).split('-');
                if (parts.length === 3) {
                    var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                }
            } catch (e) {}
            return dateStr;
        }

        function formatEventTime(timeStr) {
            if (!timeStr) return '';
            try {
                var parts = String(timeStr).split(':');
                var h = parseInt(parts[0], 10);
                var m = parts[1] || '00';
                var ampm = h >= 12 ? 'PM' : 'AM';
                h = h % 12;
                h = h ? h : 12;
                return h + ':' + m + ' ' + ampm;
            } catch (e) {
                return timeStr;
            }
        }

        function openEventAiModal() {
            var titleVal = eventTitleInput ? eventTitleInput.value.trim() : '';
            var typeVal = eventTypeSelect ? eventTypeSelect.value.trim() : '';

            if (!titleVal || !typeVal) {
                if (scheduleEventErrorAlert) {
                    scheduleEventErrorAlert.textContent = 'Please enter the event title and select an event type first.';
                    scheduleEventErrorAlert.classList.remove('d-none');
                }
                if (!titleVal && eventTitleInput) eventTitleInput.focus();
                else if (!typeVal && eventTypeSelect) eventTypeSelect.focus();
                return;
            }

            if (scheduleEventErrorAlert) {
                scheduleEventErrorAlert.classList.add('d-none');
                scheduleEventErrorAlert.textContent = '';
            }

            // Populate read-only context in #eventAiModal
            if (eventAiContextTitle) eventAiContextTitle.textContent = titleVal;
            if (eventAiContextType) eventAiContextType.textContent = typeVal;

            var dateVal = eventDateInput ? eventDateInput.value : '';
            var sTimeVal = eventStartTimeInput ? eventStartTimeInput.value : '';
            var eTimeVal = eventEndTimeInput ? eventEndTimeInput.value : '';
            var dateStr = formatEventDate(dateVal);
            var timeStr = (sTimeVal && eTimeVal) ? formatEventTime(sTimeVal) + '–' + formatEventTime(eTimeVal) : (sTimeVal ? formatEventTime(sTimeVal) : '');
            var dtCombined = dateStr + (timeStr ? ' · ' + timeStr : '');
            if (eventAiContextDateTime) eventAiContextDateTime.innerHTML = '<i class="far fa-calendar-alt me-1"></i>' + escapeHtml(dtCombined);

            var venueVal = eventVenueInput ? eventVenueInput.value.trim() : '';
            if (eventAiContextVenue) eventAiContextVenue.innerHTML = '<i class="fas fa-map-marker-alt me-1 text-secondary"></i><span>' + escapeHtml(venueVal || 'Campus') + '</span>';

            // Reset AI modal to setup view
            if (eventAiModalLabel) eventAiModalLabel.textContent = 'Generate Event Description';
            if (eventAiModalSubtitle) eventAiModalSubtitle.textContent = '<?= htmlspecialchars((string) $activeClub['club_name']) ?>';
            if (eventAiSetupView) eventAiSetupView.classList.remove('d-none');
            if (eventAiReviewView) eventAiReviewView.classList.add('d-none');
            if (eventAiSetupAlert) {
                eventAiSetupAlert.classList.add('d-none');
                eventAiSetupAlert.textContent = '';
            }
            if (eventAiReviewAlert) {
                eventAiReviewAlert.classList.add('d-none');
                eventAiReviewAlert.textContent = '';
            }
            if (executeEventAiBtn) executeEventAiBtn.disabled = false;
            if (executeEventAiIcon) executeEventAiIcon.className = 'fas fa-magic me-1';
            if (executeEventAiText) executeEventAiText.textContent = 'Generate';

            // Hide schedule event modal and show AI modal
            var scheduleModalInst = bootstrap.Modal.getInstance(scheduleEventModalEl);
            if (scheduleModalInst) {
                scheduleModalInst.hide();
            }
            if (eventAiModalEl) {
                var eventAiModalInst = bootstrap.Modal.getOrCreateInstance(eventAiModalEl);
                eventAiModalInst.show();
            }
        }

        function returnToScheduleEventModal(focusDesc) {
            if (eventAiModalEl) {
                var eventAiModalInst = bootstrap.Modal.getInstance(eventAiModalEl);
                if (eventAiModalInst) {
                    eventAiModalInst.hide();
                }
            }
            if (scheduleEventModalEl) {
                var scheduleModalInst = bootstrap.Modal.getOrCreateInstance(scheduleEventModalEl);
                scheduleModalInst.show();
                if (focusDesc && eventDescriptionInput) {
                    setTimeout(function () {
                        eventDescriptionInput.focus();
                    }, 350);
                }
            }
        }

        if (eventAiGenerateBtn) {
            eventAiGenerateBtn.addEventListener('click', openEventAiModal);
        }

        if (cancelEventAiBtn) {
            cancelEventAiBtn.addEventListener('click', function () {
                returnToScheduleEventModal(false);
            });
        }
        if (closeEventAiModalBtn) {
            closeEventAiModalBtn.addEventListener('click', function () {
                returnToScheduleEventModal(false);
            });
        }

        function executeEventDescriptionAi(isRegen) {
            var btn = isRegen ? eventAiRegenerateBtn : executeEventAiBtn;
            var icon = isRegen ? eventAiRegenIcon : executeEventAiIcon;
            var textSpan = isRegen ? eventAiRegenText : executeEventAiText;
            var alertBox = isRegen ? eventAiReviewAlert : eventAiSetupAlert;

            if (alertBox) {
                alertBox.classList.add('d-none');
                alertBox.textContent = '';
            }

            if (btn) btn.disabled = true;
            if (icon) icon.className = 'fas fa-spinner fa-spin me-1';
            if (textSpan) textSpan.textContent = 'Generating description...';

            var payload = {
                title: eventTitleInput ? eventTitleInput.value.trim() : '',
                event_type: eventTypeSelect ? eventTypeSelect.value : 'General Assembly',
                event_date: eventDateInput ? eventDateInput.value : '',
                start_time: eventStartTimeInput ? eventStartTimeInput.value : '',
                end_time: eventEndTimeInput ? eventEndTimeInput.value : '',
                venue: eventVenueInput ? eventVenueInput.value.trim() : '',
                current_description: eventDescriptionInput ? eventDescriptionInput.value.trim() : '',
                additional_instructions: eventAiInstructionsInput ? eventAiInstructionsInput.value.trim() : '',
                club_id: <?= (int) $activeClub['id'] ?>,
                csrf_token: '<?= htmlspecialchars(function_exists('csrfToken') ? csrfToken() : '', ENT_QUOTES) ?>'
            };

            fetch('<?= BASE_URL ?>/modules/cocurricular/endpoints/generate-event-description.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': '<?= htmlspecialchars(function_exists('csrfToken') ? csrfToken() : '', ENT_QUOTES) ?>'
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            })
            .then(function (res) {
                return res.text().then(function (text) {
                    var data = null;
                    try { data = text ? JSON.parse(text) : {}; } catch (e) {}
                    return { ok: res.ok, status: res.status, data: data, rawText: text };
                });
            })
            .then(function (resObj) {
                if (resObj.data === null) {
                    throw new Error('Unable to generate a description right now. You can enter the description manually.');
                }
                var data = resObj.data;
                var description = (data.data && data.data.description) || data.description;
                if (!resObj.ok || !data.success || !description) {
                    throw new Error(data.error || 'Unable to generate a description right now. You can enter the description manually.');
                }

                if (eventAiGeneratedText) {
                    eventAiGeneratedText.value = description;
                }

                // Format subtle metadata
                var provider = (data.provider || '').toLowerCase();
                var model = data.model || '';
                var isFallback = !!(data.fallback_used || provider === 'local' || model === 'local-fallback');
                var metaText = 'Gemini · Generated just now';

                if (provider === 'gemini') {
                    metaText = 'Gemini · Generated just now';
                } else if (provider === 'openai') {
                    metaText = 'OpenAI · ' + (model || 'gpt-4.1');
                } else if (isFallback) {
                    metaText = 'Local fallback';
                }

                if (eventAiMetadata) {
                    eventAiMetadata.textContent = metaText;
                }

                // Transform modal to Review State
                if (eventAiModalLabel) eventAiModalLabel.textContent = 'Review Generated Description';
                if (eventAiModalSubtitle) eventAiModalSubtitle.textContent = 'Review and edit the draft before applying it to the event.';

                if (eventAiSetupView) eventAiSetupView.classList.add('d-none');
                if (eventAiReviewView) eventAiReviewView.classList.remove('d-none');
            })
            .catch(function (err) {
                if (alertBox) {
                    alertBox.textContent = err.message || 'Unable to generate a description right now. You can enter the description manually.';
                    alertBox.classList.remove('d-none');
                }
            })
            .finally(function () {
                if (btn) btn.disabled = false;
                if (icon) icon.className = isRegen ? 'fas fa-sync-alt me-1' : 'fas fa-magic me-1';
                if (textSpan) textSpan.textContent = isRegen ? 'Regenerate' : 'Generate';
            });
        }

        if (executeEventAiBtn) {
            executeEventAiBtn.addEventListener('click', function () {
                executeEventDescriptionAi(false);
            });
        }

        if (eventAiRegenerateBtn) {
            eventAiRegenerateBtn.addEventListener('click', function () {
                executeEventDescriptionAi(true);
            });
        }

        if (eventAiUseDescriptionBtn) {
            eventAiUseDescriptionBtn.addEventListener('click', function () {
                var generated = eventAiGeneratedText ? eventAiGeneratedText.value.trim() : '';
                if (eventDescriptionInput && generated) {
                    eventDescriptionInput.value = generated;
                }
                returnToScheduleEventModal(true);
            });
        }

        if (scheduleEventModalEl) {
            scheduleEventModalEl.addEventListener('hidden.bs.modal', function () {
                if (scheduleEventErrorAlert) scheduleEventErrorAlert.classList.add('d-none');
            });
        }

        if (scheduleEventForm) {
            scheduleEventForm.addEventListener('submit', function (e) {
                e.preventDefault();
                if (scheduleEventErrorAlert) {
                    scheduleEventErrorAlert.classList.add('d-none');
                    scheduleEventErrorAlert.textContent = '';
                }

                if (scheduleEventSubmitBtn) scheduleEventSubmitBtn.disabled = true;
                if (scheduleEventSubmitIcon) scheduleEventSubmitIcon.className = 'fas fa-spinner fa-spin me-1';
                if (scheduleEventSubmitText) scheduleEventSubmitText.textContent = 'Saving...';

                var formData = new FormData(scheduleEventForm);
                // Fix: use getAttribute('action') so <input name="action"> does not shadow form.action property
                var formActionUrl = scheduleEventForm.getAttribute('action') || (window.location.pathname + window.location.search);

                fetch(formActionUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                })
                .then(function (res) {
                    return res.text().then(function (text) {
                        var json = null;
                        try { json = JSON.parse(text); } catch (err) {}
                        return { ok: res.ok, status: res.status, data: json, rawText: text };
                    });
                })
                .then(function (resObj) {
                    var data = resObj.data;
                    if (!resObj.ok || !data || !data.success) {
                        var errMessage = (data && data.message) ? data.message : 'Unable to create event. Please verify all required fields.';
                        throw new Error(errMessage);
                    }

                    // Event created successfully! Navigate to events tab
                    window.location.href = '?club_id=<?= (int) $activeClub['id'] ?>&tab=events';
                })
                .catch(function (err) {
                    if (scheduleEventErrorAlert) {
                        scheduleEventErrorAlert.textContent = err.message || 'Failed to save event.';
                        scheduleEventErrorAlert.classList.remove('d-none');
                    }
                })
                .finally(function () {
                    if (scheduleEventSubmitBtn) scheduleEventSubmitBtn.disabled = false;
                    if (scheduleEventSubmitIcon) scheduleEventSubmitIcon.className = 'fas fa-save me-1';
                    if (scheduleEventSubmitText) scheduleEventSubmitText.textContent = 'Save & Publish Event';
                });
            });
        }

        // Action menu trigger for existing events: Generate Announcement with AI
        // Opens the Announcement modal in AI Prompt view prefilled with event information
        document.querySelectorAll('.js-adviser-generate-event-announcement').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var evId = parseInt(btn.getAttribute('data-event-id'), 10);
                var evTitle = btn.getAttribute('data-event-title') || 'Event';
                var evType = btn.getAttribute('data-event-type') || 'Event';
                var evDate = btn.getAttribute('data-event-date') || '—';
                var evTime = btn.getAttribute('data-event-time') || '—';
                var evVenue = btn.getAttribute('data-event-venue') || 'Campus';

                if (postAnnModalEl) {
                    if (aiRelatedEvent && evId) {
                        aiRelatedEvent.value = String(evId);
                    }
                    if (aiTopicInput) {
                        aiTopicInput.value = evTitle;
                    }
                    if (aiDetailsInput) {
                        aiDetailsInput.value = 'Event: ' + evTitle + '\nType: ' + evType + '\nDate: ' + evDate + (evTime ? ' at ' + evTime : '') + (evVenue ? '\nVenue: ' + evVenue : '');
                    }
                    showAnnView('prompt');
                    var modalInst = bootstrap.Modal.getOrCreateInstance(postAnnModalEl);
                    modalInst.show();
                }
            });
        });

        // Direct query param support for testing and deep links
        var eventUrlParams = new URLSearchParams(window.location.search);
        if (eventUrlParams.get('open_modal') === 'scheduleEventModal') {
            if (scheduleEventModalEl) {
                var evModalInst = bootstrap.Modal.getOrCreateInstance(scheduleEventModalEl);
                evModalInst.show();
            }
        }

        function escapeHtml(str) {
            return String(str || '').replace(/[&<>'"]/g, function (c) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'}[c];
            });
        }
        function nl2brHtml(str) {
            return String(str || '').replace(/\n/g, '<br>');
        }
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
