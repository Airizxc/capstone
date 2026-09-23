<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../../../includes/uploads.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

requireAuth();
if (getCurrentUserRoleKey() !== 'osa' || !userCanAccessModule('cocurricular')) {
    http_response_code(403);
    exit('Unauthorized');
}

function cocurricularRemoveStoredEventImage(string $path): void
{
    $normalized = ltrim(str_replace('\\', '/', $path), '/');
    if (!str_starts_with($normalized, 'cocurricular_events/')) {
        return;
    }
    $uploadsRoot = realpath(smsUploadRoot());
    $filePath = realpath(smsUploadRoot() . '/' . $normalized);
    if ($uploadsRoot && $filePath && is_file($filePath)
        && strncmp($filePath, $uploadsRoot . DIRECTORY_SEPARATOR, strlen($uploadsRoot . DIRECTORY_SEPARATOR)) === 0) {
        @unlink($filePath);
    }
}

$notice = '';
$errors = [];
$currentUserId = getCurrentUserId();
$search = trim((string) ($_GET['search'] ?? ''));
$filterClubId = (int) ($_GET['club_id'] ?? 0);
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$clubs = cocurricularFetchClubs();
$summary = cocurricularGetEventSummaryCounts();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $eventId = (int) ($_POST['event_id'] ?? 0);
    $clubId = (int) ($_POST['club_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $eventType = trim((string) ($_POST['event_type'] ?? ''));
    $eventDate = trim((string) ($_POST['event_date'] ?? ''));
    $startTime = trim((string) ($_POST['start_time'] ?? ''));
    $endTime = trim((string) ($_POST['end_time'] ?? ''));
    $venue = trim((string) ($_POST['venue'] ?? ''));
    $status = in_array($_POST['status'] ?? 'Draft', ['Draft', 'Published', 'Completed', 'Cancelled'], true)
        ? (string) $_POST['status']
        : 'Draft';
    $isPinned = !empty($_POST['is_pinned']);
    $imagePath = '';
    $existingEvent = $eventId > 0 ? cocurricularGetEventById($eventId) : null;
    $existingImagePath = trim((string) ($existingEvent['image_path'] ?? ''));
    $removeImage = !empty($_POST['remove_image']);

    if ($action === 'create' || $action === 'update') {
        if ($clubId <= 0) {
            $errors[] = 'Please select a club.';
        }
        if ($title === '') {
            $errors[] = 'Please enter an event title.';
        }
        if ($eventType === '') {
            $errors[] = 'Please select an event type.';
        }
        if ($eventDate === '') {
            $errors[] = 'Please provide the event date.';
        }
        if ($startTime === '') {
            $errors[] = 'Please provide the start time.';
        }
        if ($endTime === '') {
            $errors[] = 'Please provide the end time.';
        }
        if ($startTime !== '' && $endTime !== '' && $endTime < $startTime) {
            $errors[] = 'End time must not be earlier than the start time.';
        }
    }

    if (isset($_FILES['event_image']) && !empty($_FILES['event_image']['name'])) {
        $upload = smsSecureUpload($_FILES['event_image'], [
            'subdir' => 'cocurricular_events',
            'max_bytes' => 5 * 1024 * 1024,
            'allowed' => [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'webp' => ['image/webp'],
            ],
        ]);
        if (!$upload['ok']) {
            $errors[] = 'Event image: ' . $upload['error'];
        } else {
            $imagePath = 'cocurricular_events/' . $upload['stored_name'];
        }
    } elseif ($removeImage && $eventId > 0 && $existingImagePath !== '') {
        $imagePath = '';
    } elseif ($action === 'update' && $eventId > 0) {
        $imagePath = $existingImagePath;
    }

    if (empty($errors)) {
        if ($action === 'create') {
            $saved = cocurricularCreateClubEvent([
                'club_id' => $clubId,
                'title' => $title,
                'description' => $description,
                'event_type' => $eventType,
                'event_date' => $eventDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'venue' => $venue,
                'image_path' => $imagePath,
                'status' => $status,
                'is_pinned' => $isPinned,
                'created_by' => $currentUserId,
            ]);
            if ($saved) {
                $notice = 'Event created successfully.';
            } else {
                $errors[] = 'Unable to create the event.';
            }
        }

        if ($action === 'update') {
            if ($eventId <= 0) {
                $errors[] = 'Event not found.';
            } else {
                $saved = cocurricularUpdateClubEvent($eventId, [
                    'club_id' => $clubId,
                    'title' => $title,
                    'description' => $description,
                    'event_type' => $eventType,
                    'event_date' => $eventDate,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'venue' => $venue,
                    'image_path' => $imagePath,
                    'status' => $status,
                    'is_pinned' => $isPinned,
                ]);
                if ($saved) {
                    if ($existingImagePath !== '' && $existingImagePath !== $imagePath) {
                        cocurricularRemoveStoredEventImage($existingImagePath);
                    }
                    $notice = 'Event updated successfully.';
                } else {
                    $errors[] = 'Unable to update the event.';
                }
            }
        }
    }

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['event_id'] ?? 0);
        $eventToDelete = $deleteId > 0 ? cocurricularGetEventById($deleteId) : null;
        if ($deleteId <= 0) {
            $errors[] = 'Unable to locate the event.';
        } else {
            $deleted = cocurricularDeleteClubEvent($deleteId);
            if ($deleted) {
                $eventImage = trim((string) ($eventToDelete['image_path'] ?? ''));
                if ($eventImage !== '') {
                    cocurricularRemoveStoredEventImage($eventImage);
                }
                $notice = 'Event deleted successfully.';
            } else {
                $errors[] = 'Unable to delete the event.';
            }
        }
    }

    if ($action === 'status') {
        $statusEventId = (int) ($_POST['event_id'] ?? 0);
        $requestedStatus = in_array((string) ($_POST['new_status'] ?? ''), ['Draft', 'Published', 'Completed', 'Cancelled'], true)
            ? (string) $_POST['new_status']
            : '';
        if ($statusEventId <= 0 || $requestedStatus === '') {
            $errors[] = 'Invalid event status update.';
        } else {
            $updated = cocurricularUpdateEventStatus($statusEventId, $requestedStatus);
            if ($updated) {
                $notice = 'Event status updated successfully.';
            } else {
                $errors[] = 'Unable to update the event status.';
            }
        }
    }
}

