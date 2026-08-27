<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

requireAuth();
if (getCurrentUserRoleKey() !== 'osa' || !userCanAccessModule('cocurricular')) {
    http_response_code(403);
    exit('Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $participantId = (int) ($_POST['participant_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $result = match ($action) {
        'approve' => cocurricularReviewEventParticipant($participantId, (int) getCurrentUserId(), 'Approved'),
        'reject' => cocurricularReviewEventParticipant($participantId, (int) getCurrentUserId(), 'Rejected', (string) ($_POST['rejection_note'] ?? '')),
        default => ['status' => 'error'],
    };
    $messages = [
        'approved' => 'Participation approved.',
        'rejected' => 'Participation request rejected.',
        'invalid_reason' => 'A rejection reason is required.',
        'invalid_transition' => 'Only pending requests can be reviewed.',
        'error' => 'Unable to update the participation request.',
    ];
    $status = (string) ($result['status'] ?? 'error');
    echo json_encode(['success' => in_array($status, ['approved', 'rejected'], true), 'status' => $status, 'message' => $messages[$status] ?? $messages['error']], JSON_THROW_ON_ERROR);
    exit;
}

$events = cocurricularFetchEventsForManagement();
$eventId = (int) ($_GET['event_id'] ?? 0);
$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$selectedEvent = null;

if ($eventId > 0) {
    foreach ($events as $event) {
        if ((int) $event['id'] === $eventId) {
            $selectedEvent = $event;
            break;
        }
    }
}

if (!$selectedEvent && !empty($events)) {
    foreach ($events as $event) {
        if ((string) $event['event_date'] >= date('Y-m-d')) {
            $selectedEvent = $event;
            break;
        }
    }
    $selectedEvent = $selectedEvent ?: $events[0];
    $eventId = (int) $selectedEvent['id'];
}

$participants = $selectedEvent ? cocurricularFetchEventParticipants($eventId, $search, $statusFilter) : [];
$participantCounts = $selectedEvent ? cocurricularCountEventParticipantsByStatus($eventId) : ['Total' => 0, 'Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
if ($selectedEvent && $statusFilter === '' && $participantCounts['Pending'] > 0) {
    $statusFilter = 'Pending';
    $participants = cocurricularFetchEventParticipants($eventId, $search, $statusFilter);
}

$pageTitle = 'Event Participants';
$activeModule = 'cocurricular';
$activePage = 'student-affairs-event-participants';
$breadcrumbs = [
    ['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/index.php'],
    ['label' => 'Event Participants', 'url' => null],
];

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css?v=18" rel="stylesheet">

<div class="d-flex justify-content-between align-items-center gap-3 mb-3 flex-wrap">
    <div>
        <h1 class="h4 mb-1">Event Participants</h1>
        <p class="text-muted mb-0">Review student participation requests for Co-Curricular events.</p>
    </div>
</div>

<section class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-lg-7">
                <label class="form-label" for="participantEvent">Event</label>
                <select class="form-select" id="participantEvent" name="event_id" required>
                    <option value="">Select an event</option>
                    <?php foreach ($events as $event): ?>
                        <option value="<?= (int) $event['id'] ?>"<?= $eventId === (int) $event['id'] ? ' selected' : '' ?>><?= htmlspecialchars($event['title']) ?> — <?= htmlspecialchars(date('M j, Y', strtotime((string) $event['event_date']))) ?> (<?= htmlspecialchars($event['club_name']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label" for="participantSearch">Search student</label>
                <input class="form-control" id="participantSearch" name="search" type="search" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="Name or student ID">
            </div>
            <div class="col-lg-2">
                <label class="form-label" for="participantStatus">Status</label>
                <select class="form-select" id="participantStatus" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (['Pending', 'Approved', 'Rejected'] as $statusOption): ?><option value="<?= $statusOption ?>"<?= $statusFilter === $statusOption ? ' selected' : '' ?>><?= $statusOption ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <button class="btn btn-primary flex-grow-1" type="submit"><i class="fas fa-search me-1"></i>Search</button>
                <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/modules/cocurricular/pages/student-affairs-event-participants.php" aria-label="Reset filters" title="Reset filters"><i class="fas fa-undo"></i></a>
            </div>
        </form>
    </div>
</section>

<?php if (!$selectedEvent): ?>
    <div class="club-empty-state"><i class="fas fa-calendar-alt"></i><p>Select an event to view participants.</p></div>
<?php else: ?>
    <section class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                <div>
                    <span class="text-primary small text-uppercase fw-bold">Selected event</span>
                    <h2 class="h4 mt-1 mb-2"><?= htmlspecialchars($selectedEvent['title']) ?></h2>
                    <div class="text-muted small">
                        <span><?= htmlspecialchars($selectedEvent['club_name']) ?></span>
                        <span class="mx-1">&middot;</span>
                        <span><?= htmlspecialchars(date('M j, Y', strtotime((string) $selectedEvent['event_date']))) ?></span>
                        <span class="mx-1">&middot;</span>
                        <span><?= htmlspecialchars(date('h:i A', strtotime((string) $selectedEvent['start_time']))) ?> - <?= htmlspecialchars(date('h:i A', strtotime((string) $selectedEvent['end_time']))) ?></span>
                        <?php if (!empty($selectedEvent['venue'])): ?><span class="mx-1">&middot;</span><span><?= htmlspecialchars($selectedEvent['venue']) ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="text-lg-end">
                    <div class="display-6 fw-bold text-primary"><?= $participantCounts['Total'] ?></div>
                    <div class="text-muted small">Total Requests</div>
                    <div class="small mt-2"><span class="text-warning">Pending: <?= $participantCounts['Pending'] ?></span> <span class="text-success ms-2">Approved: <?= $participantCounts['Approved'] ?></span> <span class="text-danger ms-2">Rejected: <?= $participantCounts['Rejected'] ?></span></div>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2 class="h5 mb-0">Registered Students</h2></div>
        <div class="card-body">
            <?php if (empty($participants)): ?>
                <div class="alert alert-info mb-0"><?= $search !== '' ? 'No participants match your search.' : 'No students have expressed interest in this event yet.' ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr><th>Student ID</th><th>Student Name</th><th>Requested</th><th>Status</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($participants as $participant): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($participant['student_id'] ?? 'Not set')) ?></td>
                                    <td><?= htmlspecialchars($participant['full_name']) ?></td>
                                    <td><?= htmlspecialchars(date('M j, Y h:i A', strtotime((string) $participant['registered_at']))) ?></td>
                                    <td><span class="badge rounded-pill status-<?= strtolower((string) $participant['status']) ?>"><?= htmlspecialchars($participant['status']) ?></span></td>
                                    <td><?php if ($participant['status'] === 'Pending'): ?><button type="button" class="btn btn-sm btn-outline-primary" data-review-participant='<?= htmlspecialchars(json_encode($participant, JSON_THROW_ON_ERROR), ENT_QUOTES) ?>'>Review</button><?php else: ?><button type="button" class="btn btn-sm btn-outline-secondary" data-view-participant='<?= htmlspecialchars(json_encode($participant, JSON_THROW_ON_ERROR), ENT_QUOTES) ?>'>View</button><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<div class="modal fade cocurricular-runtime-modal" id="participantReviewModal" tabindex="-1" aria-labelledby="participantReviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5 mb-0" id="participantReviewModalLabel">Participation Request</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body" id="participantReviewBody"></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-danger" id="participantRejectButton">Reject</button><button type="button" class="btn btn-success" id="participantApproveButton">Approve</button></div>
        </div>
    </div>
</div>

<div class="modal fade cocurricular-runtime-modal" id="participantRejectionModal" tabindex="-1" aria-labelledby="participantRejectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="participantRejectionForm">
                <div class="modal-header"><h2 class="modal-title h5 mb-0" id="participantRejectionModalLabel">Reject Participation</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body"><p id="participantRejectionSummary" class="small text-muted"></p><label class="form-label" for="participantRejectionNote">Reason for rejection</label><textarea class="form-control" id="participantRejectionNote" rows="4" required></textarea><div class="invalid-feedback">Please provide a reason for rejection.</div></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Reject Request</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade cocurricular-runtime-modal" id="participantApproveModal" tabindex="-1" aria-labelledby="participantApproveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5 mb-0" id="participantApproveModalLabel">Approve Participation?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><p class="mb-0" id="participantApproveSummary"></p></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-success" id="participantApproveConfirmButton">Approve</button></div>
        </div>
    </div>
</div>

<div class="modal fade cocurricular-interest-feedback-modal" id="participantFeedbackModal" tabindex="-1" aria-labelledby="participantFeedbackTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-body text-center"><div class="cocurricular-interest-feedback-icon" id="participantFeedbackIcon"><i class="fas fa-check"></i></div><h2 class="modal-title h5" id="participantFeedbackTitle"></h2><p class="mb-0" id="participantFeedbackMessage"></p></div></div></div>
</div>

<script>
(function () {
    const reviewModal = document.getElementById('participantReviewModal');
    const rejectionModal = document.getElementById('participantRejectionModal');
    const approveModal = document.getElementById('participantApproveModal');
    const reviewBody = document.getElementById('participantReviewBody');
    const rejectionForm = document.getElementById('participantRejectionForm');
    const rejectionNote = document.getElementById('participantRejectionNote');
    const rejectionSummary = document.getElementById('participantRejectionSummary');
    const approveSummary = document.getElementById('participantApproveSummary');
    const feedbackModal = document.getElementById('participantFeedbackModal');
    let selectedParticipant = null;

    function escapeHtml(value) { return String(value || '').replace(/[&<>"']/g, function (character) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[character]; }); }
    function participantSummary(participant) {
        const reviewed = participant.reviewed_at ? '<dt class="col-4">Reviewed</dt><dd class="col-8">' + escapeHtml(participant.reviewed_at) + '</dd>' : '';
        const reason = participant.rejection_note ? '<dt class="col-4">Reason</dt><dd class="col-8">' + escapeHtml(participant.rejection_note) + '</dd>' : '';
        return '<dl class="row small mb-0"><dt class="col-4">Student</dt><dd class="col-8">' + escapeHtml(participant.full_name) + '</dd><dt class="col-4">Student ID</dt><dd class="col-8">' + escapeHtml(participant.student_id || 'Not set') + '</dd><dt class="col-4">Event</dt><dd class="col-8">' + escapeHtml(participant.event_title) + '</dd><dt class="col-4">Club</dt><dd class="col-8">' + escapeHtml(participant.club_name) + '</dd><dt class="col-4">Requested</dt><dd class="col-8">' + escapeHtml(participant.registered_at) + '</dd><dt class="col-4">Status</dt><dd class="col-8">' + escapeHtml(participant.status) + '</dd>' + reviewed + reason + '</dl>';
    }
    function showFeedback(title, message, isError) {
        document.getElementById('participantFeedbackTitle').textContent = title;
        document.getElementById('participantFeedbackMessage').textContent = message;
        const icon = document.getElementById('participantFeedbackIcon');
        icon.classList.toggle('is-error', isError);
        icon.innerHTML = '<i class="fas fa-' + (isError ? 'exclamation' : 'check') + '"></i>';
        bootstrap.Modal.getOrCreateInstance(feedbackModal).show();
        window.setTimeout(function () { bootstrap.Modal.getOrCreateInstance(feedbackModal).hide(); }, 2200);
    }
    function review(action, note) {
        const body = new URLSearchParams({ action: action, participant_id: String(selectedParticipant.id) });
        if (note) body.set('rejection_note', note);
        fetch(window.location.href, { method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'}, body: body })
            .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || 'Unable to update request.'); return data; }); })
            .then(function (data) {
                bootstrap.Modal.getOrCreateInstance(reviewModal).hide();
                bootstrap.Modal.getOrCreateInstance(approveModal).hide();
                bootstrap.Modal.getOrCreateInstance(rejectionModal).hide();
                if (data.success) { showFeedback(action === 'approve' ? 'Participation Approved' : 'Participation Rejected', data.message, false); window.setTimeout(function () { window.location.reload(); }, 500); }
                else { showFeedback('Unable to update request', data.message, true); }
            })
            .catch(function (error) { showFeedback('Unable to update request', error.message, true); });
    }
    document.querySelectorAll('[data-review-participant], [data-view-participant]').forEach(function (button) {
        button.addEventListener('click', function () {
            selectedParticipant = JSON.parse(button.getAttribute(button.hasAttribute('data-review-participant') ? 'data-review-participant' : 'data-view-participant'));
            reviewBody.innerHTML = participantSummary(selectedParticipant);
            document.getElementById('participantApproveButton').classList.toggle('d-none', button.hasAttribute('data-view-participant'));
            document.getElementById('participantRejectButton').classList.toggle('d-none', button.hasAttribute('data-view-participant'));
            bootstrap.Modal.getOrCreateInstance(reviewModal).show();
        });
    });
    document.getElementById('participantApproveButton').addEventListener('click', function () {
        if (!selectedParticipant) return;
        approveSummary.textContent = selectedParticipant.full_name + ' will be approved to participate in ' + selectedParticipant.event_title + '.';
        bootstrap.Modal.getOrCreateInstance(reviewModal).hide();
        bootstrap.Modal.getOrCreateInstance(approveModal).show();
    });
    document.getElementById('participantApproveConfirmButton').addEventListener('click', function () { if (selectedParticipant) review('approve', ''); });
    document.getElementById('participantRejectButton').addEventListener('click', function () {
        rejectionSummary.textContent = selectedParticipant ? selectedParticipant.full_name + ' - ' + selectedParticipant.event_title : '';
        rejectionNote.value = '';
        bootstrap.Modal.getOrCreateInstance(reviewModal).hide();
        bootstrap.Modal.getOrCreateInstance(rejectionModal).show();
    });
    rejectionForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!rejectionNote.value.trim()) { rejectionNote.classList.add('is-invalid'); return; }
        rejectionNote.classList.remove('is-invalid');
        review('reject', rejectionNote.value.trim());
    });
})();
</script>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
