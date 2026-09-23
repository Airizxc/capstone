<?php
/**
 * SMS 2 - Club Directory
 * Module: Co-Curricular
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

requireAuth();
$roleKey = getCurrentUserRoleKey();
$isStudent = ($roleKey === 'student');
$isOsaAdmin = ($roleKey === 'osa' || smsIsGrantedAdminRole($roleKey) || userCanAccessModule('cocurricular'));

if (!$isStudent && !$isOsaAdmin) {
    header('Location: ' . BASE_URL . '/dashboard/index.php');
    exit;
}

$notice = '';
$noticeType = 'success';
$currentUserId = (int) getCurrentUserId();

// Handle fallback standard POST assignment if submitted directly without JS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_adviser') {
    if (!$isOsaAdmin) {
        http_response_code(403);
        exit('Unauthorized');
    }

    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token !== '' && function_exists('verifyCsrfToken') && !verifyCsrfToken($token)) {
        $notice = 'Security token expired. Please try again.';
        $noticeType = 'danger';
    } else {
        $postClubId = (int) ($_POST['club_id'] ?? 0);
        $postFacultyId = (int) ($_POST['faculty_user_id'] ?? 0);
        $result = cocurricularAssignClubAdviser($postClubId, $postFacultyId, $currentUserId);
        $notice = (string) ($result['message'] ?? 'Adviser assignment processed.');
        $noticeType = ($result['success'] ?? false) ? 'success' : 'danger';
    }
}

$pageTitle    = $isOsaAdmin ? 'Clubs & Organizations' : 'Club Directory';
$isStudentPortalView = $isStudent;
$activeModule = $isStudentPortalView ? 'student_portal' : 'cocurricular';
$activePage   = 'club-directory';
$breadcrumbs  = $isStudentPortalView
    ? [
        ['label' => 'Student Portal', 'url' => BASE_URL . '/modules/student-portal/pages/dashboard.php'],
        ['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/pages/club-directory.php'],
        ['label' => 'Club Directory', 'url' => null],
    ]
    : [
        ['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/index.php'],
        ['label' => 'Clubs & Organizations', 'url' => null],
    ];

$search = trim((string) ($_GET['search'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));

$clubs = cocurricularFetchClubs($search, $status, $category);
$categories = cocurricularFetchCategories();
$summary = cocurricularGetDirectorySummary();
$clubList = [];
foreach ($clubs as $club) {
    $club['officers'] = cocurricularFetchClubOfficers((int) $club['id']);
    $club['member_count'] = cocurricularCountClubMembers((int) $club['id']);
    $clubList[] = $club;
}

$facultyList = $isOsaAdmin ? cocurricularGetEligibleFacultyUsers() : [];

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
$pageBannerDescription = $isOsaAdmin
    ? 'Manage student clubs, view organization details, and assign verified faculty advisers from the institution.'
    : 'Browse student clubs, view essential details, and apply for membership through the Co-Curricular Club Directory.';
$pageBannerIcon = 'fa-users';
?>
<link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css" rel="stylesheet">

<?php renderBreadcrumbs($breadcrumbs); ?>

<?php if ($notice !== ''): ?>
    <div class="alert alert-<?= htmlspecialchars($noticeType) ?> alert-dismissible fade show mb-4" role="alert">
        <?= htmlspecialchars($notice) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4 dashboard-stats">
    <?php foreach ([
        ['label' => 'Total Clubs', 'value' => $summary['total'] ?? 0, 'icon' => 'fa-list', 'type' => 'primary'],
        ['label' => 'Active Clubs', 'value' => $summary['active'] ?? 0, 'icon' => 'fa-check-circle', 'type' => 'success'],
        ['label' => 'Pending Review', 'value' => $summary['pending'] ?? 0, 'icon' => 'fa-clock', 'type' => 'warning'],
        ['label' => 'Inactive Clubs', 'value' => $summary['inactive'] ?? 0, 'icon' => 'fa-ban', 'type' => 'secondary'],
    ] as $stat): ?>
        <div class="col-6 col-xl-3">
            <section class="card stat-card <?= htmlspecialchars($stat['type']) ?>">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3"><i class="fas <?= htmlspecialchars($stat['icon']) ?>"></i></div>
                    <div>
                        <h6 class="text-muted mb-0 small"><?= htmlspecialchars($stat['label']) ?></h6>
                        <h4 class="mb-0 fw-bold"><?= htmlspecialchars((string) $stat['value']) ?></h4>
                    </div>
                </div>
            </section>
        </div>
    <?php endforeach; ?>
</div>

<section class="card cocurricular-filter-card mb-4">
    <div class="card-body">
        <h2 class="h6 fw-semibold mb-3">Search clubs</h2>
        <form class="cocurricular-filter-form" novalidate>
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small" for="search">Keyword</label>
                    <input id="search" type="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Search by club name, adviser, or category">
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="status">Status</label>
                    <select id="status" class="form-select">
                        <option value="">All statuses</option>
                        <?php foreach (['Active', 'Pending', 'Inactive'] as $option): ?>
                            <option value="<?= htmlspecialchars($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="category">Category</label>
                    <select id="category" class="form-select">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $option): ?>
                            <option value="<?= htmlspecialchars($option) ?>" <?= $category === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </div>
</section>

<div id="cocurricularNoResults" class="alert alert-info" style="display:none;">No clubs match your search. Try a different keyword, status, or category.</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('.cocurricular-filter-form');
        if (!form) {
            return;
        }

        var search = form.querySelector('#search');
        var status = form.querySelector('#status');
        var category = form.querySelector('#category');
        var cards = Array.from(document.querySelectorAll('.cocurricular-club-card'));
        var noResults = document.getElementById('cocurricularNoResults');

        function normalize(value) {
            return String(value || '').trim().toLowerCase();
        }

        function applyFilter() {
            var query = normalize(search.value);
            var statusValue = normalize(status.value);
            var categoryValue = normalize(category.value);

            var visibleCount = 0;

            cards.forEach(function (card) {
                var name = normalize(card.dataset.clubName);
                var adviser = normalize(card.dataset.clubAdviser);
                var clubCategory = normalize(card.dataset.clubCategory);
                var clubStatus = normalize(card.dataset.clubStatus);
                var description = normalize(card.dataset.clubDescription);

                var matchesSearch = query === '' || name.indexOf(query) !== -1 || adviser.indexOf(query) !== -1 || clubCategory.indexOf(query) !== -1 || description.indexOf(query) !== -1;
                var matchesStatus = statusValue === '' || clubStatus === statusValue;
                var matchesCategory = categoryValue === '' || clubCategory === categoryValue;
                var visible = matchesSearch && matchesStatus && matchesCategory;

                var column = card.closest('.col');
                if (column) {
                    column.style.display = visible ? '' : 'none';
                }
                card.style.display = visible ? '' : 'none';
                if (visible) {
                    visibleCount += 1;
                }
            });

            if (noResults) {
                noResults.style.display = visibleCount === 0 ? 'block' : 'none';
            }
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
        });

        var searchTimeout = null;
        search.addEventListener('input', function () {
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            searchTimeout = setTimeout(applyFilter, 250);
        });
        status.addEventListener('change', applyFilter);
        category.addEventListener('change', applyFilter);

        applyFilter();
    });
</script>

<?php if (empty($clubList)): ?>
    <div class="alert alert-info">No clubs match your search. Try a different keyword, status, or category.</div>
<?php else: ?>
    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mb-4">
        <?php foreach ($clubList as $club): ?>
            <div class="col">
                <div class="card cocurricular-card cocurricular-club-card h-100"
                     data-club-id="<?= htmlspecialchars((string) $club['id']) ?>"
                     data-club-name="<?= htmlspecialchars($club['club_name']) ?>"
                     data-club-adviser="<?= htmlspecialchars($club['adviser']) ?>"
                     data-club-adviser-id="<?= htmlspecialchars((string) ($club['adviser_id'] ?? '')) ?>"
                     data-club-adviser-email="<?= htmlspecialchars((string) ($club['adviser_email'] ?? '')) ?>"
                     data-club-category="<?= htmlspecialchars($club['category']) ?>"
                     data-club-status="<?= htmlspecialchars($club['status']) ?>"
                     data-club-description="<?= htmlspecialchars(strip_tags($club['description'])) ?>">
                    <div class="card-body d-flex flex-column">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <h5 class="card-title mb-1"><?= htmlspecialchars($club['club_name']) ?></h5>
                                    <p class="mb-1 small text-muted"><?= htmlspecialchars($club['category']) ?></p>
                                </div>
                                <span class="badge rounded-pill bg-<?= $club['status'] === 'Active' ? 'success' : ($club['status'] === 'Pending' ? 'warning' : 'secondary') ?> cocurricular-status-badge"><?= htmlspecialchars($club['status']) ?></span>
                            </div>
                        </div>
                        <p class="text-muted small mb-3"><?= htmlspecialchars(mb_substr($club['description'], 0, 130)) ?><?= mb_strlen($club['description']) > 130 ? '…' : '' ?></p>
                        <div class="mb-3 small">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-1 mb-1">
                                <div>
                                    <strong>Adviser:</strong> <span class="club-card-adviser-name"><?= htmlspecialchars($club['adviser']) ?></span>
                                </div>
                                <?php if (!empty($club['adviser_id'])): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle club-card-adviser-badge" title="Verified Faculty Account">
                                        <i class="fas fa-check-circle me-1"></i>Faculty
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <strong>Members:</strong> <?= htmlspecialchars((string) $club['member_count']) ?>
                            </div>
                        </div>
                        <div class="mt-auto d-flex justify-content-between align-items-center gap-2 flex-wrap">
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-outline-primary btn-sm" data-cocurricular-club-id="<?= htmlspecialchars($club['id']) ?>">View Details</button>
                                <?php if ($isOsaAdmin): ?>
                                    <button type="button" class="btn btn-primary btn-sm btn-assign-adviser"
                                            data-club-id="<?= (int) $club['id'] ?>"
                                            data-club-name="<?= htmlspecialchars($club['club_name']) ?>"
                                            data-club-category="<?= htmlspecialchars($club['category']) ?>"
                                            data-current-adviser-id="<?= (int) ($club['adviser_id'] ?? 0) ?>"
                                            data-current-adviser-name="<?= htmlspecialchars($club['adviser']) ?>"
                                            data-current-adviser-email="<?= htmlspecialchars((string) ($club['adviser_email'] ?? '')) ?>"
                                            title="Assign or reassign faculty adviser">
                                        <i class="fas fa-user-tie me-1"></i>Assign Adviser
                                    </button>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted">Officers <?= htmlspecialchars((string) count($club['officers'])) ?></small>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="modal fade cocurricular-modal" id="cocurricularClubDetailModal" tabindex="-1" aria-labelledby="cocurricularClubDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg cocurricular-modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="cocurricularClubDetailModalLabel" data-club-name>Club Details</h5>
                    <p class="small text-muted mb-0"><span data-club-category></span> · <span data-club-status class="badge"></span></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body cocurricular-modal-body">
                <div class="row g-4">
                    <div class="col-lg-7">
                        <h6 class="mb-3">About this club</h6>
                        <p data-club-description class="mb-4"></p>
                        <ul class="list-unstyled cocurricular-detail-list mb-0">
                            <li><strong>Adviser:</strong> <span data-club-adviser></span></li>
                            <li><strong>Email:</strong> <span data-club-email></span></li>
                            <li><strong>Contact:</strong> <span data-club-contact></span></li>
                            <li><strong>Current members:</strong> <span data-club-members></span></li>
                        </ul>
                    </div>
                    <div class="col-lg-5">
                        <h6 class="mb-3">Club officers</h6>
                        <ul class="list-unstyled cocurricular-detail-list" data-club-officers></ul>
                        <div class="mt-4">
                            <?php if ($isStudent): ?>
                                <a href="#" class="btn btn-primary w-100" data-join-href data-base-url="<?= htmlspecialchars(BASE_URL) ?>">Join Club</a>
                            <?php else: ?>
                                <a href="#" class="btn btn-primary w-100 d-none" data-join-href data-base-url="<?= htmlspecialchars(BASE_URL) ?>">Join Club</a>
                                <button type="button" class="btn btn-primary w-100" id="detailModalAssignBtn">
                                    <i class="fas fa-user-tie me-1"></i> Assign Faculty Adviser
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($isOsaAdmin): ?>
<!-- Assign Faculty Adviser Modal -->
<div class="modal fade cocurricular-modal" id="cocurricularAssignAdviserModal" tabindex="-1" aria-labelledby="cocurricularAssignAdviserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="cocurricularAssignAdviserForm" method="POST" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                <input type="hidden" name="action" value="assign_adviser">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrfToken') ? csrfToken() : '') ?>">
                <input type="hidden" name="club_id" id="assignModalClubId" value="">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="cocurricularAssignAdviserModalLabel">Assign Faculty Adviser</h5>
                        <p class="small text-muted mb-0" id="assignModalClubSubtitle">Select a verified faculty account from the system.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="assignAdviserAlert" class="alert d-none mb-3" role="alert"></div>

                    <div class="card bg-light border-0 mb-3">
                        <div class="card-body p-3">
                            <div class="small text-muted mb-1">Target Club</div>
                            <h6 class="fw-bold mb-1" id="assignModalClubName">-</h6>
                            <div class="small text-muted" id="assignModalClubMeta">-</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-muted mb-1">Current Assigned Adviser</label>
                        <div class="p-2 border rounded bg-white small d-flex align-items-center justify-content-between" id="assignModalCurrentBox">
                            <div>
                                <strong id="assignModalCurrentName">None</strong>
                                <span class="text-muted d-block" id="assignModalCurrentEmail">No email on record</span>
                            </div>
                            <span id="assignModalCurrentBadge" class="badge bg-secondary-subtle text-secondary border">Unassigned</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="facultyAdviserSelect" class="form-label fw-semibold">Select Faculty Adviser <span class="text-danger">*</span></label>
                        <select class="form-select" id="facultyAdviserSelect" name="faculty_user_id" required>
                            <option value="">-- Select an active Faculty member --</option>
                            <?php foreach ($facultyList as $fac): ?>
                                <option value="<?= (int) $fac['id'] ?>">
                                    <?= htmlspecialchars($fac['full_name']) ?> — <?= htmlspecialchars($fac['role_label']) ?> (<?= htmlspecialchars($fac['email']) ?>)
                                </option>
                            <?php endforeach; ?>
                            <option value="0">-- Remove / Unassign Adviser --</option>
                        </select>
                        <div class="form-text small">
                            <i class="fas fa-info-circle me-1"></i>Retrieved from system faculty accounts in <code>sms2_db.users</code>.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="assignSubmitBtn">
                        <i class="fas fa-save me-1"></i> Save Adviser Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    window.cocurricularClubs = <?= json_encode($clubList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.cocurricularIsOsaAdmin = <?= $isOsaAdmin ? 'true' : 'false' ?>;
</script>
<script src="<?= BASE_URL ?>/modules/cocurricular/assets/js/cocurricular.js"></script>

<?php if ($isOsaAdmin): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var assignModalEl = document.getElementById('cocurricularAssignAdviserModal');
    var detailModalEl = document.getElementById('cocurricularClubDetailModal');
    if (!assignModalEl || typeof bootstrap === 'undefined') {
        return;
    }

    var assignModal = new bootstrap.Modal(assignModalEl);
    var detailModal = detailModalEl ? bootstrap.Modal.getInstance(detailModalEl) || new bootstrap.Modal(detailModalEl) : null;

    var form = document.getElementById('cocurricularAssignAdviserForm');
    var clubIdInput = document.getElementById('assignModalClubId');
    var clubNameEl = document.getElementById('assignModalClubName');
    var clubMetaEl = document.getElementById('assignModalClubMeta');
    var curNameEl = document.getElementById('assignModalCurrentName');
    var curEmailEl = document.getElementById('assignModalCurrentEmail');
    var curBadgeEl = document.getElementById('assignModalCurrentBadge');
    var facultySelect = document.getElementById('facultyAdviserSelect');
    var alertBox = document.getElementById('assignAdviserAlert');
    var submitBtn = document.getElementById('assignSubmitBtn');
    var activeInspectedClubId = null;

    function openAssignModalForClub(clubData) {
        if (!clubData) return;

        activeInspectedClubId = clubData.id;
        clubIdInput.value = clubData.id;
        clubNameEl.textContent = clubData.club_name || '-';
        clubMetaEl.textContent = (clubData.category || 'Category') + ' · Status: ' + (clubData.status || 'Active');

        var adviserName = clubData.adviser || 'None';
        var adviserEmail = clubData.adviser_email || 'No email on record';
        var adviserId = parseInt(clubData.adviser_id, 10) || 0;

        curNameEl.textContent = adviserName;
        curEmailEl.textContent = adviserEmail;

        if (adviserId > 0) {
            curBadgeEl.className = 'badge bg-success-subtle text-success border border-success-subtle';
            curBadgeEl.textContent = 'Faculty Assigned';
            facultySelect.value = String(adviserId);
        } else {
            curBadgeEl.className = 'badge bg-secondary-subtle text-secondary border';
            curBadgeEl.textContent = (adviserName && adviserName !== 'None') ? 'External / Legacy' : 'Unassigned';
            facultySelect.value = '';
        }

        alertBox.className = 'alert d-none mb-3';
        alertBox.textContent = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save me-1"></i> Save Adviser Assignment';

        assignModal.show();
    }

    // Attach click listeners to all "Assign Adviser" buttons on cards
    document.querySelectorAll('.btn-assign-adviser').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var clubId = btn.getAttribute('data-club-id');
            var club = (window.cocurricularClubs || []).find(function (c) {
                return parseInt(c.id, 10) === parseInt(clubId, 10);
            });
            if (!club) {
                club = {
                    id: clubId,
                    club_name: btn.getAttribute('data-club-name'),
                    category: btn.getAttribute('data-club-category'),
                    adviser_id: btn.getAttribute('data-current-adviser-id'),
                    adviser: btn.getAttribute('data-current-adviser-name'),
                    adviser_email: btn.getAttribute('data-current-adviser-email')
                };
            }
            openAssignModalForClub(club);
        });
    });

    // Listen to View Details modal's Assign button
    var detailModalAssignBtn = document.getElementById('detailModalAssignBtn');
    if (detailModalAssignBtn) {
        detailModalAssignBtn.addEventListener('click', function () {
            var detailModalLabel = document.getElementById('cocurricularClubDetailModalLabel');
            var inspectedName = detailModalLabel ? detailModalLabel.textContent.trim() : '';
            var club = (window.cocurricularClubs || []).find(function (c) {
                return c.club_name === inspectedName;
            });
            if (club) {
                if (detailModal) {
                    detailModal.hide();
                }
                setTimeout(function () {
                    openAssignModalForClub(club);
                }, 300);
            }
        });
    }

    // Handle AJAX Form Submission
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var clubId = clubIdInput.value;
            var facultyId = facultySelect.value;
            var csrfToken = form.querySelector('input[name="csrf_token"]').value;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';

            fetch('<?= BASE_URL ?>/modules/cocurricular/endpoints/assign-adviser.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    action: 'assign',
                    club_id: parseInt(clubId, 10),
                    faculty_user_id: parseInt(facultyId, 10),
                    csrf_token: csrfToken
                })
            })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (result) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save me-1"></i> Save Adviser Assignment';

                if (result.ok && result.data.success) {
                    alertBox.className = 'alert alert-success mb-3';
                    alertBox.textContent = result.data.message || 'Adviser assigned successfully.';

                    var updatedClub = result.data.club;
                    var assignedAdviser = result.data.adviser;

                    // Update cached club in window.cocurricularClubs
                    if (updatedClub && Array.isArray(window.cocurricularClubs)) {
                        var idx = window.cocurricularClubs.findIndex(function (c) {
                            return parseInt(c.id, 10) === parseInt(clubId, 10);
                        });
                        if (idx !== -1) {
                            window.cocurricularClubs[idx].adviser = updatedClub.adviser;
                            window.cocurricularClubs[idx].adviser_id = updatedClub.adviser_id;
                            window.cocurricularClubs[idx].adviser_email = updatedClub.adviser_email;
                        }
                    }

                    // Update DOM card
                    var cardEl = document.querySelector('.cocurricular-club-card[data-club-id="' + clubId + '"]');
                    if (cardEl) {
                        cardEl.dataset.clubAdviser = updatedClub ? updatedClub.adviser : (assignedAdviser ? assignedAdviser.full_name : '');
                        cardEl.dataset.clubAdviserId = updatedClub ? updatedClub.adviser_id : (assignedAdviser ? assignedAdviser.id : '');
                        var nameSpan = cardEl.querySelector('.club-card-adviser-name');
                        if (nameSpan) {
                            nameSpan.textContent = updatedClub ? updatedClub.adviser : (assignedAdviser ? assignedAdviser.full_name : 'None');
                        }
                        var badgeEl = cardEl.querySelector('.club-card-adviser-badge');
                        if (updatedClub && updatedClub.adviser_id > 0) {
                            if (!badgeEl) {
                                var badgeContainer = cardEl.querySelector('.small .d-flex');
                                if (badgeContainer) {
                                    badgeEl = document.createElement('span');
                                    badgeEl.className = 'badge bg-success-subtle text-success border border-success-subtle club-card-adviser-badge';
                                    badgeEl.title = 'Verified Faculty Account';
                                    badgeEl.innerHTML = '<i class="fas fa-check-circle me-1"></i>Faculty';
                                    badgeContainer.appendChild(badgeEl);
                                }
                            }
                        } else if (badgeEl) {
                            badgeEl.remove();
                        }

                        // Update assign button attributes
                        var assignBtn = cardEl.querySelector('.btn-assign-adviser');
                        if (assignBtn && updatedClub) {
                            assignBtn.setAttribute('data-current-adviser-id', updatedClub.adviser_id || '');
                            assignBtn.setAttribute('data-current-adviser-name', updatedClub.adviser || '');
                            assignBtn.setAttribute('data-current-adviser-email', updatedClub.adviser_email || '');
                        }
                    }

                    // Auto dismiss modal after 1.2s
                    setTimeout(function () {
                        assignModal.hide();
                    }, 1200);
                } else {
                    alertBox.className = 'alert alert-danger mb-3';
                    alertBox.textContent = result.data.message || 'Failed to save adviser assignment.';
                }
            })
            .catch(function (err) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save me-1"></i> Save Adviser Assignment';
                alertBox.className = 'alert alert-danger mb-3';
                alertBox.textContent = 'A network or server error occurred. Please try again.';
            });
        });
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
