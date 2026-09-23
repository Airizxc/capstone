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

    $isAiGenerated = !empty($_POST['is_ai_generated']);
    $aiModel = trim((string) ($_POST['ai_model'] ?? ''));
    $aiGeneratedAt = trim((string) ($_POST['ai_generated_at'] ?? ''));

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
                'is_ai_generated' => $isAiGenerated ? 1 : 0,
                'ai_model' => $isAiGenerated ? ($aiModel !== '' ? $aiModel : 'gpt-4.1') : null,
                'ai_generated_at' => $isAiGenerated ? ($aiGeneratedAt !== '' ? $aiGeneratedAt : date('Y-m-d H:i:s')) : null,
            ]);
            if ($saved) {
                cocurricularNotifyAnnouncementPublished((int) $saved);
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
                    'is_ai_generated' => $isAiGenerated ? 1 : null,
                    'ai_model' => $isAiGenerated ? ($aiModel !== '' ? $aiModel : 'gpt-4.1') : null,
                    'ai_generated_at' => $isAiGenerated ? ($aiGeneratedAt !== '' ? $aiGeneratedAt : date('Y-m-d H:i:s')) : null,
                ]);
                if ($saved) {
                    cocurricularNotifyAnnouncementPublished($announcementId);
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
                    <input type="hidden" name="is_ai_generated" id="isAiGenerated" value="0">
                    <input type="hidden" name="ai_model" id="aiModel" value="">
                    <input type="hidden" name="ai_generated_at" id="aiGeneratedAt" value="">

                    <div class="mb-3">
                        <label class="form-label" for="announcementClubId">Club</label>
                        <select id="announcementClubId" name="club_id" class="form-select" required>
                            <option value="">Select club</option>
                            <?php foreach ($clubs as $club): ?>
                                <option value="<?= (int) $club['id'] ?>"><?= htmlspecialchars($club['club_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- AI Announcement Assistant -->
                    <div class="card bg-light border-primary border-opacity-25 mb-3" id="aiAssistantSection">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <h6 class="card-title text-primary mb-0 d-flex align-items-center gap-2">
                                    <i class="fas fa-robot text-primary"></i> AI Announcement Assistant
                                </h6>
                                <span class="badge bg-primary text-white">GPT-4.1</span>
                            </div>
                            <p class="small text-muted mb-2">
                                Provide the basic details and let GPT-4.1 prepare a draft. Review and edit the result before publishing.
                            </p>

                            <div class="row g-2 mb-2">
                                <div class="col-md-12">
                                    <input type="text" id="aiTopic" class="form-control form-control-sm" placeholder="Topic / Main idea (optional)">
                                </div>
                                <div class="col-md-12">
                                    <textarea id="aiDetails" class="form-control form-control-sm" rows="2" placeholder="Announcement details or instructions (optional)"></textarea>
                                </div>
                            </div>

                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" id="generateAiBtn">
                                    <i class="fas fa-magic me-1" id="aiBtnIcon"></i><span id="aiBtnText">Generate with AI</span>
                                </button>
                                <div id="aiDraftBadge" class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 d-none">
                                    <i class="fas fa-check-circle me-1"></i> AI-generated draft loaded. Please review & edit.
                                </div>
                            </div>
                            <div id="aiErrorAlert" class="alert alert-danger py-1 px-2 small mt-2 d-none"></div>
                        </div>
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

        // AI Assistant Elements
        const generateAiBtn = document.getElementById('generateAiBtn');
        const aiBtnIcon = document.getElementById('aiBtnIcon');
        const aiBtnText = document.getElementById('aiBtnText');
        const aiTopic = document.getElementById('aiTopic');
        const aiDetails = document.getElementById('aiDetails');
        const aiDraftBadge = document.getElementById('aiDraftBadge');
        const aiErrorAlert = document.getElementById('aiErrorAlert');
        const isAiGenerated = document.getElementById('isAiGenerated');
        const aiModel = document.getElementById('aiModel');
        const aiGeneratedAt = document.getElementById('aiGeneratedAt');

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

            // Reset AI state
            isAiGenerated.value = '0';
            aiModel.value = '';
            aiGeneratedAt.value = '';
            aiDraftBadge.classList.add('d-none');
            aiErrorAlert.classList.add('d-none');
            aiErrorAlert.textContent = '';
            aiTopic.value = '';
            aiDetails.value = '';

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

        generateAiBtn.addEventListener('click', function () {
            if (isAiGenerated.value === '1' || titleField.value.trim() !== '' || contentField.value.trim() !== '') {
                if (!confirm('Generate a new AI draft? Your current announcement content will be updated with the AI draft.')) {
                    return;
                }
            }

            const topicVal = aiTopic.value.trim();
            const detailsVal = aiDetails.value.trim();
            const titleVal = titleField.value.trim();
            const contentVal = contentField.value.trim();

            if (!topicVal && !detailsVal && !titleVal && !contentVal) {
                aiErrorAlert.textContent = 'Please enter a topic or details in the AI Assistant box, or type a draft title above.';
                aiErrorAlert.classList.remove('d-none');
                return;
            }

            aiErrorAlert.classList.add('d-none');
            generateAiBtn.disabled = true;
            aiBtnIcon.className = 'fas fa-spinner fa-spin me-1';
            aiBtnText.textContent = 'Generating draft...';

            const payload = {
                club_id: clubField.value,
                title: titleVal || topicVal,
                topic: topicVal || titleVal,
                details: detailsVal || contentVal,
            };

            fetch('<?= BASE_URL ?>/modules/cocurricular/endpoints/generate-announcement.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data) {
                    titleField.value = data.data.title || titleField.value;
                    contentField.value = data.data.content || contentField.value;
                    isAiGenerated.value = '1';
                    aiModel.value = data.model || 'gpt-4.1';
                    aiGeneratedAt.value = data.generated_at || new Date().toISOString();
                    aiDraftBadge.classList.remove('d-none');
                } else {
                    aiErrorAlert.textContent = data.error || 'Unable to generate announcement draft right now.';
                    aiErrorAlert.classList.remove('d-none');
                }
            })
            .catch(err => {
                console.error('AI Announcement generation error:', err);
                aiErrorAlert.textContent = 'Network error or connection failed. Please try again.';
                aiErrorAlert.classList.remove('d-none');
            })
            .finally(() => {
                generateAiBtn.disabled = false;
                aiBtnIcon.className = 'fas fa-magic me-1';
                aiBtnText.textContent = 'Re-generate with AI';
            });
        });

        // Auto-open modal if redirected from Event-to-Announcement AI Generator
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('ai_draft') === '1') {
            const clubIdParam = urlParams.get('club_id') || '';
            const titleParam = urlParams.get('title') || '';
            const contentParam = urlParams.get('content') || '';
            const modelParam = urlParams.get('model') || 'gpt-4.1';

            if (clubIdParam) clubField.value = clubIdParam;
            if (titleParam) titleField.value = titleParam;
            if (contentParam) contentField.value = contentParam;

            isAiGenerated.value = '1';
            aiModel.value = modelParam;
            aiGeneratedAt.value = new Date().toISOString();
            aiDraftBadge.classList.remove('d-none');

            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
        }
    });
</script>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
