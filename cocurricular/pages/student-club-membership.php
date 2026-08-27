<?php
/**
 * SMS 2 - Student Club Membership
 * Module: Co-Curricular
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

if (getCurrentUserRoleKey() !== 'student') {
    header('Location: ' . BASE_URL . '/dashboard/index.php');
    exit;
}

$pageTitle    = 'My Club Memberships';
$isStudentPortalView = true;
$activeModule = $isStudentPortalView ? 'student_portal' : 'cocurricular';
$activePage   = 'student-club-membership';
$breadcrumbs  = $isStudentPortalView
    ? [
        ['label' => 'Student Portal', 'url' => BASE_URL . '/modules/student-portal/pages/dashboard.php'],
        ['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/pages/club-directory.php'],
        ['label' => 'My Club Memberships', 'url' => null],
    ]
    : [
        ['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/index.php'],
        ['label' => 'My Club Memberships', 'url' => null],
    ];
$pageBannerDescription = 'Review your submitted club applications, membership status, and participation details in one place.';
$pageBannerIcon = 'fa-users';

$userId = getCurrentUserId();
$student = cocurricularStudentProfile();
$memberships = $userId ? cocurricularFetchStudentMemberships($userId) : [];

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
?>
<link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css" rel="stylesheet">

<?php renderBreadcrumbs($breadcrumbs); ?>

<!-- Top-right action: Browse club directory (moved out of Student Details card) -->
<div class="d-flex justify-content-end mb-3">
    <div class="d-inline-block">
        <a href="<?= BASE_URL ?>/modules/cocurricular/pages/club-directory.php" class="btn btn-outline-primary btn-sm">Browse club directory</a>
    </div>
</div>

<?php if (!$userId || empty($student)): ?>
    <div class="alert alert-warning">Unable to locate your student profile. Please refresh or contact support if your account is not linked properly.</div>
<?php else: ?>
    <section class="card mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h2 class="h6 fw-semibold mb-2">Student details</h2>
                    <p class="mb-1"><strong>Name:</strong> <?= htmlspecialchars($student['full_name']) ?></p>
                    <p class="mb-1"><strong>Student ID:</strong> <?= htmlspecialchars($student['student_id']) ?></p>
                    <p class="mb-1"><strong>Program:</strong> <?= htmlspecialchars($student['program']) ?></p>
                    <p class="mb-0"><strong>Year level:</strong> <?= htmlspecialchars($student['year_level']) ?></p>
                </div>
                <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                    <!-- Button moved to top-right above the section -->
                </div>
            </div>
        </div>
    </section>

    <?php if (empty($memberships)): ?>
        <div class="alert alert-info">You have not submitted any club membership applications yet. Browse the club directory to apply.</div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($memberships as $membership): ?>
                <?php $isApproved = (string) ($membership['status'] ?? '') === 'Approved'; ?>
                <div class="col-12">
                    <section class="card cocurricular-card">
                        <div class="card-body">
                            <div class="d-flex flex-column flex-md-row justify-content-between gap-3 align-items-start">
                                <div>
                                    <h5 class="card-title mb-1"><?= htmlspecialchars($membership['club_name'] ?: 'Unknown club') ?></h5>
                                    <p class="small text-muted mb-1"><?= htmlspecialchars($membership['category'] ?? 'General') ?></p>
                                </div>
                                <?php if ($isApproved): ?>
                                    <span class="badge rounded-pill bg-success membership-status">Approved</span>
                                <?php elseif ((string) ($membership['status'] ?? '') === 'Rejected'): ?>
                                    <span class="badge rounded-pill bg-danger membership-status">Rejected</span>
                                <?php else: ?>
                                    <span class="badge rounded-pill bg-warning text-dark membership-status">Pending</span>
                                <?php endif; ?>
                            </div>

                            <div class="row g-3 mt-2 align-items-center">
                                <div class="col-md-9">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <p class="mb-1"><strong>Submitted</strong></p>
                                            <p class="small text-muted"><?= htmlspecialchars(!empty($membership['submitted_at']) ? date('M j, Y', strtotime($membership['submitted_at'])) : '—') ?></p>
                                        </div>
                                        <div class="col-md-4">
                                            <p class="mb-1"><strong>Preferred participation</strong></p>
                                            <p class="small text-muted"><?= htmlspecialchars($membership['preferred_participation'] ?? 'Not specified') ?></p>
                                        </div>
                                        <div class="col-md-4">
                                            <p class="mb-1"><strong>Review date</strong></p>
                                            <p class="small text-muted"><?= htmlspecialchars(!empty($membership['reviewed_at']) ? date('M j, Y', strtotime($membership['reviewed_at'])) : ($isApproved ? 'Approved' : 'Pending review')) ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-12">
                                    <div>
                                        <p class="mb-1"><strong>Reason for joining</strong></p>
                                        <p class="small text-muted mb-2"><?= nl2br(htmlspecialchars((string) ($membership['reason_for_joining'] ?? ''))) ?></p>
                                        <p class="mb-1"><strong>Areas of interest</strong></p>
                                        <p class="small text-muted"><?= nl2br(htmlspecialchars((string) ($membership['areas_of_interest'] ?? ''))) ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
                                <a href="<?= BASE_URL ?>/modules/cocurricular/pages/student-club-membership.php?application_id=<?= (int) ($membership['id'] ?? 0) ?>" class="btn btn-outline-primary btn-sm">View Application</a>
                                <?php if ($isApproved): ?>
                                    <a href="<?= BASE_URL ?>/modules/cocurricular/pages/my-club.php?club_id=<?= (int) ($membership['club_id'] ?? 0) ?>" class="btn btn-primary btn-sm">Open My Club</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
