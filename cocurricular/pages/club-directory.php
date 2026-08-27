<?php
/**
 * SMS 2 - Club Directory
 * Module: Co-Curricular
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

if (getCurrentUserRoleKey() !== 'student') {
    header('Location: ' . BASE_URL . '/dashboard/index.php');
    exit;
}

$pageTitle    = 'Club Directory';
$isStudentPortalView = true;
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
        ['label' => 'Club Directory', 'url' => null],
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

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
$pageBannerDescription = 'Browse student clubs, view essential details, and apply for membership through the Co-Curricular Club Directory.';
$pageBannerIcon = 'fa-users';
?>
<link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css" rel="stylesheet">

<?php renderBreadcrumbs($breadcrumbs); ?>

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
                     data-club-name="<?= htmlspecialchars($club['club_name']) ?>"
                     data-club-adviser="<?= htmlspecialchars($club['adviser']) ?>"
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
                            <strong>Adviser:</strong> <?= htmlspecialchars($club['adviser']) ?><br>
                            <strong>Members:</strong> <?= htmlspecialchars((string) $club['member_count']) ?>
                        </div>
                        <div class="mt-auto d-flex justify-content-between align-items-center gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" data-cocurricular-club-id="<?= htmlspecialchars($club['id']) ?>">View Details</button>
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
                            <a href="#" class="btn btn-primary w-100" data-join-href data-base-url="<?= htmlspecialchars(BASE_URL) ?>">Join Club</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.cocurricularClubs = <?= json_encode($clubList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= BASE_URL ?>/modules/cocurricular/assets/js/cocurricular.js"></script>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
