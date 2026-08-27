<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

requireAuth();
if (getCurrentUserRoleKey() !== 'osa' || !userCanAccessModule('cocurricular')) {
    http_response_code(403);
    exit('Unauthorized');
}

$pageTitle = 'Membership Applications';
$activeModule = 'cocurricular';
$activePage = 'membership-applications';
$breadcrumbs = [
    ['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/index.php'],
    ['label' => 'Membership Applications', 'url' => null],
];
$applications = cocurricularGetMembershipApplications();
$mainDb = db();
foreach ($applications as &$application) {
    $application['student_name'] = 'Unknown';
    $application['student_email'] = '';
    if ($mainDb) {
        $statement = $mainDb->prepare('SELECT full_name, email FROM users WHERE id = ? LIMIT 1');
        $statement->execute([(int) $application['user_id']]);
        $student = $statement->fetch();
        if ($student) {
            $application['student_name'] = $student['full_name'] ?? 'Unknown';
            $application['student_email'] = $student['email'] ?? '';
        }
    }
}
unset($application);
require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css?v=2" rel="stylesheet">
<section class="card">
    <div class="card-header"><h1 class="h5 mb-0">Membership Applications</h1></div>
    <div class="card-body">
        <?php if (!$applications): ?>
            <div class="alert alert-info mb-0">No membership applications yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Student</th><th>Student ID</th><th>Club</th><th>Submitted</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($applications as $application): ?>
                        <?php $badge = $application['status'] === 'Approved' ? 'success' : ($application['status'] === 'Rejected' ? 'danger' : 'warning'); ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($application['student_name']) ?></strong><br><small><?= htmlspecialchars($application['student_email']) ?></small></td>
                            <td><code><?= htmlspecialchars($application['student_id']) ?></code></td>
                            <td><?= htmlspecialchars($application['club_name']) ?></td>
                            <td><?= htmlspecialchars(date('M j, Y', strtotime($application['submitted_at']))) ?></td>
                            <td><span class="badge rounded-pill bg-<?= $badge ?>"><?= htmlspecialchars($application['status']) ?></span></td>
                            <td class="application-actions">
                                <button type="button" class="btn btn-sm btn-outline-primary application-action-btn js-view-application"
                                        data-application-id="<?= (int) $application['id'] ?>"
                                        data-bs-toggle="modal" data-bs-target="#applicationDetailsModal"
                                        title="View application" aria-label="View application">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                                <?php if ($application['status'] === 'Pending'): ?>
                                    <form method="post" action="<?= BASE_URL ?>/modules/cocurricular/pages/osa-process-application.php" class="d-inline-flex gap-1">
                                        <input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary application-action-btn" name="action" value="approve"
                                                title="Approve application" aria-label="Approve application">
                                            <i class="fas fa-check-circle" aria-hidden="true"></i>
                                        </button>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary application-action-btn" name="action" value="reject"
                                                title="Reject application" aria-label="Reject application">
                                            <i class="fas fa-times-circle" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<div class="modal fade application-details-modal" id="applicationDetailsModal" tabindex="-1" aria-labelledby="applicationDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5 mb-0" id="applicationDetailsModalLabel">Application Details</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="applicationDetailsModalBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status" aria-label="Loading application details"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalBody = document.getElementById('applicationDetailsModalBody');
    document.querySelectorAll('.js-view-application').forEach(function (button) {
        button.addEventListener('click', function () {
            var applicationId = button.getAttribute('data-application-id');
            modalBody.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status" aria-label="Loading application details"></div></div>';

            fetch('<?= BASE_URL ?>/modules/cocurricular/pages/osa-application-details.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: 'application_id=' + encodeURIComponent(applicationId)
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok || !data.success) {
                            throw new Error(data.message || 'Unable to load application details.');
                        }
                        return data.application;
                    });
                })
                .then(function (application) {
                    var reviewed = application.reviewed_at ? application.reviewed_at : 'Not reviewed';
                    var reviewer = application.reviewer ? '<div class="mb-2"><strong>Reviewer:</strong> ' + escapeHtml(application.reviewer) + '</div>' : '';
                    modalBody.innerHTML =
                        '<div class="row g-3">' +
                        '<div class="col-md-6"><h3 class="h6 fw-bold">Student Information</h3>' +
                        '<div class="mb-2"><strong>Student Name:</strong> ' + escapeHtml(application.student_name) + '</div>' +
                        '<div class="mb-2"><strong>Student ID:</strong> ' + escapeHtml(application.student_id) + '</div>' +
                        '<div class="mb-2"><strong>Email:</strong> ' + escapeHtml(application.student_email) + '</div></div>' +
                        '<div class="col-md-6"><h3 class="h6 fw-bold">Club Information</h3>' +
                        '<div class="mb-2"><strong>Club:</strong> ' + escapeHtml(application.club_name) + '</div>' +
                        '<div class="mb-2"><strong>Category:</strong> ' + escapeHtml(application.category || 'N/A') + '</div>' +
                        '<div class="mb-2"><strong>Adviser:</strong> ' + escapeHtml(application.adviser || 'N/A') + '</div>' +
                        '<div class="mb-2"><strong>Adviser Email:</strong> ' + escapeHtml(application.adviser_email || 'N/A') + '</div></div>' +
                        '<div class="col-12"><hr><h3 class="h6 fw-bold">Application Information</h3>' +
                        '<div class="mb-2"><strong>Submitted Date:</strong> ' + escapeHtml(application.submitted_at) + '</div>' +
                        '<div class="mb-2"><strong>Reason for Joining:</strong><br>' + escapeHtml(application.reason_for_joining) + '</div>' +
                        '<div class="mb-2"><strong>Areas of Interest:</strong><br>' + escapeHtml(application.areas_of_interest) + '</div>' +
                        '<div class="mb-2"><strong>Preferred Participation:</strong> ' + escapeHtml(application.preferred_participation) + '</div>' +
                        '<div class="mb-2"><strong>Agreement:</strong> ' + (application.agreement ? 'Agreed' : 'Not agreed') + '</div></div>' +
                        '<div class="col-12"><hr><h3 class="h6 fw-bold">Review Information</h3>' +
                        '<div class="mb-2"><strong>Current Status:</strong> <span class="badge rounded-pill bg-' + statusClass(application.status) + '">' + escapeHtml(application.status) + '</span></div>' +
                        '<div class="mb-2"><strong>Reviewed Date:</strong> ' + escapeHtml(reviewed) + '</div>' + reviewer + '</div></div>';
                })
                .catch(function (error) {
                    modalBody.innerHTML = '<div class="alert alert-danger mb-0">' + escapeHtml(error.message) + '</div>';
                });
        });
    });

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>'"]/g, function (character) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'}[character];
        });
    }

    function statusClass(status) {
        return status === 'Approved' ? 'success' : (status === 'Rejected' ? 'danger' : 'warning');
    }
});
</script>
<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