$pageTitle = 'Events & Activities';
$activeModule = 'cocurricular';
$activePage = 'student-affairs-events';
$breadcrumbs = [
    ['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/index.php'],
    ['label' => 'Events & Activities', 'url' => null],
];

$events = cocurricularFetchEventsForManagement($search, $filterClubId, $filterStatus);

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css?v=17" rel="stylesheet">

<div class="d-flex justify-content-between align-items-center gap-3 mb-3 flex-wrap">
    <div>
        <h1 class="h4 mb-1">Events & Activities</h1>
        <p class="text-muted mb-0">Manage official club events and activities for Student Affairs.</p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#eventModal" data-mode="create">
        <i class="fas fa-plus me-1"></i>Create Event
    </button>
</div>

<?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($notice) ?></div>
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

<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Total Events</div>
                <div class="display-6 fw-bold mt-2"><?= (int) ($summary['total'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Upcoming</div>
                <div class="display-6 fw-bold mt-2"><?= (int) ($summary['upcoming'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Published</div>
                <div class="display-6 fw-bold mt-2"><?= (int) ($summary['published'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Draft</div>
                <div class="display-6 fw-bold mt-2"><?= (int) ($summary['draft'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Completed</div>
                <div class="display-6 fw-bold mt-2"><?= (int) ($summary['completed'] ?? 0) ?></div>
            </div>
        </div>
    </div>
</div>

<section class="card">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label" for="eventSearch">Search</label>
                <input id="eventSearch" name="search" type="search" class="form-control" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="Event title, type, venue, club">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filterClubId">Club</label>
                <select id="filterClubId" name="club_id" class="form-select">
                    <option value="">All clubs</option>
                    <?php foreach ($clubs as $club): ?>
                        <option value="<?= (int) $club['id'] ?>"<?= $filterClubId === (int) $club['id'] ? ' selected' : '' ?>><?= htmlspecialchars($club['club_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="filterStatus">Status</label>
                <select id="filterStatus" name="status" class="form-select">
                    <option value="">All statuses</option>
                    <?php foreach (['Draft', 'Published', 'Completed', 'Cancelled'] as $statusOption): ?>
                        <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES) ?>"<?= $filterStatus === $statusOption ? ' selected' : '' ?>><?= htmlspecialchars($statusOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary w-100">Filter</button>
                <a href="<?= BASE_URL ?>/modules/cocurricular/pages/student-affairs-events.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</section>

<section class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0">Event List</h2>
    </div>
    <div class="card-body">
        <?php if (empty($events)): ?>
            <div class="alert alert-info mb-0">No events match the current filters.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Club</th>
                            <th>Event Type</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Venue</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($event['image_path'])): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <img src="<?= htmlspecialchars(BASE_URL . '/modules/cocurricular/pages/event-image.php?club_id=' . (int) $event['club_id'] . '&event_id=' . (int) $event['id'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars($event['title']) ?>" style="width:44px;height:44px;object-fit:cover;border-radius:8px;">
                                            <div>
                                                <div class="fw-semibold"><?= htmlspecialchars($event['title']) ?></div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="fw-semibold"><?= htmlspecialchars($event['title']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($event['club_name']) ?></td>
                                <td><?= htmlspecialchars($event['event_type']) ?></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime((string) $event['event_date']))) ?></td>
                                <td><?= htmlspecialchars(date('h:i A', strtotime((string) $event['start_time']))) ?> - <?= htmlspecialchars(date('h:i A', strtotime((string) $event['end_time']))) ?></td>
                                <td><?= htmlspecialchars($event['venue'] ?: '—') ?></td>
                                <td>
                                    <span class="badge rounded-pill <?php
                                        $statusClass = 'bg-secondary';
                                        if ($event['status'] === 'Published') { $statusClass = 'bg-success'; }
                                        elseif ($event['status'] === 'Draft') { $statusClass = 'bg-warning text-dark'; }
                                        elseif ($event['status'] === 'Completed') { $statusClass = 'bg-info text-dark'; }
                                        elseif ($event['status'] === 'Cancelled') { $statusClass = 'bg-danger'; }
                                        echo $statusClass;
                                    ?>"><?= htmlspecialchars($event['status']) ?></span>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a href="<?= BASE_URL ?>/modules/cocurricular/pages/event-image.php?club_id=<?= (int) $event['club_id'] ?>&event_id=<?= (int) $event['id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">View</a>
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#eventModal" data-mode="edit"
                                                data-id="<?= (int) $event['id'] ?>"
                                                data-club-id="<?= (int) $event['club_id'] ?>"
                                                data-title="<?= htmlspecialchars($event['title'], ENT_QUOTES) ?>"
                                                data-description="<?= htmlspecialchars($event['description'], ENT_QUOTES) ?>"
                                                data-event-type="<?= htmlspecialchars($event['event_type'], ENT_QUOTES) ?>"
                                                data-event-date="<?= htmlspecialchars(date('Y-m-d', strtotime((string) $event['event_date'])), ENT_QUOTES) ?>"
                                                data-start-time="<?= htmlspecialchars(date('H:i', strtotime((string) $event['start_time'])), ENT_QUOTES) ?>"
                                                data-end-time="<?= htmlspecialchars(date('H:i', strtotime((string) $event['end_time'])), ENT_QUOTES) ?>"
                                                data-venue="<?= htmlspecialchars((string) $event['venue'], ENT_QUOTES) ?>"
                                                data-status="<?= htmlspecialchars($event['status'], ENT_QUOTES) ?>"
                                                data-is-pinned="<?= !empty($event['is_pinned']) ? '1' : '0' ?>"
                                                data-image-url="<?= htmlspecialchars(!empty($event['image_path']) ? BASE_URL . '/modules/cocurricular/pages/event-image.php?club_id=' . (int) $event['club_id'] . '&event_id=' . (int) $event['id'] : '', ENT_QUOTES) ?>">
                                            Edit
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-info generate-event-ai-btn" data-event-id="<?= (int) $event['id'] ?>" title="Generate Announcement Draft with AI">
                                            <i class="fas fa-magic me-1"></i>Generate Announcement
                                        </button>
                                        <?php if ($event['status'] !== 'Published'): ?>
                                            <form method="post" action="<?= BASE_URL ?>/modules/cocurricular/pages/student-affairs-events.php" class="d-inline" onsubmit="return confirm('Publish this event?');">
                                                <input type="hidden" name="action" value="status">
                                                <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                                <input type="hidden" name="new_status" value="Published">
                                                <button type="submit" class="btn btn-sm btn-success">Publish</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($event['status'] !== 'Completed'): ?>
                                            <form method="post" action="<?= BASE_URL ?>/modules/cocurricular/pages/student-affairs-events.php" class="d-inline" onsubmit="return confirm('Mark this event as completed?');">
                                                <input type="hidden" name="action" value="status">
                                                <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                                <input type="hidden" name="new_status" value="Completed">
                                                <button type="submit" class="btn btn-sm btn-info text-dark">Complete</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($event['status'] !== 'Cancelled'): ?>
                                            <form method="post" action="<?= BASE_URL ?>/modules/cocurricular/pages/student-affairs-events.php" class="d-inline" onsubmit="return confirm('Cancel this event?');">
                                                <input type="hidden" name="action" value="status">
                                                <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                                <input type="hidden" name="new_status" value="Cancelled">
                                                <button type="submit" class="btn btn-sm btn-warning text-dark">Cancel</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" action="<?= BASE_URL ?>/modules/cocurricular/pages/student-affairs-events.php" class="d-inline" onsubmit="return confirm('Delete this event?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="modal fade" id="eventAiDraftModal" tabindex="-1" aria-labelledby="eventAiDraftModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center gap-2" id="eventAiDraftModalLabel">
                    <i class="fas fa-robot text-primary"></i> AI Announcement Draft
                </h5>
                <span class="badge bg-primary text-white">GPT-4.1</span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                    <i class="fas fa-info-circle"></i>
                    <div>This is an <strong>AI-generated announcement draft</strong> based on your event details. Review and edit before publishing.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Draft Title</label>
                    <input type="text" id="eventAiDraftTitle" class="form-control" readonly>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Draft Content</label>
                    <textarea id="eventAiDraftContent" class="form-control" rows="8" readonly></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" id="eventAiOpenDraftBtn" class="btn btn-primary">
                    <i class="fas fa-edit me-1"></i>Review & Save in Announcement Manager
                </a>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="eventModal" tabindex="-1" aria-labelledby="eventModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" action="<?= BASE_URL ?>/modules/cocurricular/pages/student-affairs-events.php">
                <div class="modal-header">
                    <h2 class="modal-title h5 mb-0" id="eventModalLabel">Event</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="eventAction" value="create">
                    <input type="hidden" name="event_id" id="eventId" value="">

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="eventTitle">Event Title</label>
                            <input id="eventTitle" name="title" type="text" class="form-control" maxlength="191" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="eventClubId">Club</label>
                            <select id="eventClubId" name="club_id" class="form-select" required>
                                <option value="">Select club</option>
                                <?php foreach ($clubs as $club): ?>
                                    <option value="<?= (int) $club['id'] ?>"><?= htmlspecialchars($club['club_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mt-0">
                        <div class="col-md-4">
                            <label class="form-label" for="eventType">Event Type</label>
                            <input id="eventType" name="event_type" type="text" class="form-control" maxlength="120" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="eventDate">Event Date</label>
                            <input id="eventDate" name="event_date" type="date" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="eventStatus">Status</label>
                            <select id="eventStatus" name="status" class="form-select">
                                <?php foreach (['Draft', 'Published', 'Completed', 'Cancelled'] as $statusOption): ?>
                                    <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES) ?>"><?= htmlspecialchars($statusOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="eventDescription">Description</label>
                        <textarea id="eventDescription" name="description" rows="5" class="form-control"></textarea>
                    </div>

                    <div class="row g-3 mt-0">
                        <div class="col-md-6">
                            <label class="form-label" for="eventStartTime">Start Time</label>
                            <input id="eventStartTime" name="start_time" type="time" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="eventEndTime">End Time</label>
                            <input id="eventEndTime" name="end_time" type="time" class="form-control" required>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="eventVenue">Venue</label>
                        <input id="eventVenue" name="venue" type="text" class="form-control" maxlength="191" placeholder="Optional venue">
                    </div>

                    <div class="form-check mt-3">
                        <input id="eventPinned" name="is_pinned" type="checkbox" class="form-check-input" value="1">
                        <label class="form-check-label" for="eventPinned">Pin this event to Hero</label>
                        <div class="form-text">Only published, upcoming events appear in the Hero carousel.</div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="eventImage">Event Image / Poster</label>
                        <input id="eventImage" name="event_image" type="file" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                        <div id="eventImagePreview" class="announcement-image-preview mt-2 d-none">
                            <img src="" alt="Event image preview">
                        </div>
                        <div class="form-check mt-2 d-none" id="eventRemoveImageWrap">
                            <input id="eventRemoveImage" name="remove_image" type="checkbox" class="form-check-input" value="1">
                            <label class="form-check-label" for="eventRemoveImage">Remove current image</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="eventSubmitBtn">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const modalEl = document.getElementById('eventModal');
    const eventTitle = document.getElementById('eventTitle');
    const eventClubId = document.getElementById('eventClubId');
    const eventType = document.getElementById('eventType');
    const eventDescription = document.getElementById('eventDescription');
    const eventDate = document.getElementById('eventDate');
    const eventStartTime = document.getElementById('eventStartTime');
    const eventEndTime = document.getElementById('eventEndTime');
    const eventVenue = document.getElementById('eventVenue');
    const eventStatus = document.getElementById('eventStatus');
    const eventPinned = document.getElementById('eventPinned');
    const eventAction = document.getElementById('eventAction');
    const eventId = document.getElementById('eventId');
    const submitBtn = document.getElementById('eventSubmitBtn');
    const preview = document.getElementById('eventImagePreview');
    const previewImg = preview.querySelector('img');
    const removeWrap = document.getElementById('eventRemoveImageWrap');

    modalEl.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        const mode = trigger?.dataset.mode ?? 'create';
        const form = modalEl.querySelector('form');
        if (mode === 'edit') {
            eventTitle.value = trigger.dataset.title || '';
            eventClubId.value = trigger.dataset.clubId || '';
            eventType.value = trigger.dataset.eventType || '';
            eventDescription.value = trigger.dataset.description || '';
            eventDate.value = trigger.dataset.eventDate || '';
            eventStartTime.value = trigger.dataset.startTime || '';
            eventEndTime.value = trigger.dataset.endTime || '';
            eventVenue.value = trigger.dataset.venue || '';
            eventStatus.value = trigger.dataset.status || 'Draft';
            eventPinned.checked = trigger.dataset.isPinned === '1';
            eventAction.value = 'update';
            eventId.value = trigger.dataset.id || '';
            submitBtn.textContent = 'Update';
            removeWrap.classList.remove('d-none');
            if (trigger.dataset.imageUrl) {
                previewImg.src = trigger.dataset.imageUrl;
                preview.classList.remove('d-none');
            } else {
                previewImg.src = '';
                preview.classList.add('d-none');
            }
            form.querySelector('#eventImage').value = '';
        } else {
            form.reset();
            eventAction.value = 'create';
            eventId.value = '';
            eventPinned.checked = false;
            submitBtn.textContent = 'Save';
            previewImg.src = '';
            preview.classList.add('d-none');
            removeWrap.classList.add('d-none');
        }
    });
})();
</script>
<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
