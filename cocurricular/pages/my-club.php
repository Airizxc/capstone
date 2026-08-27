<?php
/**
 * SMS 2 - Student Club Workspace
 * Module: Co-Curricular
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

if (getCurrentUserRoleKey() !== 'student') {
    header('Location: ' . BASE_URL . '/dashboard/index.php');
    exit;
}

$userId = getCurrentUserId();
$clubId = (int) ($_GET['club_id'] ?? 0);
$club = $clubId > 0 ? cocurricularGetClubById($clubId) : null;
$membership = $userId ? cocurricularFetchApprovedMembershipForClub($clubId, $userId) : null;

if (!$userId || !$club || !$membership) {
    http_response_code(403);
    $pageTitle = 'Access Denied';
    $isStudentPortalView = true;
    $activeModule = 'student_portal';
    $activePage = 'student-club-membership';
    $breadcrumbs = [
        ['label' => 'Student Portal', 'url' => BASE_URL . '/modules/student-portal/pages/dashboard.php'],
        ['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/pages/club-directory.php'],
        ['label' => 'My Club Memberships', 'url' => BASE_URL . '/modules/cocurricular/pages/student-club-membership.php'],
        ['label' => 'Access Denied', 'url' => null],
    ];
    require_once __DIR__ . '/../../../includes/breadcrumbs.php';
    require_once __DIR__ . '/../../../includes/layout-start.php';
    ?>
    <link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css" rel="stylesheet">
    <?php renderBreadcrumbs($breadcrumbs); ?>
    <div class="alert alert-danger">
        <h2 class="h5 mb-2">Access denied</h2>
        <p class="mb-2">You are not authorized to view this club’s private member area.</p>
        <p class="mb-0">Only students with an approved membership for this club may enter.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/modules/cocurricular/pages/student-club-membership.php" class="btn btn-primary">Return to My Club Memberships</a>
        <a href="<?= BASE_URL ?>/modules/cocurricular/pages/club-directory.php" class="btn btn-outline-secondary">Browse club directory</a>
    </div>
    <?php require_once __DIR__ . '/../../../includes/layout-end.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_event_interest') {
    header('Content-Type: application/json');
    $eventId = (int) ($_POST['event_id'] ?? 0);
    $registration = cocurricularRegisterEventInterest($eventId, (int) $userId, $clubId);
    $messages = [
        'registered' => 'Your interest has been noted for this session.',
        'already_registered' => 'You have already expressed your interest in this event.',
        'invalid_event' => 'This event is not available for registration.',
        'error' => 'Something went wrong. Please try again.',
    ];
    $status = (string) ($registration['status'] ?? 'error');
    echo json_encode([
        'success' => $status === 'registered',
        'status' => $status,
        'message' => $messages[$status] ?? $messages['error'],
    ], JSON_THROW_ON_ERROR);
    exit;
}

$officers = cocurricularFetchClubOfficers($clubId);
$memberSince = !empty($membership['reviewed_at']) ? date('M j, Y', strtotime($membership['reviewed_at'])) : date('M j, Y', strtotime($membership['submitted_at']));
$latestAnnouncements = cocurricularGetLatestClubAnnouncements($clubId, 3);
$allAnnouncements = cocurricularGetClubAnnouncements($clubId);
$clubEvents = cocurricularFetchPublishedEventsForClub($clubId);
$pinnedClubEvents = cocurricularFetchPinnedEventsForClub($clubId);
$studentProfile = cocurricularStudentProfile();
$attendanceData = cocurricularFetchStudentAttendanceData($clubId, (int) $userId);
$calendarEvents = cocurricularFetchStudentClubCalendarEvents($clubId, (int) $userId);
$pinnedAnnouncements = array_values(array_filter($allAnnouncements, static function (array $announcement): bool {
    return !empty($announcement['is_pinned']);
}));
$heroItems = [];
foreach ($pinnedAnnouncements as $announcement) {
    $heroItems[] = ['type' => 'announcement', 'item' => $announcement];
}
foreach ($pinnedClubEvents as $event) {
    $heroItems[] = ['type' => 'event', 'item' => $event];
}

function cocurricularAnnouncementImageUrl(array $announcement, int $clubId): ?string
{
    $path = trim((string) ($announcement['attachment_path'] ?? ''));
    if ($path === '') {
        return null;
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    if (!str_starts_with(ltrim(str_replace('\\', '/', $path), '/'), 'cocurricular_announcements/')) {
        return null;
    }
    return BASE_URL . '/modules/cocurricular/pages/announcement-image.php?club_id=' . $clubId . '&announcement_id=' . (int) $announcement['id'];
}

if (isset($_GET['announcement_id'])) {
    $announcementId = (int) $_GET['announcement_id'];
    $announcement = $announcementId > 0 ? cocurricularGetAnnouncementById($announcementId) : null;
    if ($announcement && (int) $announcement['club_id'] === $clubId && (!empty($_GET['format']) && $_GET['format'] === 'json')) {
        header('Content-Type: application/json');
        echo json_encode([
            'id' => (int) $announcement['id'],
            'title' => (string) $announcement['title'],
            'content' => (string) $announcement['content'],
            'posted_at' => date('M j, Y', strtotime((string) $announcement['posted_at'])),
            'is_pinned' => (bool) $announcement['is_pinned'],
            'attachment_path' => cocurricularAnnouncementImageUrl($announcement, $clubId) ?? '',
        ], JSON_THROW_ON_ERROR);
        exit;
    }
}

if (isset($_GET['event_id']) && ($_GET['format'] ?? '') === 'json') {
    $eventId = (int) $_GET['event_id'];
    $event = $eventId > 0 ? cocurricularGetEventById($eventId) : null;
    if ($event && (int) $event['club_id'] === $clubId && (string) $event['status'] === 'Published') {
        $participation = cocurricularGetEventParticipationStatus((int) $event['id'], (int) $userId);
        header('Content-Type: application/json');
        echo json_encode([
            'id' => (int) $event['id'],
            'title' => (string) $event['title'],
            'description' => (string) $event['description'],
            'event_type' => (string) $event['event_type'],
            'event_date' => date('M j, Y', strtotime((string) $event['event_date'])),
            'start_time' => date('h:i A', strtotime((string) $event['start_time'])),
            'end_time' => date('h:i A', strtotime((string) $event['end_time'])),
            'venue' => (string) ($event['venue'] ?? ''),
            'status' => (string) $event['status'],
            'participation_status' => $participation['status'] ?? null,
            'rejection_note' => $participation['rejection_note'] ?? '',
            'image_path' => !empty($event['image_path']) ? BASE_URL . '/modules/cocurricular/pages/event-image.php?club_id=' . $clubId . '&event_id=' . (int) $event['id'] : '',
        ], JSON_THROW_ON_ERROR);
        exit;
    }
}

$pageTitle = 'My Club';
$isStudentPortalView = true;
$activeModule = 'student_portal';
$activePage = 'student-club-membership';
$breadcrumbs = [
    ['label' => 'Student Portal', 'url' => BASE_URL . '/modules/student-portal/pages/dashboard.php'],
    ['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/pages/club-directory.php'],
    ['label' => 'My Club', 'url' => null],
    ['label' => $club['club_name'], 'url' => null],
];
$pageBannerDescription = 'Private member workspace for your approved club membership.';
$pageBannerIcon = 'fa-user-check';

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
?>
<link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css?v=18" rel="stylesheet">

<?php renderBreadcrumbs($breadcrumbs); ?>

<main class="club-workspace">
    <header class="club-workspace-header">
        <div>
            <div class="club-eyebrow"><i class="fas fa-layer-group"></i> Private club workspace</div>
            <h1><?= htmlspecialchars($club['club_name']) ?></h1>
            <p><?= htmlspecialchars($club['category']) ?> <span aria-hidden="true">•</span> Adviser: <?= htmlspecialchars($club['adviser']) ?></p>
        </div>
        <div class="club-member-chip"><i class="fas fa-check-circle"></i> Active Member</div>
    </header>

    <section class="club-announcement-hero" id="overview" aria-labelledby="hero-title" data-hero-count="<?= count($heroItems) ?>">
        <div class="club-hero-copy">
            <span class="club-hero-kicker"><i class="fas fa-bullhorn"></i> Featured club content<?php if (count($heroItems) > 1): ?> <span class="club-hero-count"><?= count($heroItems) ?> pinned</span><?php endif; ?></span>
            <?php if (!empty($heroItems)): ?>
                <div class="club-hero-slides">
                    <?php foreach ($heroItems as $index => $heroItem): ?>
                        <?php $heroContent = $heroItem['item']; $isHeroEvent = $heroItem['type'] === 'event'; ?>
                        <article class="club-hero-slide<?= $index === 0 ? ' is-active' : '' ?>" data-hero-slide="<?= $index ?>" data-content-type="<?= $heroItem['type'] ?>" data-content-id="<?= (int) $heroContent['id'] ?>" aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>">
                            <h2 id="hero-title<?= $index > 0 ? '-' . $index : '' ?>"><?= htmlspecialchars($heroContent['title']) ?></h2>
                            <p><?= htmlspecialchars(mb_substr((string) ($isHeroEvent ? $heroContent['description'] : $heroContent['content']), 0, 170)) ?><?= mb_strlen((string) ($isHeroEvent ? $heroContent['description'] : $heroContent['content'])) > 170 ? '...' : '' ?></p>
                            <div class="club-hero-meta"><?= $isHeroEvent ? 'Event ' . htmlspecialchars(date('M j, Y', strtotime($heroContent['event_date']))) : 'Posted ' . htmlspecialchars(date('M j, Y', strtotime($heroContent['posted_at']))) ?> <span class="club-pin-label">Pinned</span></div>
                            <button type="button" class="btn club-hero-button" data-hero-cta>View <?= $isHeroEvent ? 'Event' : 'Announcement' ?> <i class="fas fa-arrow-right"></i></button>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if (count($pinnedAnnouncements) > 1): ?>
                    <div class="club-hero-controls" aria-label="Announcement slider indicators">
                        <div class="club-hero-indicators" role="tablist" aria-label="Pinned club content">
                            <?php foreach ($heroItems as $index => $heroItem): ?>
                                <button type="button" class="club-hero-dot<?= $index === 0 ? ' is-active' : '' ?>" data-hero-index="<?= $index ?>" role="tab" aria-label="Show featured item <?= $index + 1 ?>" aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="club-hero-slide is-active">
                        <h2 id="hero-title">No pinned club content yet.</h2>
                    <p>Your club's pinned announcements and upcoming events will appear here.</p>
                    <div class="club-hero-meta">Stay tuned for club news</div>
                </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($heroItems)): ?>
            <div class="club-hero-visuals" aria-label="Featured club content images">
                <?php foreach ($heroItems as $index => $heroItem): ?>
                    <?php $heroContent = $heroItem['item']; $imageUrl = $heroItem['type'] === 'event' ? (!empty($heroContent['image_path']) ? BASE_URL . '/modules/cocurricular/pages/event-image.php?club_id=' . $clubId . '&event_id=' . (int) $heroContent['id'] : null) : cocurricularAnnouncementImageUrl($heroContent, $clubId); ?>
                    <div class="club-hero-visual<?= $index === 0 ? ' is-active' : '' ?>" data-hero-visual="<?= $index ?>" data-hero-image-url="<?= htmlspecialchars($imageUrl ?? '', ENT_QUOTES) ?>" aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>"<?php if ($imageUrl): ?> style="--club-hero-image: url('<?= htmlspecialchars($imageUrl, ENT_QUOTES) ?>')"<?php endif; ?>>
                        <?php if ($imageUrl): ?>
                            <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($heroContent['title']) ?>">
                        <?php else: ?>
                            <div class="club-hero-fallback"><i class="fas fa-<?= $heroItem['type'] === 'event' ? 'calendar-alt' : 'bullhorn' ?>"></i><span>Club<br><?= $heroItem['type'] === 'event' ? 'event' : 'announcement' ?></span></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="club-hero-visuals" aria-hidden="true"><div class="club-hero-fallback"><i class="fas fa-bullhorn"></i><span>Club<br>announcement</span></div></div>
        <?php endif; ?>
    </section>

    <section class="club-section-block" aria-labelledby="featured-title">
        <div class="club-section-heading"><div><span class="club-section-kicker">Quick access</span><h2 id="featured-title">Featured club sections</h2></div><a href="#club-posts" class="club-text-link">Explore workspace <i class="fas fa-arrow-right"></i></a></div>
        <div class="club-feature-grid">
            <?php
            $features = [
                ['announcements', 'fa-bullhorn', 'Announcements', 'Club updates and notices'],
                ['events', 'fa-calendar-alt', 'Events & Activities', 'Upcoming club activities'],
                ['attendance', 'fa-clipboard-check', 'Attendance', 'Your activity record'],
                ['documents', 'fa-folder-open', 'Documents', 'Shared club files'],
                ['achievements', 'fa-award', 'Achievements', 'Club milestones'],
                ['elections', 'fa-vote-yea', 'Election Information', 'Leadership updates'],
                ['volunteer-hours', 'fa-hands-helping', 'Volunteer Hours', 'Your service record'],
                ['club-posts', 'fa-comments', 'Club Posts', 'Community updates'],
            ];
            foreach ($features as [$anchor, $icon, $title, $description]): ?>
                <a class="club-feature-card" href="#<?= $anchor ?>"><span class="club-feature-icon"><i class="fas <?= $icon ?>"></i></span><strong><?= $title ?></strong><small><?= $description ?></small><span class="club-feature-view">View <i class="fas fa-arrow-right"></i></span></a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="club-attendance-panel" id="attendance" aria-labelledby="attendance-title">
        <div class="club-attendance-heading">
            <div class="club-attendance-icon"><i class="fas fa-clipboard-check"></i></div>
            <div><span class="club-section-kicker">Member activity</span><h2 id="attendance-title">Attendance <?php if (!empty($attendanceData['available_sessions'])): ?><span class="club-attendance-availability"><i class="fas fa-bolt"></i> Attendance Available</span><?php endif; ?></h2><p class="mb-0">Your club participation at a glance.</p></div>
        </div>
        <div class="club-attendance-dashboard">
            <div class="club-attendance-summary">
                <div class="club-attendance-stats" aria-label="Attendance summary">
                    <div class="club-attendance-stat"><span>Attendance rate</span><strong data-attendance-stat="rate"><?= (int) $attendanceData['summary']['rate'] ?>%</strong></div>
                    <div class="club-attendance-stat"><span>Present</span><strong data-attendance-stat="present"><?= (int) $attendanceData['summary']['present'] ?></strong></div>
                    <div class="club-attendance-stat"><span>Absent</span><strong data-attendance-stat="absent"><?= (int) $attendanceData['summary']['absent'] ?></strong></div>
                    <div class="club-attendance-stat"><span>Total club activities</span><strong data-attendance-stat="total"><?= (int) $attendanceData['summary']['total'] ?></strong></div>
                </div>
                <div class="club-attendance-records">
                    <div class="club-attendance-subheading"><h3>Recent attendance</h3><span data-attendance-record-count><?= count($attendanceData['sessions']) ?> <?= count($attendanceData['sessions']) === 1 ? 'session' : 'sessions' ?></span></div>
                    <div class="club-attendance-record-list" data-attendance-records></div>
                </div>
            </div>
            <div class="club-attendance-calendar-card">
                <div class="club-calendar-header">
                    <button type="button" class="club-calendar-nav" data-calendar-prev aria-label="Previous month"><i class="fas fa-chevron-left"></i></button>
                    <h3 data-calendar-title>Loading calendar</h3>
                    <button type="button" class="club-calendar-nav" data-calendar-next aria-label="Next month"><i class="fas fa-chevron-right"></i></button>
                </div>
                <div class="club-calendar-weekdays" aria-hidden="true"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
                <div class="club-calendar-grid" data-calendar-grid role="grid" aria-label="Attendance calendar"></div>
                <div class="club-calendar-details" data-calendar-details aria-live="polite">Select a date to view scheduled club activities.</div>
            </div>
        </div>
    </section>

    <div class="club-content-grid">
        <section class="club-content-section" id="announcements" aria-labelledby="announcements-title">
            <div class="club-section-heading"><div><span class="club-section-kicker">Stay informed</span><h2 id="announcements-title">Announcements</h2></div></div>
            <?php if (empty($allAnnouncements)): ?><div class="club-empty-state"><i class="fas fa-bullhorn"></i><p>No announcements yet.</p></div>
            <?php else: ?><div class="club-announcement-list"><?php foreach ($allAnnouncements as $announcement): ?><article class="club-announcement-row" data-content-type="announcement" data-content-id="<?= (int) $announcement['id'] ?>"><div><div class="club-row-meta"><?= htmlspecialchars(date('M j, Y', strtotime($announcement['posted_at']))) ?><?php if (!empty($announcement['is_pinned'])): ?> <span class="club-pin-label">Pinned</span><?php endif; ?></div><h3><?= htmlspecialchars($announcement['title']) ?></h3><p><?= htmlspecialchars(mb_substr((string) $announcement['content'], 0, 130)) ?><?= mb_strlen((string) $announcement['content']) > 130 ? '...' : '' ?></p></div><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#announcementDetailModal" data-announcement-id="<?= (int) $announcement['id'] ?>">Read</button></article><?php endforeach; ?></div><?php endif; ?>
        </section>

        <aside class="club-side-section" aria-labelledby="club-details-title">
            <div class="club-section-heading"><div><span class="club-section-kicker">Your membership</span><h2 id="club-details-title">Club details</h2></div></div>
            <p class="club-description"><?= nl2br(htmlspecialchars($club['description'])) ?></p>
            <dl class="club-details-list"><div><dt>Adviser</dt><dd><?= htmlspecialchars($club['adviser']) ?></dd></div><div><dt>Date joined</dt><dd><?= htmlspecialchars($memberSince) ?></dd></div><div><dt>Contact</dt><dd><?= htmlspecialchars($club['adviser_email'] ?: 'No email provided') ?></dd></div></dl>
            <h3 class="club-subheading">Club officers</h3>
            <?php if (empty($officers)): ?><p class="text-muted small mb-0">No officers are listed yet.</p><?php else: ?><div class="club-officer-list"><?php foreach ($officers as $officer): ?><div><span><?= htmlspecialchars($officer['officer_name']) ?></span><small><?= htmlspecialchars($officer['position']) ?></small></div><?php endforeach; ?></div><?php endif; ?>
        </aside>
    </div>

    <?php
    $clubEvents = cocurricularFetchPublishedEventsForClub($clubId);
    $upcomingClubEvents = array_values(array_filter($clubEvents, static fn (array $event): bool => !empty($event['event_date']) && $event['event_date'] >= date('Y-m-d')));
    $pastClubEvents = array_values(array_filter($clubEvents, static fn (array $event): bool => !empty($event['event_date']) && $event['event_date'] < date('Y-m-d')));
    ?>
    <section class="club-content-section" id="events" aria-labelledby="events-title">
        <div class="club-section-heading">
            <div><span class="club-section-kicker">What's next</span><h2 id="events-title">Events &amp; Activities</h2></div>
        </div>
        <?php if (empty($clubEvents)): ?>
            <div class="club-empty-state"><i class="fas fa-calendar-alt"></i><p>No upcoming events.</p></div>
        <?php else: ?>
            <div class="club-event-sections">
                <?php if (!empty($upcomingClubEvents)): ?>
                    <div class="club-event-group">
                        <h3>Upcoming Events</h3>
                        <div class="club-event-list">
                            <?php foreach ($upcomingClubEvents as $event): ?>
                                <?php $eventImage = !empty($event['image_path']) ? BASE_URL . '/modules/cocurricular/pages/event-image.php?club_id=' . $clubId . '&event_id=' . (int) $event['id'] : ''; ?>
                                <article class="club-event-card" data-content-type="event" data-content-id="<?= (int) $event['id'] ?>">
                                    <?php if ($eventImage): ?>
                                        <div class="club-event-image-wrap"><img src="<?= htmlspecialchars($eventImage, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($event['title']) ?>"></div>
                                    <?php endif; ?>
                                    <div class="club-event-content">
                                        <div class="club-event-head">
                                            <span class="club-event-type"><?= htmlspecialchars($event['event_type']) ?></span>
                                            <span class="club-event-status status-<?= strtolower(str_replace(' ', '-', $event['status'])) ?>"><?= htmlspecialchars($event['status']) ?></span>
                                        </div>
                                        <h4><?= htmlspecialchars($event['title']) ?></h4>
                                        <ul class="club-event-meta">
                                            <li><i class="fas fa-calendar-day"></i> <?= htmlspecialchars(date('M j, Y', strtotime((string) $event['event_date']))) ?></li>
                                            <li><i class="fas fa-clock"></i> <?= htmlspecialchars(date('h:i A', strtotime((string) $event['start_time']))) ?> - <?= htmlspecialchars(date('h:i A', strtotime((string) $event['end_time']))) ?></li>
                                            <?php if (!empty($event['venue'])): ?><li><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($event['venue']) ?></li><?php endif; ?>
                                        </ul>
                                        <?php if (!empty($event['description'])): ?><p><?= nl2br(htmlspecialchars($event['description'])) ?></p><?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary mt-3" data-bs-toggle="modal" data-bs-target="#eventDetailModal" data-event-id="<?= (int) $event['id'] ?>">View Event</button>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($pastClubEvents)): ?>
                    <div class="club-event-group mt-4">
                        <h3>Past Events</h3>
                        <div class="club-event-list">
                            <?php foreach ($pastClubEvents as $event): ?>
                                <?php $eventImage = !empty($event['image_path']) ? BASE_URL . '/modules/cocurricular/pages/event-image.php?club_id=' . $clubId . '&event_id=' . (int) $event['id'] : ''; ?>
                                <article class="club-event-card club-event-card-past" data-content-type="event" data-content-id="<?= (int) $event['id'] ?>">
                                    <?php if ($eventImage): ?>
                                        <div class="club-event-image-wrap"><img src="<?= htmlspecialchars($eventImage, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($event['title']) ?>"></div>
                                    <?php endif; ?>
                                    <div class="club-event-content">
                                        <div class="club-event-head">
                                            <span class="club-event-type"><?= htmlspecialchars($event['event_type']) ?></span>
                                            <span class="club-event-status status-<?= strtolower(str_replace(' ', '-', $event['status'])) ?>"><?= htmlspecialchars($event['status']) ?></span>
                                        </div>
                                        <h4><?= htmlspecialchars($event['title']) ?></h4>
                                        <ul class="club-event-meta">
                                            <li><i class="fas fa-calendar-day"></i> <?= htmlspecialchars(date('M j, Y', strtotime((string) $event['event_date']))) ?></li>
                                            <li><i class="fas fa-clock"></i> <?= htmlspecialchars(date('h:i A', strtotime((string) $event['start_time']))) ?> - <?= htmlspecialchars(date('h:i A', strtotime((string) $event['end_time']))) ?></li>
                                            <?php if (!empty($event['venue'])): ?><li><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($event['venue']) ?></li><?php endif; ?>
                                        </ul>
                                        <?php if (!empty($event['description'])): ?><p><?= nl2br(htmlspecialchars($event['description'])) ?></p><?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary mt-3" data-bs-toggle="modal" data-bs-target="#eventDetailModal" data-event-id="<?= (int) $event['id'] ?>">View Event</button>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
    <div class="club-empty-grid">
        <section class="club-content-section" id="documents"><h2>Documents</h2><div class="club-empty-state compact"><i class="fas fa-folder-open"></i><p>No documents available.</p></div></section>
        <section class="club-content-section" id="achievements"><h2>Achievements</h2><div class="club-empty-state compact"><i class="fas fa-award"></i><p>No achievements recorded yet.</p></div></section>
        <section class="club-content-section" id="elections"><h2>Election Information</h2><div class="club-empty-state compact"><i class="fas fa-vote-yea"></i><p>No election information available.</p></div></section>
        <section class="club-content-section" id="volunteer-hours"><h2>Volunteer Hours</h2><div class="club-empty-state compact"><i class="fas fa-hands-helping"></i><p>No volunteer records yet.</p></div></section>
    </div>
    <section class="club-content-section" id="club-posts"><div class="club-section-heading"><div><span class="club-section-kicker">Community</span><h2>Club Posts</h2></div></div><div class="club-empty-state"><i class="fas fa-comments"></i><p>No club posts yet.</p></div></section>
</main>

<div class="modal fade cocurricular-runtime-modal" id="announcementDetailModal" tabindex="-1" aria-labelledby="announcementDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="announcementDetailModalLabel">Announcement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="announcementDetailModalBody">
                <div class="text-center py-3 text-muted">Loading announcement…</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade cocurricular-runtime-modal" id="eventDetailModal" tabindex="-1" aria-labelledby="eventDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="eventDetailModalLabel">Event Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="eventDetailModalBody"><div class="text-center py-3 text-muted">Loading event...</div></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-primary" id="eventInterestButton">I'm Interested</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade cocurricular-runtime-modal" id="eventInterestModal" tabindex="-1" aria-labelledby="eventInterestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="eventInterestForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="eventInterestModalLabel">Participation Form</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted" id="eventInterestName">Let the club know you plan to participate.</p>
                    <div class="row g-3">
                        <div class="col-sm-6"><label class="form-label" for="interestStudentName">Student name</label><input id="interestStudentName" class="form-control" value="<?= htmlspecialchars($studentProfile['full_name'] ?? '', ENT_QUOTES) ?>" readonly></div>
                        <div class="col-sm-6"><label class="form-label" for="interestStudentId">Student ID</label><input id="interestStudentId" class="form-control" value="<?= htmlspecialchars($studentProfile['student_id'] ?? '', ENT_QUOTES) ?>" readonly></div>
                    </div>
                    <div class="mt-3"><label class="form-label" for="interestClub">Club</label><input id="interestClub" class="form-control" value="<?= htmlspecialchars($club['club_name']) ?>" readonly></div>
                    <div class="form-check mt-3"><input id="interestConfirm" class="form-check-input" type="checkbox" required><label class="form-check-label" for="interestConfirm">I confirm that I am interested in participating.</label></div>
                    <div class="mt-3"><label class="form-label" for="interestNote">Optional note</label><textarea id="interestNote" class="form-control" rows="3" maxlength="500" placeholder="Add a note for the club"></textarea></div>
                    <div class="alert alert-info small mt-3 mb-0">Your interest will be recorded for this event.</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Submit</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade cocurricular-interest-success-modal" id="eventInterestSuccessModal" tabindex="-1" aria-labelledby="eventInterestSuccessModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center">
                <div class="cocurricular-interest-success-icon" aria-hidden="true"><i class="fas fa-check"></i></div>
                <h5 class="modal-title" id="eventInterestSuccessModalLabel">Request Submitted</h5>
                <p class="mb-0">Your participation request has been submitted and is awaiting Student Affairs approval.</p>
            </div>
        </div>
    </div>
</div>

<div class="modal fade cocurricular-interest-feedback-modal" id="eventInterestFeedbackModal" tabindex="-1" aria-labelledby="eventInterestFeedbackModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center">
                <div class="cocurricular-interest-feedback-icon" id="eventInterestFeedbackIcon" aria-hidden="true"><i class="fas fa-info"></i></div>
                <h5 class="modal-title" id="eventInterestFeedbackModalLabel">Already Registered</h5>
                <p class="mb-0" id="eventInterestFeedbackMessage">You have already expressed your interest in this event.</p>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const attendanceRecords = <?= json_encode($attendanceData['sessions'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const availableAttendanceSessions = <?= json_encode($attendanceData['available_sessions'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const calendarEvents = <?= json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const attendancePanel = document.getElementById('attendance');
        const calendarGrid = attendancePanel?.querySelector('[data-calendar-grid]');
        const calendarTitle = attendancePanel?.querySelector('[data-calendar-title]');
        const calendarDetails = attendancePanel?.querySelector('[data-calendar-details]');
        const attendanceList = attendancePanel?.querySelector('[data-attendance-records]');
        let calendarDate = new Date();
        calendarDate.setDate(1);
        let selectedDateKey = null;

        function formatAttendanceTime(value) {
            if (!value) return '';
            const parts = String(value).split(':');
            const date = new Date(2000, 0, 1, Number(parts[0]), Number(parts[1] || 0));
            return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
        }

        function renderAttendanceRecords() {
            if (!attendanceList) return;
            if (!attendanceRecords.length) {
                attendanceList.innerHTML = '<p class="club-attendance-empty">No attendance records yet.</p>';
                return;
            }
            attendanceList.innerHTML = attendanceRecords.map(function (record) {
                const action = record.available && record.access_token
                    ? '<a class="btn btn-sm btn-outline-primary club-attendance-action" href="<?= BASE_URL ?>/modules/cocurricular/pages/student-attendance.php?session=' + encodeURIComponent(record.access_token) + '"><i class="fas fa-check-to-slot"></i> Submit Attendance</a>'
                    : '';
                const checkIn = record.check_in_time ? '<small>Checked in at ' + escapeHtml(new Date(record.check_in_time.replace(' ', 'T')).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })) + '</small>' : '';
                return '<article class="club-attendance-record"><div class="club-attendance-record-main"><span class="club-attendance-record-club">' + escapeHtml(record.club_name) + '</span><strong>' + escapeHtml(record.title) + '</strong><span>' + escapeHtml(formatCalendarDate(new Date(record.date + 'T00:00:00'))) + ' · ' + escapeHtml(formatAttendanceTime(record.start_time)) + ' - ' + escapeHtml(formatAttendanceTime(record.end_time)) + '</span><span><i class="fas fa-location-dot"></i> ' + escapeHtml(record.venue || 'Location unavailable') + '</span></div><div class="club-attendance-record-status"><span class="club-attendance-badge status-' + record.status.toLowerCase().replaceAll(' ', '-') + '">' + escapeHtml(record.status) + '</span>' + checkIn + action + '</div></article>';
            }).join('');
        }

        function renderCalendarDetails(dateKey) {
            if (!calendarDetails) return;
            const events = calendarEvents.filter(function (item) { return item.event_date === dateKey; });
            const date = new Date(dateKey + 'T00:00:00');
            if (!events.length) {
                calendarDetails.innerHTML = '<strong>' + formatCalendarDate(date) + '</strong><span class="club-calendar-empty"><i class="fas fa-calendar-day"></i> No club activities scheduled for this date.</span>';
                return;
            }
            calendarDetails.innerHTML = '<strong>' + formatCalendarDate(date) + '</strong><span class="club-calendar-count">' + events.length + ' ' + (events.length === 1 ? 'activity' : 'activities') + ' scheduled</span>' + events.map(function (event) {
                const availableSession = availableAttendanceSessions.find(function (session) { return Number(session.event_id) === Number(event.id); });
                const recordedSession = attendanceRecords.find(function (record) { return Number(record.event_id) === Number(event.id); });
                const eventState = availableSession ? 'ongoing' : event.attendance_state;
                const isRecorded = !availableSession && eventState === 'ongoing' && recordedSession;
                const attendanceLabel = eventState === 'upcoming'
                    ? 'Upcoming'
                    : isRecorded
                        ? 'Attendance Recorded'
                    : eventState === 'ongoing'
                        ? 'Attendance Available'
                        : 'Past';
                const stateClass = isRecorded ? 'recorded' : eventState === 'upcoming' ? 'upcoming' : eventState === 'ongoing' ? 'available' : 'past';
                const action = availableSession && eventState === 'ongoing'
                    ? '<a class="btn btn-sm btn-outline-primary club-calendar-attendance-action" href="<?= BASE_URL ?>/modules/cocurricular/pages/student-attendance.php?session=' + encodeURIComponent(availableSession.access_token) + '"><i class="fas fa-check-to-slot"></i> Mark Attendance</a>'
                    : '';
                return '<article class="club-calendar-event state-' + stateClass + '"><span><strong>' + escapeHtml(event.club_name) + '</strong><span class="club-calendar-event-title">' + escapeHtml(event.title) + '</span><span class="club-calendar-event-date">' + escapeHtml(formatCalendarDate(date)) + '</span><span>' + escapeHtml(formatAttendanceTime(event.start_time)) + ' - ' + escapeHtml(formatAttendanceTime(event.end_time)) + '</span><span><i class="fas fa-location-dot"></i> ' + escapeHtml(event.venue || 'Location unavailable') + '</span><span class="club-calendar-attendance">' + escapeHtml(attendanceLabel) + '</span>' + action + '</span></article>';
            }).join('');
        }

        renderAttendanceRecords();

        function formatCalendarDate(date) {
            return date.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' });
        }

        function renderAttendanceCalendar() {
            if (!calendarGrid || !calendarTitle) return;
            const year = calendarDate.getFullYear();
            const month = calendarDate.getMonth();
            const today = new Date();
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            calendarTitle.textContent = calendarDate.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
            calendarGrid.innerHTML = '';

            for (let index = 0; index < firstDay; index += 1) {
                const spacer = document.createElement('span');
                spacer.className = 'club-calendar-day is-empty';
                spacer.setAttribute('aria-hidden', 'true');
                calendarGrid.appendChild(spacer);
            }

            for (let day = 1; day <= daysInMonth; day += 1) {
                const dateKey = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
                const events = calendarEvents.filter(function (item) { return item.event_date === dateKey; });
                const dateButton = document.createElement('button');
                dateButton.type = 'button';
                dateButton.className = 'club-calendar-day' + (today.getFullYear() === year && today.getMonth() === month && today.getDate() === day ? ' is-today' : '') + (dateKey === selectedDateKey ? ' is-selected' : '') + (events.length ? ' has-record' : '') + (new Date(year, month, day) < new Date(today.getFullYear(), today.getMonth(), today.getDate()) ? ' is-past' : '');
                dateButton.textContent = String(day);
                dateButton.setAttribute('role', 'gridcell');
                dateButton.setAttribute('aria-label', formatCalendarDate(new Date(year, month, day)) + (events.length ? ', ' + events.length + ' club activit' + (events.length === 1 ? 'y' : 'ies') : ''));
                dateButton.addEventListener('click', function () {
                    selectedDateKey = dateKey;
                    renderAttendanceCalendar();
                    renderCalendarDetails(dateKey);
                });
                calendarGrid.appendChild(dateButton);
            }
        }

        function shiftCalendarMonth(offset) {
            calendarDate.setMonth(calendarDate.getMonth() + offset);
            selectedDateKey = calendarDate.getFullYear() + '-' + String(calendarDate.getMonth() + 1).padStart(2, '0') + '-01';
            renderAttendanceCalendar();
            renderCalendarDetails(selectedDateKey);
        }

        attendancePanel?.querySelector('[data-calendar-prev]')?.addEventListener('click', function () { shiftCalendarMonth(-1); });
        attendancePanel?.querySelector('[data-calendar-next]')?.addEventListener('click', function () { shiftCalendarMonth(1); });
        renderAttendanceCalendar();

        const modal = document.getElementById('announcementDetailModal');
        const modalBody = document.getElementById('announcementDetailModalBody');
        const announcementButtons = document.querySelectorAll('[data-announcement-id]');

        announcementButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const announcementId = button.getAttribute('data-announcement-id');
                modalBody.innerHTML = '<div class="text-center py-3 text-muted">Loading announcement…</div>';

                fetch('<?= BASE_URL ?>/modules/cocurricular/pages/my-club.php?club_id=<?= (int) $clubId ?>&announcement_id=' + encodeURIComponent(announcementId) + '&format=json', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Unable to load announcement.');
                    }
                    return response.json();
                })
                .then(function (data) {
                    const pinnedBadge = data.is_pinned ? '<span class="badge rounded-pill bg-warning text-dark">Pinned</span>' : '';
                    const attachment = data.attachment_path ? '<img class="img-fluid rounded mb-3" src="' + escapeHtml(data.attachment_path) + '" alt="' + escapeHtml(data.title) + '"><div class="small mb-3"><a href="' + escapeHtml(data.attachment_path) + '" target="_blank" rel="noopener noreferrer">Open image</a></div>' : '';
                    modalBody.innerHTML = attachment + '<div class="mb-3"><div class="d-flex align-items-center gap-2 flex-wrap">' + pinnedBadge + '</div></div><h6 class="fw-bold mb-2">' + escapeHtml(data.title) + '</h6><div class="small text-muted mb-3">Posted: ' + escapeHtml(data.posted_at) + '</div><div class="announcement-content">' + escapeHtml(data.content).replace(/\n/g, '<br>') + '</div>';
                })
                .catch(function () {
                    modalBody.innerHTML = '<div class="alert alert-danger mb-0">Announcement details are unavailable.</div>';
                });
            });
        });

        let selectedEvent = null;
        const eventModal = document.getElementById('eventDetailModal');
        const eventModalBody = document.getElementById('eventDetailModalBody');
        const eventInterestButton = document.getElementById('eventInterestButton');
        const eventInterestModal = document.getElementById('eventInterestModal');
        const eventInterestForm = document.getElementById('eventInterestForm');
        const eventInterestName = document.getElementById('eventInterestName');
        const eventInterestSubmitButton = eventInterestForm.querySelector('button[type="submit"]');
        const eventInterestSuccessModal = document.getElementById('eventInterestSuccessModal');
        const eventInterestFeedbackModal = document.getElementById('eventInterestFeedbackModal');
        const eventInterestFeedbackTitle = document.getElementById('eventInterestFeedbackModalLabel');
        const eventInterestFeedbackMessage = document.getElementById('eventInterestFeedbackMessage');
        const eventInterestFeedbackIcon = document.getElementById('eventInterestFeedbackIcon');

        function resetInterestSubmitButton() {
            eventInterestSubmitButton.disabled = false;
            eventInterestSubmitButton.textContent = 'Submit';
        }

        function showInterestFeedback(title, message, isError) {
            eventInterestFeedbackTitle.textContent = title;
            eventInterestFeedbackMessage.textContent = message;
            eventInterestFeedbackIcon.classList.toggle('is-error', isError);
            eventInterestFeedbackIcon.innerHTML = '<i class="fas fa-' + (isError ? 'exclamation' : 'info') + '"></i>';
            bootstrap.Modal.getOrCreateInstance(eventInterestFeedbackModal).show();
            window.setTimeout(function () {
                bootstrap.Modal.getOrCreateInstance(eventInterestFeedbackModal).hide();
            }, 2800);
        }

        document.querySelectorAll('[data-event-id]').forEach(function (button) {
            button.addEventListener('click', function () {
                const eventId = button.getAttribute('data-event-id');
                eventModalBody.innerHTML = '<div class="text-center py-3 text-muted">Loading event...</div>';
                eventInterestButton.classList.add('d-none');
                eventInterestButton.disabled = true;
                fetch('<?= BASE_URL ?>/modules/cocurricular/pages/my-club.php?club_id=<?= (int) $clubId ?>&event_id=' + encodeURIComponent(eventId) + '&format=json', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (response) { if (!response.ok) throw new Error('Unable to load event.'); return response.json(); })
                    .then(function (data) {
                        selectedEvent = data;
                        const venue = data.venue ? '<li><strong>Venue:</strong> ' + escapeHtml(data.venue) + '</li>' : '';
                        eventModalBody.innerHTML = '<span class="club-event-type">' + escapeHtml(data.event_type) + '</span><h6 class="fw-bold mt-3 mb-2">' + escapeHtml(data.title) + '</h6><ul class="club-event-meta"><li><strong>Date:</strong> ' + escapeHtml(data.event_date) + '</li><li><strong>Time:</strong> ' + escapeHtml(data.start_time) + ' - ' + escapeHtml(data.end_time) + '</li>' + venue + '</ul><div class="event-description">' + escapeHtml(data.description).replace(/\n/g, '<br>') + '</div>';
                        const participationStatus = data.participation_status || null;
                        eventInterestButton.classList.remove('d-none');
                        eventInterestButton.disabled = Boolean(participationStatus && participationStatus !== 'Rejected');
                        eventInterestButton.textContent = participationStatus === 'Pending' ? 'Pending Approval' : participationStatus === 'Approved' ? 'Approved' : participationStatus === 'Rejected' ? 'Apply Again' : "I'm Interested";
                        if (participationStatus === 'Rejected' && data.rejection_note) {
                            eventModalBody.innerHTML += '<div class="alert alert-warning small mt-3 mb-0"><strong>Reason:</strong> ' + escapeHtml(data.rejection_note) + '</div>';
                        }
                    })
                    .catch(function () { eventModalBody.innerHTML = '<div class="alert alert-danger mb-0">Event details are unavailable.</div>'; });
            });
        });

        eventInterestButton.addEventListener('click', function () {
            if (!selectedEvent) return;
            eventInterestName.textContent = 'You are responding to: ' + selectedEvent.title;
            bootstrap.Modal.getOrCreateInstance(eventModal).hide();
            bootstrap.Modal.getOrCreateInstance(eventInterestModal).show();
        });
        eventInterestForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (eventInterestSubmitButton.disabled) return;
            eventInterestSubmitButton.disabled = true;
            eventInterestSubmitButton.textContent = 'Submitting...';
            if (!selectedEvent) {
                resetInterestSubmitButton();
                showInterestFeedback('Something went wrong.', 'Please try again.', true);
                return;
            }
            fetch('<?= BASE_URL ?>/modules/cocurricular/pages/my-club.php?club_id=<?= (int) $clubId ?>', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'register_event_interest', event_id: String(selectedEvent.id) })
            })
                .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || 'Unable to register interest.'); return data; }); })
                .then(function (data) {
                    bootstrap.Modal.getOrCreateInstance(eventInterestModal).hide();
                    bootstrap.Modal.getOrCreateInstance(eventModal).hide();
                    if (data.status === 'registered') {
                        bootstrap.Modal.getOrCreateInstance(eventInterestSuccessModal).show();
                        window.setTimeout(function () { bootstrap.Modal.getOrCreateInstance(eventInterestSuccessModal).hide(); }, 2800);
                    } else if (data.status === 'pending') {
                        showInterestFeedback('Request Pending', 'Your application is already pending.', false);
                    } else if (data.status === 'approved') {
                        showInterestFeedback('Already Approved', 'You are already approved for this event.', false);
                    } else if (data.status === 'already_registered') {
                        showInterestFeedback('Already Registered', data.message, false);
                    } else {
                        showInterestFeedback('Something went wrong.', data.message, true);
                    }
                    resetInterestSubmitButton();
                })
                .catch(function (error) {
                    bootstrap.Modal.getOrCreateInstance(eventInterestModal).hide();
                    bootstrap.Modal.getOrCreateInstance(eventModal).hide();
                    resetInterestSubmitButton();
                    showInterestFeedback('Something went wrong.', error.message || 'Please try again.', true);
                });
        });

        const hero = document.querySelector('.club-announcement-hero[data-hero-count]');
        const heroSlides = hero ? Array.from(hero.querySelectorAll('[data-hero-slide]')) : [];
        const heroVisuals = hero ? Array.from(hero.querySelectorAll('[data-hero-visual]')) : [];
        const heroDots = hero ? Array.from(hero.querySelectorAll('[data-hero-index]')) : [];
        let heroIndex = 0;
        let heroTimer = null;
        let paletteRequest = 0;

        function rgbString(color, alpha = 1) {
            return 'rgb(' + color.join(' ') + ' / ' + alpha + ')';
        }

        function deriveHeroPalette(image) {
            return new Promise(function (resolve) {
                if (!image || !image.complete || image.naturalWidth === 0) {
                    resolve(null);
                    return;
                }
                const canvas = document.createElement('canvas');
                canvas.width = 24;
                canvas.height = 24;
                const context = canvas.getContext('2d', { willReadFrequently: true });
                if (!context) {
                    resolve(null);
                    return;
                }
                try {
                    context.drawImage(image, 0, 0, canvas.width, canvas.height);
                    const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
                    const totals = [0, 0, 0];
                    let samples = 0;
                    for (let index = 0; index < pixels.length; index += 4) {
                        if (pixels[index + 3] < 160) {
                            continue;
                        }
                        totals[0] += pixels[index];
                        totals[1] += pixels[index + 1];
                        totals[2] += pixels[index + 2];
                        samples += 1;
                    }
                    if (!samples) {
                        resolve(null);
                        return;
                    }
                    const average = totals.map(function (value) { return Math.round(value / samples); });
                    const shadow = average.map(function (value) { return Math.max(0, Math.round(value * 0.58)); });
                    resolve({ average, shadow });
                } catch (error) {
                    resolve(null);
                }
            });
        }

        async function updateHeroPalette(index) {
            const request = ++paletteRequest;
            const visual = heroVisuals[index];
            const image = visual?.querySelector('img');
            const imageUrl = visual?.getAttribute('data-hero-image-url') || '';
            if (!imageUrl || !image) {
                hero?.style.removeProperty('--club-hero-color-a');
                hero?.style.removeProperty('--club-hero-color-b');
                hero?.style.removeProperty('--club-hero-overlay-soft');
                return;
            }
            if (!image.complete) {
                await new Promise(function (resolve) {
                    image.addEventListener('load', resolve, { once: true });
                    image.addEventListener('error', resolve, { once: true });
                });
            }
            const palette = await deriveHeroPalette(image);
            if (!palette || request !== paletteRequest) {
                return;
            }
            hero.style.setProperty('--club-hero-color-a', rgbString(palette.shadow));
            hero.style.setProperty('--club-hero-color-b', rgbString(palette.average));
            hero.style.setProperty('--club-hero-overlay-soft', rgbString(palette.shadow, 0.3));
        }

        function setHeroBackground(index) {
            if (!hero) {
                return;
            }
            const imageUrl = heroVisuals[index]?.getAttribute('data-hero-image-url') || '';
            hero.style.setProperty('--club-hero-active-image', imageUrl ? 'url("' + imageUrl.replace(/"/g, '%22') + '")' : 'none');
            updateHeroPalette(index);
        }

        function showHeroSlide(nextIndex) {
            if (heroSlides.length < 2) {
                return;
            }
            heroIndex = (nextIndex + heroSlides.length) % heroSlides.length;
            heroSlides.forEach(function (slide, index) {
                const isActive = index === heroIndex;
                slide.classList.toggle('is-active', isActive);
                slide.setAttribute('aria-hidden', isActive ? 'false' : 'true');
            });
            heroVisuals.forEach(function (visual, index) {
                const isActive = index === heroIndex;
                visual.classList.toggle('is-active', isActive);
                visual.setAttribute('aria-hidden', isActive ? 'false' : 'true');
            });
            setHeroBackground(heroIndex);
            heroDots.forEach(function (dot, index) {
                const isActive = index === heroIndex;
                dot.classList.toggle('is-active', isActive);
                dot.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
        }

        function resetHeroTimer() {
            if (heroSlides.length < 2) {
                return;
            }
            window.clearInterval(heroTimer);
            heroTimer = window.setInterval(function () {
                showHeroSlide(heroIndex + 1);
            }, 3000);
        }

        if (heroSlides.length > 1) {
            heroDots.forEach(function (dot) {
                dot.addEventListener('click', function () {
                    showHeroSlide(Number(dot.getAttribute('data-hero-index')));
                    resetHeroTimer();
                });
            });
            hero.addEventListener('mouseenter', function () { window.clearInterval(heroTimer); });
            hero.addEventListener('mouseleave', resetHeroTimer);
            hero.addEventListener('focusin', function () { window.clearInterval(heroTimer); });
            hero.addEventListener('focusout', function (event) {
                if (!hero.contains(event.relatedTarget)) {
                    resetHeroTimer();
                }
            });
            resetHeroTimer();
        } else if (heroVisuals.length === 1) {
            setHeroBackground(0);
        }

        hero?.querySelectorAll('[data-hero-cta]').forEach(function (button) {
            button.addEventListener('click', function () {
                const slide = button.closest('[data-hero-slide]');
                const type = slide?.getAttribute('data-content-type');
                const id = slide?.getAttribute('data-content-id');
                const target = type && id ? Array.from(document.querySelectorAll('[data-content-type="' + type + '"][data-content-id="' + id + '"]')).find(function (element) { return !hero.contains(element); }) : null;
                if (!target) return;
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                target.classList.add('is-hero-target');
                window.setTimeout(function () { target.classList.remove('is-hero-target'); }, 1800);
            });
        });

        function escapeHtml(value) {
            return String(value || '').replace(/[&<>"']/g, function (character) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[character];
            });
        }
    });
</script>

<div class="mt-4 d-flex flex-wrap gap-2">
    <a href="<?= BASE_URL ?>/modules/cocurricular/pages/student-club-membership.php" class="btn btn-outline-secondary">Back to My Club Memberships</a>
    <a href="<?= BASE_URL ?>/modules/cocurricular/pages/club-directory.php" class="btn btn-outline-primary">Browse Clubs</a>
</div>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
