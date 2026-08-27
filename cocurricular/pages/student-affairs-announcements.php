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

$notice = '';
$errors = [];
$newAttachmentPath = '';

function cocurricularRemoveStoredAnnouncementImage(string $path): void
{
    $normalized = ltrim(str_replace('\\', '/', $path), '/');
    if (!str_starts_with($normalized, 'cocurricular_announcements/')) {
        return;
    }
    $uploadsRoot = realpath(smsUploadRoot());
    $filePath = realpath(smsUploadRoot() . '/' . $normalized);
    if ($uploadsRoot && $filePath && is_file($filePath)
        && strncmp($filePath, $uploadsRoot . DIRECTORY_SEPARATOR, strlen($uploadsRoot . DIRECTORY_SEPARATOR)) === 0) {
        @unlink($filePath);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $clubId = (int) ($_POST['club_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));
    $postedAt = trim((string) ($_POST['posted_at'] ?? ''));
    $isPinned = !empty($_POST['is_pinned']);
    $removeImage = !empty($_POST['remove_image']);
    $announcementId = (int) ($_POST['announcement_id'] ?? 0);
    $existingAnnouncement = $action === 'update' && $announcementId > 0 ? cocurricularGetAnnouncementById($announcementId) : null;
    $existingAttachmentPath = trim((string) ($existingAnnouncement['attachment_path'] ?? ''));
    $attachmentPath = $removeImage ? '' : $existingAttachmentPath;

    if ($action === 'create' || $action === 'update') {
        if ($clubId <= 0) {
            $errors[] = 'Please select a club.';
        }
        if ($title === '') {
            $errors[] = 'Please enter an announcement title.';
        }
        if ($content === '') {
            $errors[] = 'Please enter announcement content.';
        }
    }

    if (empty($errors)) {
        $imageUpload = smsSecureUpload($_FILES['announcement_image'] ?? [], [
            'subdir' => 'cocurricular_announcements',
            'max_bytes' => 5 * 1024 * 1024,
            'allowed' => [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'webp' => ['image/webp'],
            ],
        ]);
        if (empty($imageUpload['ok'])) {
            $errors[] = 'Announcement image: ' . $imageUpload['error'];
        } elseif (!empty($imageUpload['stored_name'])) {
            $attachmentPath = 'cocurricular_announcements/' . $imageUpload['stored_name'];
            $newAttachmentPath = $attachmentPath;
        }
    }

    if (empty($errors)) {
        if ($action === 'create') {
            $saved = cocurricularCreateClubAnnouncement([
                'club_id' => $clubId,
                'title' => $title,
                'content' => $content,
                'posted_at' => $postedAt,
                'is_pinned' => $isPinned,
                'attachment_path' => $attachmentPath,
            ]);
            if ($saved) {
                $notice = 'Announcement created successfully.';
            } else {
                cocurricularRemoveStoredAnnouncementImage($newAttachmentPath);
                $errors[] = 'Unable to save the announcement. Please try again.';
            }
        }

        if ($action === 'update') {
            if ($announcementId <= 0) {
                $errors[] = 'Announcement not found.';
            } else {
                $saved = cocurricularUpdateClubAnnouncement($announcementId, [
                    'title' => $title,
                    'content' => $content,
                    'posted_at' => $postedAt,
                    'is_pinned' => $isPinned,
                    'attachment_path' => $attachmentPath,
                ]);
                if ($saved) {
                    if ($existingAttachmentPath !== '' && $existingAttachmentPath !== $attachmentPath) {
                        cocurricularRemoveStoredAnnouncementImage($existingAttachmentPath);
                    }
                    $notice = 'Announcement updated successfully.';
                } else {
                    $errors[] = 'Unable to update the announcement.';
                }
            }
        }

        if ($action === 'delete') {
            $announcementId = (int) ($_POST['announcement_id'] ?? 0);
            if ($announcementId <= 0) {
                $errors[] = 'Unable to locate the announcement.';
            } else {
                $deleted = cocurricularDeleteClubAnnouncement($announcementId);
                if ($deleted) {
                    cocurricularRemoveStoredAnnouncementImage($existingAttachmentPath);
                    $notice = 'Announcement deleted successfully.';
                } else {
                    $errors[] = 'Unable to delete the announcement.';
                }
            }
        }
    }
}

$pageTitle = 'Club Announcements';
$activeModule = 'cocurricular';
$activePage = 'student-affairs-announcements';
$breadcrumbs = [
    ['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/index.php'],
    ['label' => 'Club Announcements', 'url' => null],
];
$clubs = cocurricularFetchClubs();
$announcements = cocurricularGetAnnouncementsForManagement();

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css?v=3" rel="stylesheet">

<div class="d-flex justify-content-between align-items-center gap-3 mb-3 flex-wrap">
    <div>
        <h1 class="h4 mb-0">Club Announcements</h1>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#announcementModal" data-mode="create">
        <i class="fas fa-plus me-1"></i>Create Announcement
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

<section class="card">
    <div class="card-header"><h2 class="h5 mb-0">Announcement List</h2></div>
    <div class="card-body">
        <?php if (empty($announcements)): ?>
            <div class="alert alert-info mb-0">No announcements have been created yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Club</th>
                            <th>Title</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($announcements as $announcement): ?>
                            <tr>
                                <td><?= htmlspecialchars($announcement['club_name']) ?></td>
                                <td>
                                    <?= htmlspecialchars($announcement['title']) ?>
                                    <?php if (!empty($announcement['is_pinned'])): ?>
                                        <span class="badge rounded-pill bg-warning text-dark ms-1">Pinned</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($announcement['posted_at']))) ?></td>
                                <td>
                                    <?php if (!empty($announcement['is_pinned'])): ?>
                                        <span class="badge rounded-pill bg-info">Pinned</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill bg-secondary">Standard</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#announcementModal" data-mode="edit"
                                            data-id="<?= (int) $announcement['id'] ?>"
                                            data-club-id="<?= (int) $announcement['club_id'] ?>"
                                            data-title="<?= htmlspecialchars($announcement['title'], ENT_QUOTES) ?>"
                                            data-content="<?= htmlspecialchars($announcement['content'], ENT_QUOTES) ?>"
                                            data-posted-at="<?= htmlspecialchars(date('Y-m-d', strtotime($announcement['posted_at']))) ?>"
                                            data-is-pinned="<?= !empty($announcement['is_pinned']) ? '1' : '0' ?>"
                                            data-image-url="<?= htmlspecialchars((!empty($announcement['attachment_path']) && preg_match('~^https?://~i', (string) $announcement['attachment_path'])) ? (string) $announcement['attachment_path'] : (!empty($announcement['attachment_path']) && str_starts_with(ltrim(str_replace('\\', '/', (string) $announcement['attachment_path']), '/'), 'cocurricular_announcements/') ? BASE_URL . '/modules/cocurricular/pages/announcement-image.php?club_id=' . (int) $announcement['club_id'] . '&announcement_id=' . (int) $announcement['id'] : ''), ENT_QUOTES) ?>">
                                            Edit
                                        </button>
                                        <form method="post" action="<?= BASE_URL ?>/modules/cocurricular/pages/student-affairs-announcements.php" class="d-inline" onsubmit="return confirm('Delete this announcement?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="announcement_id" value="<?= (int) $announcement['id'] ?>">
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

<div class="modal fade" id="announcementModal" tabindex="-1" aria-labelledby="announcementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" action="<?= BASE_URL ?>/modules/cocurricular/pages/student-affairs-announcements.php">
                <div class="modal-header">
                    <h2 class="modal-title h5 mb-0" id="announcementModalLabel">Announcement</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="announcementAction" value="create">
                    <input type="hidden" name="announcement_id" id="announcementId" value="">

                    <div class="mb-3">
                        <label class="form-label" for="announcementClubId">Club</label>
                        <select id="announcementClubId" name="club_id" class="form-select" required>
                            <option value="">Select club</option>
                            <?php foreach ($clubs as $club): ?>
                                <option value="<?= (int) $club['id'] ?>"><?= htmlspecialchars($club['club_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="announcementTitle">Title</label>
                        <input id="announcementTitle" name="title" type="text" class="form-control" maxlength="191" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="announcementContent">Content</label>
                        <textarea id="announcementContent" name="content" rows="6" class="form-control" required></textarea>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="announcementPostedAt">Posted date</label>
                            <input id="announcementPostedAt" name="posted_at" type="date" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-4">
                                <input id="announcementIsPinned" name="is_pinned" type="checkbox" class="form-check-input" value="1">
                                <label class="form-check-label" for="announcementIsPinned">Pin announcement</label>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="announcementImage">Announcement image (optional)</label>
                        <input id="announcementImage" name="announcement_image" type="file" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                        <div id="announcementImagePreview" class="announcement-image-preview mt-2 d-none">
                            <img src="" alt="Announcement image preview">
                        </div>
                        <div class="form-check mt-2 d-none" id="removeImageWrap">
                            <input id="removeImage" name="remove_image" type="checkbox" class="form-check-input" value="1">
                            <label class="form-check-label" for="removeImage">Remove current image</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="announcementSubmitBtn">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const modal = document.getElementById('announcementModal');
        const actionField = document.getElementById('announcementAction');
        const submitBtn = document.getElementById('announcementSubmitBtn');
        const clubField = document.getElementById('announcementClubId');
        const titleField = document.getElementById('announcementTitle');
        const contentField = document.getElementById('announcementContent');
        const postedAtField = document.getElementById('announcementPostedAt');
        const pinnedField = document.getElementById('announcementIsPinned');
        const idField = document.getElementById('announcementId');
        const imageField = document.getElementById('announcementImage');
        const imagePreview = document.getElementById('announcementImagePreview');
        const imagePreviewImage = imagePreview.querySelector('img');
        const removeImageWrap = document.getElementById('removeImageWrap');
        const removeImage = document.getElementById('removeImage');

        modal.addEventListener('show.bs.modal', function (event) {
            const trigger = event.relatedTarget;
            const mode = trigger?.getAttribute('data-mode') || 'create';
            const id = trigger?.getAttribute('data-id') || '';
            const clubId = trigger?.getAttribute('data-club-id') || '';
            const title = trigger?.getAttribute('data-title') || '';
            const content = trigger?.getAttribute('data-content') || '';
            const postedAt = trigger?.getAttribute('data-posted-at') || '';
            const isPinned = trigger?.getAttribute('data-is-pinned') || '0';
            const imageUrl = trigger?.getAttribute('data-image-url') || '';

            if (mode === 'edit') {
                actionField.value = 'update';
                idField.value = id;
                submitBtn.textContent = 'Update';
                clubField.value = clubId;
                titleField.value = title;
                contentField.value = content;
                postedAtField.value = postedAt;
                pinnedField.checked = isPinned === '1';
                imageField.value = '';
                removeImage.checked = false;
                removeImageWrap.classList.toggle('d-none', imageUrl === '');
                imagePreviewImage.src = imageUrl;
                imagePreview.classList.toggle('d-none', imageUrl === '');
            } else {
                actionField.value = 'create';
                idField.value = '';
                submitBtn.textContent = 'Save';
                clubField.value = '';
                titleField.value = '';
                contentField.value = '';
                postedAtField.value = '';
                pinnedField.checked = false;
                imageField.value = '';
                removeImage.checked = false;
                removeImageWrap.classList.add('d-none');
                imagePreviewImage.removeAttribute('src');
                imagePreview.classList.add('d-none');
            }
        });

        imageField.addEventListener('change', function () {
            const file = imageField.files[0];
            if (!file) {
                return;
            }
            imagePreviewImage.src = URL.createObjectURL(file);
            imagePreview.classList.remove('d-none');
            removeImage.checked = false;
        });
    });
</script>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
