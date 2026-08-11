<?php
/**
 * SMS 2 - Club Registration Portal
 * Module: Co-Curricular
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

$pageTitle    = 'Club Registration Portal';
$activeModule = 'cocurricular';
$activePage   = 'club-registration-portal';
$breadcrumbs  = [
    ['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/index.php'],
    ['label' => 'Club Registration Portal', 'url' => null],
];

$clubId = (int) ($_GET['club_id'] ?? 0);
$club = $clubId ? cocurricularGetClubById($clubId) : null;
$userId = getCurrentUserId();
$student = cocurricularStudentProfile();
$successMessage = '';
$showSuccess = false;
$latestMembership = null;
$errors = [];
$formData = [
    'reason_for_joining' => '',
    'areas_of_interest' => '',
    'preferred_participation' => '',
    'agreement' => false,
];
$isAjaxRequest = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjaxRequest = !empty($_POST['ajax']) ||
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

    $formData['reason_for_joining'] = trim((string) ($_POST['reason_for_joining'] ?? ''));
    $formData['areas_of_interest'] = trim((string) ($_POST['areas_of_interest'] ?? ''));
    $formData['preferred_participation'] = trim((string) ($_POST['preferred_participation'] ?? ''));
    $formData['agreement'] = !empty($_POST['agreement']);

    if (!$club) {
        $errors[] = 'Invalid club selected for registration.';
    }
    if (!$userId || empty($student)) {
        $errors[] = 'Unable to identify your student account. Please refresh and try again.';
    }
    if ($club && $club['status'] !== 'Active') {
        $errors[] = 'Registration is only open for active clubs.';
    }
    if ($formData['reason_for_joining'] === '') {
        $errors[] = 'Please tell us why you want to join this club.';
    }
    if ($formData['areas_of_interest'] === '') {
        $errors[] = 'Please describe your areas of interest.';
    }
    if ($formData['preferred_participation'] === '') {
        $errors[] = 'Please select your preferred participation type.';
    }
    if (!$formData['agreement']) {
        $errors[] = 'You must agree to the club membership terms before submitting.';
    }

    if (empty($errors) && $club && $userId) {
        $membershipStatus = cocurricularStudentMembershipStatus((int) $club['id'], $userId);
        if (!empty($membershipStatus['pending']) || !empty($membershipStatus['approved'])) {
            $errors[] = 'You already have a pending or approved membership request for this club.';
        }
    }

    if (empty($errors) && $club && $userId) {
        $result = cocurricularCreateMembershipApplication([
            'club_id' => (int) $club['id'],
            'user_id' => $userId,
            'student_id' => $student['student_id'] ?: 'Unknown',
            'reason_for_joining' => cocurricularNormalizeText($formData['reason_for_joining']),
            'areas_of_interest' => cocurricularNormalizeText($formData['areas_of_interest']),
            'preferred_participation' => cocurricularNormalizeText($formData['preferred_participation']),
            'agreement' => $formData['agreement'],
        ]);

        if ($result) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'redirect' => BASE_URL . '/modules/cocurricular/pages/club-registration-portal.php?club_id=' . urlencode((string) $club['id']) . '&joined=1']);
                exit;
            }

            header('Location: ' . BASE_URL . '/modules/cocurricular/pages/club-registration-portal.php?club_id=' . urlencode((string) $club['id']) . '&joined=1');
            exit;
        }

        $errors[] = 'Unable to submit your membership request at this time. Please try again later.';
    }

    if ($isAjaxRequest && !empty($errors)) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'errors' => $errors]);
        exit;
    }
}

if (isset($_GET['joined']) && $club && !$isAjaxRequest) {
    $showSuccess = true;
    $latestMembership = cocurricularFetchLatestStudentMembershipForClub((int) $club['id'], $userId);
}

if (isset($_GET['joined']) && $club) {
    $successMessage = 'Your membership request for ' . htmlspecialchars($club['club_name']) . ' has been submitted and is pending review.';
}

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
?>
<link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css" rel="stylesheet">

<?php renderBreadcrumbs($breadcrumbs); ?>

<?php if (!$club): ?>
    <div class="alert alert-danger">Please select a club from the <a href="<?= BASE_URL ?>/modules/cocurricular/pages/club-directory.php">Club Directory</a> before registering.</div>
<?php elseif ($showSuccess && $latestMembership): ?>
    <section class="card mb-4">
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-3 align-items-start">
                <div>
                    <h2 class="h5 fw-semibold mb-2">Application Submitted Successfully</h2>
                    <p class="mb-2 text-muted">Your application is now pending adviser approval.</p>
                    <p class="mb-1"><strong>Club:</strong> <?= htmlspecialchars($club['club_name']) ?></p>
                    <p class="mb-1"><strong>Status:</strong> <span class="badge rounded-pill bg-warning">Pending</span></p>
                    <p class="mb-0"><strong>Application Date:</strong> <?= htmlspecialchars(date('M j, Y', strtotime($latestMembership['submitted_at']))) ?></p>
                </div>
                <div class="d-flex flex-column gap-2">
                    <a href="<?= BASE_URL ?>/modules/cocurricular/pages/student-club-membership.php" class="btn btn-primary">View My Club Memberships</a>
                    <a href="<?= BASE_URL ?>/modules/cocurricular/pages/club-directory.php" class="btn btn-outline-secondary">Back to Club Directory</a>
                </div>
            </div>
        </div>
    </section>
<?php else: ?>
    <div class="row g-4 justify-content-center">
        <div class="col-12 col-lg-7 col-xl-6 mx-auto">
            <section class="card mb-4">
                <div class="card-body">
                    <h2 class="h6 fw-semibold mb-3">Club registration</h2>
                    <div class="cocurricular-stepper mb-3">
                        <div class="cocurricular-step is-active">
                            <span class="cocurricular-step-icon">1</span>
                            <span class="cocurricular-step-label">Application</span>
                        </div>
                        <div class="cocurricular-step-line"></div>
                        <div class="cocurricular-step">
                            <span class="cocurricular-step-icon">2</span>
                            <span class="cocurricular-step-label">Review</span>
                        </div>
                    </div>
                    <p class="mb-2"><strong>Club:</strong> <?= htmlspecialchars($club['club_name']) ?></p>
                    <p class="mb-2"><strong>Category:</strong> <?= htmlspecialchars($club['category']) ?></p>
                    <p class="mb-2"><strong>Adviser:</strong> <?= htmlspecialchars($club['adviser']) ?></p>
                    <p class="mb-0"><strong>Status:</strong> <span class="badge rounded-pill bg-<?= $club['status'] === 'Active' ? 'success' : ($club['status'] === 'Pending' ? 'warning' : 'secondary') ?>"><?= htmlspecialchars($club['status']) ?></span></p>
                </div>
            </section>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success"><?= $successMessage ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($club['status'] !== 'Active'): ?>
                <div class="alert alert-warning">This club is not open for new membership applications at this time.</div>
            <?php endif; ?>

            <section class="card mb-4">
                <div class="card-body">
                    <h2 class="h6 fw-semibold mb-3">Your details</h2>
                    <p class="mb-1"><strong>Name:</strong> <?= htmlspecialchars($student['full_name'] ?? 'N/A') ?></p>
                    <p class="mb-1"><strong>Student ID:</strong> <?= htmlspecialchars($student['student_id'] ?? 'N/A') ?></p>
                    <p class="mb-0"><strong>Email:</strong> <?= htmlspecialchars($student['email'] ?? 'N/A') ?></p>
                </div>
            </section>

            <form id="cocurricularRegistrationForm" method="post" action="<?= htmlspecialchars(BASE_URL . '/modules/cocurricular/pages/club-registration-portal.php?club_id=' . urlencode((string) $club['id'])) ?>">
                <input type="hidden" name="ajax" id="cocurricularAjaxIndicator" value="0">
                <section class="card cocurricular-step-panel is-active" data-step-panel="1">
                    <div class="card-body">
                        <div id="cocurricularReviewValidationAlert" class="alert alert-danger d-none"></div>
                        <div class="mb-3">
                            <label class="form-label" for="reason_for_joining">Why do you want to join this club?</label>
                            <textarea id="reason_for_joining" name="reason_for_joining" class="form-control" rows="4" required><?= htmlspecialchars($formData['reason_for_joining']) ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="areas_of_interest">Areas of interest</label>
                            <textarea id="areas_of_interest" name="areas_of_interest" class="form-control" rows="3" required><?= htmlspecialchars($formData['areas_of_interest']) ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="preferred_participation">Preferred participation</label>
                            <select id="preferred_participation" name="preferred_participation" class="form-select" required>
                                <option value="" <?= $formData['preferred_participation'] === '' ? 'selected' : '' ?>>Select participation type</option>
                                <option value="Event support" <?= $formData['preferred_participation'] === 'Event support' ? 'selected' : '' ?>>Event support</option>
                                <option value="Project team" <?= $formData['preferred_participation'] === 'Project team' ? 'selected' : '' ?>>Project team</option>
                                <option value="Leadership roles" <?= $formData['preferred_participation'] === 'Leadership roles' ? 'selected' : '' ?>>Leadership roles</option>
                                <option value="Volunteer work" <?= $formData['preferred_participation'] === 'Volunteer work' ? 'selected' : '' ?>>Volunteer work</option>
                                <option value="Community outreach" <?= $formData['preferred_participation'] === 'Community outreach' ? 'selected' : '' ?>>Community outreach</option>
                            </select>
                        </div>
                        <input type="hidden" id="agreement" name="agreement" value="0">
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="<?= BASE_URL ?>/modules/cocurricular/pages/club-directory.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="button" class="btn btn-primary" id="cocurricularNextBtn">Continue to Review</button>
                        </div>
                    </div>
                </section>
            </form>

            <div class="modal fade cocurricular-modal" id="cocurricularReviewModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="cocurricularReviewModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="cocurricularReviewModalLabel">Club Registration Review</h5>
                                <p class="small text-muted mb-0">Review your application before submitting.</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="cocurricular-stepper mb-4">
                                <div class="cocurricular-step" data-review-step="1">
                                    <span class="cocurricular-step-icon">1</span>
                                    <span class="cocurricular-step-label">Application</span>
                                </div>
                                <div class="cocurricular-step-line"></div>
                                <div class="cocurricular-step is-active" data-review-step="2">
                                    <span class="cocurricular-step-icon">2</span>
                                    <span class="cocurricular-step-label">Review</span>
                                </div>
                            </div>
                            <div id="cocurricularReviewError" class="alert alert-danger d-none"></div>
                            <section class="card mb-4">
                                <div class="card-body">
                                    <h3 class="h6 fw-semibold mb-3">Registration review</h3>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <p class="mb-1 text-muted small">Club</p>
                                            <p id="reviewClubName" class="mb-2 fw-semibold"></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mb-1 text-muted small">Category</p>
                                            <p id="reviewClubCategory" class="mb-2"></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mb-1 text-muted small">Status</p>
                                            <p id="reviewClubStatus" class="mb-2"></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mb-1 text-muted small">Adviser</p>
                                            <p id="reviewClubAdviser" class="mb-2"></p>
                                        </div>
                                    </div>
                                </div>
                            </section>
                            <section class="card mb-4">
                                <div class="card-body">
                                    <h3 class="h6 fw-semibold mb-3">Student information</h3>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <p class="mb-1 text-muted small">Name</p>
                                            <p id="reviewStudentName" class="mb-2 fw-semibold"></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mb-1 text-muted small">Student ID</p>
                                            <p id="reviewStudentId" class="mb-2"></p>
                                        </div>
                                        <div class="col-12">
                                            <p class="mb-1 text-muted small">Email</p>
                                            <p id="reviewStudentEmail" class="mb-2"></p>
                                        </div>
                                    </div>
                                </div>
                            </section>
                            <section class="card">
                                <div class="card-body">
                                    <h3 class="h6 fw-semibold mb-3">Application details</h3>
                                    <div class="mb-3">
                                        <p class="mb-1 text-muted small">Why do you want to join this club?</p>
                                        <p id="reviewReason" class="mb-2"></p>
                                    </div>
                                    <div class="mb-3">
                                        <p class="mb-1 text-muted small">Areas of interest</p>
                                        <p id="reviewInterest" class="mb-2"></p>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <p class="mb-1 text-muted small">Preferred participation</p>
                                            <p id="reviewParticipation" class="mb-2"></p>
                                        </div>
                                        <div class="col-md-6"></div>
                                    </div>
                                    <div class="form-check mt-3">
                                        <input class="form-check-input" type="checkbox" id="reviewAgreementCheckbox">
                                        <label class="form-check-label" for="reviewAgreementCheckbox">I agree to follow the club rules, attend meetings, and participate responsibly.</label>
                                    </div>
                                </div>
                            </section>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" id="cocurricularReviewBackBtn">Back to Application</button>
                            <button type="button" class="btn btn-primary" id="cocurricularReviewSubmitBtn">Submit Application</button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <script src="<?= BASE_URL ?>/modules/cocurricular/assets/js/cocurricular.js"></script>
    <!-- Success confirmation modal -->
    <div class="modal fade cocurricular-modal cocurricular-success-modal" id="cocurricularSuccessModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <div class="cocurricular-success-animation mb-3">
                        <svg class="cocurricular-success-svg" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle class="success-circle" cx="32" cy="32" r="28" />
                            <path class="success-check" d="M18 34 L28 44 L46 22" />
                        </svg>
                    </div>
                    <h5 class="fw-bold mb-2">Submission Completed</h5>
                    <p class="small mb-3">Your application has been submitted and is now pending advisor confirmation.</p>
                    <button type="button" class="btn btn-light" id="cocurricularSuccessOkBtn">OK</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Expose a helper to show the success modal and handle OK
        window.showCocurricularSuccessModal = function () {
            var modalEl = document.getElementById('cocurricularSuccessModal');
            if (!modalEl || typeof bootstrap === 'undefined') {
                // fallback to redirect if modal can't be shown
                var redirect = window._cocurricularRedirectAfterSuccess || null;
                if (redirect) {
                    window.location.href = redirect;
                }
                return;
            }

            var successModal = new bootstrap.Modal(modalEl, {
                backdrop: 'static',
                keyboard: false
            });

            var svg = modalEl.querySelector('.cocurricular-success-svg');
            if (svg) {
                // restart animation
                svg.classList.remove('play');
                void svg.offsetWidth;
                svg.classList.add('play');
            }

            // Clean any other visible modals/backdrops to avoid overlap
            Array.from(document.querySelectorAll('.modal.show')).forEach(function (m) {
                if (m !== modalEl) {
                    try { m.classList.remove('show'); m.style.display = 'none'; } catch (e) {}
                }
            });
            Array.from(document.querySelectorAll('.modal-backdrop')).forEach(function (b) {
                try { b.parentNode && b.parentNode.removeChild(b); } catch (e) {}
            });
            document.body.classList.remove('modal-open');
            document.body.style.paddingRight = '';

            var okBtn = document.getElementById('cocurricularSuccessOkBtn');
            if (okBtn) {
                // ensure previous handlers removed
                okBtn.onclick = function () {
                    var redirect = window._cocurricularRedirectAfterSuccess || null;
                    var target = redirect ? redirect : '<?= htmlspecialchars(BASE_URL . "/modules/cocurricular/pages/student-club-membership.php") ?>';

                    // If server redirected back to the registration page (contains joined=1 or points
                    // to club-registration-portal.php), prefer sending the user to the membership page
                    // so they can view their application.
                    try {
                        if (redirect) {
                            var parsed = new URL(redirect, window.location.origin);
                            var pathname = parsed.pathname || '';
                            var search = parsed.search || '';
                            if (pathname.indexOf('/modules/cocurricular/pages/club-registration-portal.php') !== -1 || search.indexOf('joined=1') !== -1) {
                                target = '<?= htmlspecialchars(BASE_URL . "/modules/cocurricular/pages/student-club-membership.php") ?>';
                            }
                        }
                    } catch (e) {
                        // ignore parse errors and fallback to default target
                    }

                    var onHiddenSuccess = function () {
                        modalEl.removeEventListener('hidden.bs.modal', onHiddenSuccess);
                        // ensure no lingering backdrops
                        Array.from(document.querySelectorAll('.modal-backdrop')).forEach(function (b) {
                            try { b.parentNode && b.parentNode.removeChild(b); } catch (e) {}
                        });
                        document.body.classList.remove('modal-open');
                        // navigate after modal fully hidden
                        window.location.href = target;
                    };

                    modalEl.addEventListener('hidden.bs.modal', onHiddenSuccess);
                    successModal.hide();
                };
            }

            successModal.show();
        };
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var reviewModalEl = document.getElementById('cocurricularReviewModal');
            if (!reviewModalEl || typeof bootstrap === 'undefined') {
                return;
            }

            var reviewModal = new bootstrap.Modal(reviewModalEl);
            var reviewButton = document.getElementById('cocurricularNextBtn');
            var reviewBackButton = document.getElementById('cocurricularReviewBackBtn');
            var reviewSubmitButton = document.getElementById('cocurricularReviewSubmitBtn');
            var reviewError = document.getElementById('cocurricularReviewError');
            var validationAlert = document.getElementById('cocurricularReviewValidationAlert');
            var ajaxIndicator = document.getElementById('cocurricularAjaxIndicator');
            var form = document.getElementById('cocurricularRegistrationForm');
            var clubName = '<?= htmlspecialchars(addslashes($club['club_name'] ?? '')) ?>';
            var clubCategory = '<?= htmlspecialchars(addslashes($club['category'] ?? '')) ?>';
            var clubStatus = '<?= htmlspecialchars(addslashes($club['status'] ?? '')) ?>';
            var clubAdviser = '<?= htmlspecialchars(addslashes($club['adviser'] ?? '')) ?>';
            var studentName = '<?= htmlspecialchars(addslashes($student['full_name'] ?? '')) ?>';
            var studentId = '<?= htmlspecialchars(addslashes($student['student_id'] ?? '')) ?>';
            var studentEmail = '<?= htmlspecialchars(addslashes($student['email'] ?? '')) ?>';

            var reviewClubName = document.getElementById('reviewClubName');
            var reviewClubCategory = document.getElementById('reviewClubCategory');
            var reviewClubStatus = document.getElementById('reviewClubStatus');
            var reviewClubAdviser = document.getElementById('reviewClubAdviser');
            var reviewStudentName = document.getElementById('reviewStudentName');
            var reviewStudentId = document.getElementById('reviewStudentId');
            var reviewStudentEmail = document.getElementById('reviewStudentEmail');
            var reviewReason = document.getElementById('reviewReason');
            var reviewInterest = document.getElementById('reviewInterest');
            var reviewParticipation = document.getElementById('reviewParticipation');
            var reviewAgreementCheckbox = document.getElementById('reviewAgreementCheckbox');

            function clearErrors() {
                reviewError.classList.add('d-none');
                reviewError.textContent = '';
                validationAlert.classList.add('d-none');
                validationAlert.textContent = '';
            }

            function setReviewValues() {
                reviewClubName.textContent = clubName;
                reviewClubCategory.textContent = clubCategory;
                reviewClubStatus.textContent = clubStatus;
                reviewClubAdviser.textContent = clubAdviser;
                reviewStudentName.textContent = studentName;
                reviewStudentId.textContent = studentId;
                reviewStudentEmail.textContent = studentEmail;
                reviewReason.textContent = document.getElementById('reason_for_joining').value.trim();
                reviewInterest.textContent = document.getElementById('areas_of_interest').value.trim();
                reviewParticipation.textContent = document.getElementById('preferred_participation').value;
                var agreementChecked = false;
                var agEl = document.getElementById('agreement');
                if (agEl) {
                    try {
                        if (agEl.type === 'checkbox') {
                            agreementChecked = !!agEl.checked;
                        } else {
                            var agv = (agEl.value || '').toString();
                            agreementChecked = agv === '1' || agv === 'true' || agv.toLowerCase() === 'on';
                        }
                    } catch (e) {
                        agreementChecked = (agEl.value === '1');
                    }
                }
                reviewAgreementCheckbox.checked = false;
                reviewAgreementCheckbox.indeterminate = false;
                reviewAgreementCheckbox.checked = agreementChecked;
            }

            function validateForm() {
                var reason = document.getElementById('reason_for_joining').value.trim();
                var interest = document.getElementById('areas_of_interest').value.trim();
                var participation = document.getElementById('preferred_participation').value;
                var errors = [];

                if (reason === '') {
                    errors.push('Please tell us why you want to join this club.');
                }
                if (interest === '') {
                    errors.push('Please describe your areas of interest.');
                }
                if (participation === '') {
                    errors.push('Please select your preferred participation type.');
                }

                return errors;
            }

            reviewButton.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopImmediatePropagation();
                clearErrors();
                var errors = validateForm();
                if (errors.length > 0) {
                    validationAlert.innerHTML = '<ul class="mb-0"><li>' + errors.join('</li><li>') + '</li></ul>';
                    validationAlert.classList.remove('d-none');
                    return;
                }

                setReviewValues();
                reviewModal.show();
            }, true);

            reviewBackButton.addEventListener('click', function () {
                clearErrors();
                document.getElementById('agreement').value = reviewAgreementCheckbox.checked ? '1' : '0';
                reviewModal.hide();
            });

            reviewModalEl.addEventListener('hidden.bs.modal', function () {
                clearErrors();
            });

            reviewSubmitButton.addEventListener('click', function () {
                clearErrors();
                if (!reviewAgreementCheckbox.checked) {
                    reviewError.textContent = 'Please agree to the club rules before submitting your application.';
                    reviewError.classList.remove('d-none');
                    return;
                }

                document.getElementById('agreement').value = reviewAgreementCheckbox.checked ? '1' : '0';
                reviewSubmitButton.disabled = true;
                reviewSubmitButton.textContent = 'Submitting...';
                ajaxIndicator.value = '1';

                var formData = new FormData(form);

                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data && data.ok) {
                        // store redirect target (if provided)
                        window._cocurricularRedirectAfterSuccess = (data.redirect && typeof data.redirect === 'string') ? data.redirect : null;

                        // If the review modal is open, hide it first and then show the success modal
                        try {
                            var reviewModalElLocal = reviewModalEl; // from outer scope
                            if (reviewModalElLocal && reviewModalElLocal.classList && reviewModalElLocal.classList.contains('show')) {
                                var onHidden = function () {
                                    reviewModalElLocal.removeEventListener('hidden.bs.modal', onHidden);
                                    window.showCocurricularSuccessModal();
                                };
                                reviewModalElLocal.addEventListener('hidden.bs.modal', onHidden);
                                if (typeof reviewModal !== 'undefined' && reviewModal) {
                                    // Move focus to the success modal OK button before hiding the review modal
                                    try {
                                        var okBtnPre = document.getElementById('cocurricularSuccessOkBtn');
                                        if (document.activeElement && reviewModalElLocal.contains(document.activeElement)) {
                                            if (okBtnPre && typeof okBtnPre.focus === 'function') {
                                                okBtnPre.focus({ preventScroll: true });
                                            } else {
                                                // fallback: blur active element
                                                try { document.activeElement.blur(); } catch (e) {}
                                            }
                                        }
                                    } catch (e) {
                                        // ignore focus errors
                                    }

                                    // Delay hiding slightly to ensure focus transfer completes in the browser
                                    setTimeout(function () {
                                        try { reviewModal.hide(); } catch (e) {}
                                    }, 60);
                                } else {
                                    // fallback: directly call show after a short delay
                                    setTimeout(window.showCocurricularSuccessModal, 250);
                                }
                            } else {
                                window.showCocurricularSuccessModal();
                            }
                        } catch (e) {
                            window.showCocurricularSuccessModal();
                        }

                        return;
                    }
                    if (data && data.errors) {
                        reviewError.innerHTML = '<ul class="mb-0"><li>' + data.errors.join('</li><li>') + '</li></ul>';
                        reviewError.classList.remove('d-none');
                    } else {
                        reviewError.textContent = 'Unable to submit your application at this time. Please try again.';
                        reviewError.classList.remove('d-none');
                    }
                })
                .catch(function () {
                    reviewError.textContent = 'Unable to submit your application at this time. Please try again.';
                    reviewError.classList.remove('d-none');
                })
                .finally(function () {
                    reviewSubmitButton.disabled = false;
                    reviewSubmitButton.textContent = 'Submit Application';
                    ajaxIndicator.value = '0';
                });
            });
        });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
