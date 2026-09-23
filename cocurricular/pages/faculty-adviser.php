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
    || smsIsGrantedAdminRole($roleKey);

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
    if (!$isAuthorized && smsIsGrantedAdminRole($roleKey)) {
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

// Handle POST actions for Faculty Adviser (Event creation, Announcement publishing)
if ($hasAssignedClub && $activeClub && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token !== '' && function_exists('verifyCsrfToken') && !verifyCsrfToken($token)) {
        $notice = 'Security token expired. Please try again.';
        $noticeType = 'danger';
    } else {
        $postAction = (string) ($_POST['action'] ?? '');
        $targetClubId = (int) $activeClub['id'];

        if ($postAction === 'create_event') {
            $eventResult = cocurricularCreateAdviserEvent($targetClubId, $currentUserId, $_POST);
            $notice = (string) ($eventResult['message'] ?? 'Event processed.');
            $noticeType = ($eventResult['success'] ?? false) ? 'success' : 'danger';
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
    ? htmlspecialchars((string) $activeClub['club_name']) . ' — Faculty Adviser'
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
    ? 'Official Faculty Adviser Workspace for ' . htmlspecialchars((string) $activeClub['club_name']) . '. Manage club details, student members, activities, and announcements.'
    : 'Co-Curricular Faculty Adviser access and club organizational management.';
$pageBannerIcon = 'fa-users';

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
?>
<link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css" rel="stylesheet">

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

    <!-- Top Club Summary Card -->
    <div class="card cocurricular-card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                        <span class="badge bg-primary text-white"><i class="fas fa-user-tie me-1"></i>Faculty Adviser</span>
                        <span class="badge bg-success-subtle text-success border border-success-subtle"><?= htmlspecialchars((string) $activeClub['category']) ?></span>
                        <span class="badge bg-light text-secondary border"><?= htmlspecialchars((string) $activeClub['status']) ?></span>
                    </div>
                    <h2 class="h3 fw-bold mb-1"><?= htmlspecialchars((string) $activeClub['club_name']) ?></h2>
                    <p class="text-muted small mb-0">
                        <i class="fas fa-phone-alt me-1"></i> Contact: <?= htmlspecialchars((string) ($activeClub['contact_phone'] ?: 'N/A')) ?>
                        <span class="mx-2">·</span>
                        <i class="fas fa-envelope me-1"></i> <?= htmlspecialchars((string) ($activeClub['adviser_email'] ?: 'No email on record')) ?>
                    </p>
                </div>

                <?php if (count($assignedClubs) > 1): ?>
                    <!-- Club Switcher for faculty assigned to more than 1 organization -->
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-exchange-alt me-1"></i> Switch Club (<?= count($assignedClubs) ?>)
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li class="dropdown-header small text-uppercase">Your Assigned Clubs</li>
                            <?php foreach ($assignedClubs as $clubItem): ?>
                                <li>
                                    <a class="dropdown-item <?= (int) $clubItem['id'] === (int) $activeClub['id'] ? 'active' : '' ?>"
                                       href="?club_id=<?= (int) $clubItem['id'] ?>&tab=<?= htmlspecialchars($activeTab) ?>">
                                        <?= htmlspecialchars((string) $clubItem['club_name']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quick Stats Strip -->
            <div class="row g-2 mt-3 pt-3 border-top">
                <div class="col-6 col-md-3">
                    <div class="p-2 border rounded bg-light text-center">
                        <div class="text-muted small">Approved Members</div>
                        <div class="h5 fw-bold mb-0 text-success"><?= count($approvedMembers) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-2 border rounded bg-light text-center">
                        <div class="text-muted small">Pending Applicants</div>
                        <div class="h5 fw-bold mb-0 text-warning"><?= count($pendingApplications) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-2 border rounded bg-light text-center">
                        <div class="text-muted small">Scheduled Events</div>
                        <div class="h5 fw-bold mb-0 text-primary"><?= count($clubEvents) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-2 border rounded bg-light text-center">
                        <div class="text-muted small">Announcements</div>
                        <div class="h5 fw-bold mb-0 text-info"><?= count($clubAnnouncements) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills cocurricular-nav-pills mb-4 gap-2 flex-wrap">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'overview' ? 'active' : '' ?>"
               href="?club_id=<?= (int) $activeClub['id'] ?>&tab=overview">
                <i class="fas fa-id-card me-1"></i> Assigned Club
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'members' ? 'active' : '' ?>"
               href="?club_id=<?= (int) $activeClub['id'] ?>&tab=members">
                <i class="fas fa-users me-1"></i> Members
                <span class="badge bg-light text-dark ms-1"><?= count($approvedMembers) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'events' ? 'active' : '' ?>"
               href="?club_id=<?= (int) $activeClub['id'] ?>&tab=events">
                <i class="fas fa-calendar-alt me-1"></i> Activities / Events
                <span class="badge bg-light text-dark ms-1"><?= count($clubEvents) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'announcements' ? 'active' : '' ?>"
               href="?club_id=<?= (int) $activeClub['id'] ?>&tab=announcements">
                <i class="fas fa-bullhorn me-1"></i> Announcements
                <span class="badge bg-light text-dark ms-1"><?= count($clubAnnouncements) ?></span>
            </a>
        </li>
    </ul>

    <!-- TAB 1: ASSIGNED CLUB OVERVIEW -->
    <?php if ($activeTab === 'overview'): ?>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card cocurricular-card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="card-title mb-0 fw-bold">About the Organization</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-4"><?= nl2br(htmlspecialchars((string) $activeClub['description'])) ?></p>

                        <div class="p-3 bg-light rounded mb-3">
                            <div class="row g-2 small">
                                <div class="col-sm-6">
                                    <span class="text-muted d-block">Category:</span>
                                    <strong><?= htmlspecialchars((string) $activeClub['category']) ?></strong>
                                </div>
                                <div class="col-sm-6">
                                    <span class="text-muted d-block">Operating Status:</span>
                                    <span class="badge bg-<?= $activeClub['status'] === 'Active' ? 'success' : 'warning' ?>">
                                        <?= htmlspecialchars((string) $activeClub['status']) ?>
                                    </span>
                                </div>
                                <div class="col-sm-6 mt-2">
                                    <span class="text-muted d-block">Contact Phone:</span>
                                    <strong><?= htmlspecialchars((string) ($activeClub['contact_phone'] ?: 'None')) ?></strong>
                                </div>
                                <div class="col-sm-6 mt-2">
                                    <span class="text-muted d-block">Official Email:</span>
                                    <strong><?= htmlspecialchars((string) ($activeClub['adviser_email'] ?: 'None')) ?></strong>
                                </div>
                            </div>
                        </div>

                        <div class="small text-muted">
                            <i class="fas fa-info-circle me-1"></i> Organization details and adviser assignments are registered under the Co-Curricular Management Module.
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <!-- Faculty Adviser Profile Card -->
                <div class="card cocurricular-card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="card-title mb-0 fw-bold">Faculty Adviser Profile</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-circle bg-primary-subtle text-primary d-grid place-items-center" style="width: 48px; height: 48px; font-size: 1.25rem;">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold"><?= htmlspecialchars((string) $activeClub['adviser']) ?></h6>
                                <small class="text-muted"><?= htmlspecialchars((string) ($activeClub['adviser_email'] ?: 'No email on record')) ?></small>
                            </div>
                        </div>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                            <i class="fas fa-check-circle me-1"></i>Verified Faculty Account
                        </span>
                    </div>
                </div>

                <!-- Club Officers List -->
                <div class="card cocurricular-card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="card-title mb-0 fw-bold">Student Officers</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($clubOfficers)): ?>
                            <div class="p-3 text-muted small">No student officers listed yet.</div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush small">
                                <?php foreach ($clubOfficers as $officer): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                                        <span class="fw-semibold"><?= htmlspecialchars((string) $officer['officer_name']) ?></span>
                                        <span class="badge bg-secondary-subtle text-secondary border"><?= htmlspecialchars((string) $officer['position']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
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

        <!-- Pending Applications View for Adviser Awareness (Strictly View-Only) -->
        <?php if (!empty($pendingApplications)): ?>
            <div class="card cocurricular-card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h6 class="card-title mb-0 fw-bold text-warning-emphasis">
                            <i class="fas fa-clock me-1 text-warning"></i> Pending Membership Applications <span class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size: 0.7rem;">View-Only</span>
                        </h6>
                        <small class="text-muted">For adviser awareness. Membership approval and rejection are exclusively processed by the Office of Student Affairs (OSA).</small>
                    </div>
                    <span class="badge bg-warning text-dark"><?= count($pendingApplications) ?> Awaiting OSA Review</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Student Name</th>
                                    <th>Student ID</th>
                                    <th>Reason for Joining</th>
                                    <th>Areas of Interest</th>
                                    <th>Submitted Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingApplications as $app): ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars((string) $app['student_name']) ?></td>
                                        <td><code><?= htmlspecialchars((string) $app['student_id']) ?></code></td>
                                        <td><?= htmlspecialchars(mb_strimwidth((string) $app['reason_for_joining'], 0, 50, '...')) ?></td>
                                        <td><?= htmlspecialchars((string) ($app['areas_of_interest'] ?: '—')) ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime((string) $app['submitted_at']))) ?></td>
                                        <td><span class="badge bg-warning text-dark">Pending OSA Review</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

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
                    Attendance verification and tracking sessions are administered centrally by the Office of Student Affairs (OSA).
                </div>

                <?php if (empty($clubEvents)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-calendar-times fs-3 d-block mb-2 text-secondary"></i>
                        No events scheduled for this club yet. Click "Schedule Event" above to post an activity.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Event Title</th>
                                    <th>Type</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Venue</th>
                                    <th>Participants</th>
                                    <th>Status</th>
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
                                        <td>
                                            <span class="badge bg-<?= $event['status'] === 'Published' ? 'success' : ($event['status'] === 'Draft' ? 'secondary' : 'dark') ?>">
                                                <?= htmlspecialchars((string) $event['status']) ?>
                                            </span>
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
                <form method="POST" action="?club_id=<?= (int) $activeClub['id'] ?>&tab=events">
                    <input type="hidden" name="action" value="create_event">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrfToken') ? csrfToken() : '') ?>">

                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="scheduleEventModalLabel">Schedule Club Event</h5>
                            <p class="small text-muted mb-0"><?= htmlspecialchars((string) $activeClub['club_name']) ?></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
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
                                <option value="Community Activity">Community Activity</option>
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
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="eventPinned" name="is_pinned" value="1">
                            <label class="form-check-label small" for="eventPinned">Pin to top of calendar</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Save & Publish Event
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Post Announcement -->
    <div class="modal fade cocurricular-modal" id="postAnnouncementModal" tabindex="-1" aria-labelledby="postAnnouncementModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="?club_id=<?= (int) $activeClub['id'] ?>&tab=announcements">
                    <input type="hidden" name="action" value="create_announcement">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrfToken') ? csrfToken() : '') ?>">

                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="postAnnouncementModalLabel">Post Club Announcement</h5>
                            <p class="small text-muted mb-0"><?= htmlspecialchars((string) $activeClub['club_name']) ?></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="annTitle" class="form-label fw-semibold small">Announcement Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="annTitle" name="title" required placeholder="e.g., Notice of Monthly Meeting">
                        </div>
                        <div class="mb-3">
                            <label for="annContent" class="form-label fw-semibold small">Announcement Message <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="annContent" name="content" rows="4" required placeholder="Write your announcement details here..."></textarea>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="annPinned" name="is_pinned" value="1">
                            <label class="form-check-label small" for="annPinned">Pin announcement to top of feed</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-1"></i> Post Announcement
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
