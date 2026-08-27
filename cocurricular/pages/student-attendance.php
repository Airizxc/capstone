<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
$token = trim((string) ($_GET['session'] ?? $_GET['token'] ?? $_GET['attendance_token'] ?? ''));
$isAuthenticated = isAuthenticated();
if (!isAuthenticated() && $token !== '') {
    $_SESSION['attendance_return_to'] = BASE_URL . '/modules/cocurricular/pages/student-attendance.php?session=' . rawurlencode($token);
}
if ($isAuthenticated && getCurrentUserRoleKey() !== 'student') {
    http_response_code(403);
    exit('Student access is required.');
}

$pageTitle = 'Submit Attendance';
$isStudentPortalView = true;
$activeModule = 'student_portal';
$activePage = 'student-club-membership';
$breadcrumbs = [
    ['label' => 'Student Portal', 'url' => BASE_URL . '/modules/student-portal/pages/dashboard.php'],
    ['label' => 'Submit Attendance', 'url' => null],
];
require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css?v=21" rel="stylesheet">
<style>
    .student-attendance-page {
        min-height: calc(100vh - 120px);
        display: grid;
        place-items: center;
        padding: 32px 16px;
        background: linear-gradient(180deg, #f7f9fc 0%, #eef4ff 100%);
    }

    .student-attendance-shell {
        width: min(100%, 480px);
        background: #ffffff;
        border: 1px solid rgba(11, 42, 107, 0.12);
        border-radius: 20px;
        box-shadow: 0 16px 40px rgba(11, 42, 107, 0.10);
        padding: 28px 22px 20px;
    }

    .student-attendance-icon {
        width: 62px;
        height: 62px;
        border-radius: 18px;
        display: grid;
        place-items: center;
        margin: 0 auto 14px;
        background: rgba(26, 111, 196, 0.08);
        color: var(--bcp-navy, #0b2a6b);
        font-size: 1.7rem;
    }

    .attendance-eyebrow {
        display: inline-block;
        margin: 0 auto 8px;
        font-size: 0.72rem;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #4b6ca3;
        font-weight: 700;
    }

    .student-attendance-shell h1 {
        margin: 0;
        text-align: center;
        font-size: clamp(1.8rem, 3vw, 2.2rem);
        color: #0b1d3a;
    }

    .student-attendance-shell > p {
        margin: 12px 0 22px;
        text-align: center;
        color: #48617b;
        line-height: 1.55;
        font-size: 0.98rem;
    }

    .student-attendance-actions {
        display: grid;
        gap: 12px;
    }

    .student-attendance-scan {
        width: 100%;
        border: 1px solid rgba(26, 111, 196, 0.35);
        background: #f4f8ff;
        color: var(--bcp-navy, #0b2a6b);
        border-radius: 12px;
        padding: 12px 18px;
        font-weight: 700;
        cursor: pointer;
    }

    .attendance-divider {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 18px 0 14px;
        color: #657d97;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .attendance-divider::before,
    .attendance-divider::after {
        content: "";
        height: 1px;
        flex: 1;
        background: rgba(39, 72, 110, 0.18);
    }

    .student-attendance-input {
        display: block;
        margin-bottom: 16px;
    }

    .student-attendance-input span {
        display: block;
        margin-bottom: 8px;
        color: #1c385b;
        font-weight: 600;
    }

    .student-attendance-input input {
        width: 100%;
        border: 1px solid rgba(20, 65, 115, 0.2);
        background: #fff;
        border-radius: 12px;
        padding: 12px 14px;
        font-size: 1.2rem;
        letter-spacing: 0.3rem;
        text-align: center;
    }

    .attendance-primary-action,
    .attendance-secondary-action,
    .attendance-quiet-action {
        border: none;
        border-radius: 12px;
        padding: 12px 18px;
        font-weight: 700;
        cursor: pointer;
        transition: opacity 0.2s ease;
    }

    .attendance-primary-action {
        display: block;
        width: 100%;
        background: linear-gradient(180deg, #1a6fc4 0%, #0d4fae 100%);
        color: #fff;
        box-shadow: 0 8px 20px rgba(26, 111, 196, 0.25);
    }

    .attendance-secondary-action {
        background: #edf4ff;
        color: var(--bcp-navy, #0b2a6b);
    }

    .attendance-quiet-action {
        width: 100%;
        background: transparent;
        border: 1px solid rgba(11, 42, 107, 0.18);
        color: #1b345e;
    }

    .student-attendance-message,
    .student-attendance-result {
        margin-top: 16px;
        border-radius: 14px;
        padding: 14px 16px;
        border: 1px solid transparent;
        background: #f7f9fc;
        color: #1b2d41;
    }

    .student-attendance-message.is-error {
        background: #fff1f2;
        border-color: rgba(220, 38, 38, 0.2);
        color: #991b1b;
    }

    .student-attendance-message.is-success {
        background: #ecfdf5;
        border-color: rgba(16, 185, 129, 0.25);
        color: #065f46;
    }

    .student-attendance-message.is-info {
        background: #eff6ff;
        border-color: rgba(59, 130, 246, 0.25);
        color: #1d4ed8;
    }

    .student-attendance-result {
        display: grid;
        gap: 8px;
    }

    .student-attendance-result strong {
        font-size: 1.15rem;
    }

    .student-attendance-result .meta-label {
        font-size: 0.76rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #55708e;
    }

    .attendance-state-modal {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(7, 18, 39, 0.62);
        z-index: 1190;
    }

    .attendance-state-modal.is-open {
        display: flex;
        animation: attendance-modal-fade 0.18s ease-out;
    }

    .attendance-state-panel {
        width: min(100%, 520px);
        max-height: min(720px, calc(100vh - 32px));
        overflow-y: auto;
        padding: 26px;
        background: #fff;
        border: 1px solid rgba(11, 42, 107, 0.12);
        border-radius: 18px;
        box-shadow: 0 24px 60px rgba(11, 42, 107, 0.24);
        animation: attendance-modal-rise 0.22s ease-out;
    }

    .attendance-state-heading {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 20px;
    }

    .attendance-state-icon {
        flex: 0 0 42px;
        width: 42px;
        height: 42px;
        display: grid;
        place-items: center;
        border-radius: 13px;
        background: rgba(26, 111, 196, 0.10);
        color: #0d4fae;
        font-size: 1.2rem;
    }

    .attendance-state-heading h2 {
        margin: 0;
        color: #0b1d3a;
        font-size: 1.4rem;
    }

    .attendance-state-heading p,
    .attendance-state-copy {
        margin: 5px 0 0;
        color: #48617b;
        line-height: 1.5;
    }

    .attendance-details {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px 18px;
        margin: 0 0 20px;
        padding: 16px;
        background: #f7f9fc;
        border-radius: 13px;
    }

    .attendance-details dt {
        margin: 0 0 4px;
        color: #617d96;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .attendance-details dd {
        margin: 0;
        color: #102a4c;
        font-weight: 650;
        overflow-wrap: anywhere;
    }

    .attendance-state-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 22px;
    }

    .attendance-state-actions .attendance-primary-action,
    .attendance-state-actions .attendance-secondary-action {
        width: auto;
        min-width: 132px;
    }

    .attendance-state-modal.is-processing .attendance-state-panel {
        pointer-events: none;
    }

    body.attendance-modal-open { overflow: hidden; }
    @keyframes attendance-modal-fade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes attendance-modal-rise { from { opacity: 0; transform: translateY(10px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
    @media (prefers-reduced-motion: reduce) {
        .attendance-state-modal.is-open,
        .attendance-state-panel { animation: none; }
    }

    .scanner-modal {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        background: rgba(7, 18, 39, 0.68);
        padding: 16px;
        z-index: 1200;
    }

    .scanner-modal.is-open {
        display: flex;
    }

    .scanner-panel {
        width: min(100%, 460px);
        background: #0f172a;
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 24px 56px rgba(15, 23, 42, 0.45);
        border: 1px solid rgba(148, 163, 184, 0.25);
    }

    .scanner-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 14px 18px 10px;
        color: #fff;
    }

    .scanner-header h2 {
        margin: 0;
        font-size: 1.1rem;
    }

    .scanner-video-wrap {
        position: relative;
        margin: 0 14px 14px;
        border-radius: 14px;
        overflow: hidden;
        background: #111827;
        border: 1px solid rgba(148, 163, 184, 0.22);
    }

    .scanner-video-wrap::before,
    .scanner-video-wrap::after {
        content: "";
        position: absolute;
        inset: 50% auto auto 50%;
        width: 68%;
        height: 68%;
        transform: translate(-50%, -50%);
        border: 2px solid rgba(255, 255, 255, 0.6);
        border-radius: 18px;
        box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.26);
    }

    .scanner-video-wrap::after {
        inset: 50% auto auto 50%;
        width: 55%;
        height: 55%;
        border-radius: 14px;
    }

    #scannerVideo {
        display: block;
        width: 100%;
        max-height: 65vh;
        min-height: 280px;
        object-fit: cover;
        background: #0b1120;
    }

    .scanner-body {
        padding: 0 18px 18px;
        color: #dfeafc;
        text-align: center;
    }

    .scanner-status {
        display: block;
        margin: 12px 0 16px;
        font-size: 0.92rem;
        min-height: 1.4em;
    }

    .scanner-actions {
        display: grid;
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .scanner-actions.double {
        grid-template-columns: 1fr 1fr;
    }

    @media (max-width: 576px) {
        .student-attendance-shell {
            padding: 22px 16px 18px;
        }

        .attendance-details {
            grid-template-columns: 1fr;
        }

        .attendance-state-panel {
            padding: 22px 18px;
        }

        .attendance-state-actions {
            flex-direction: column-reverse;
        }

        .attendance-state-actions .attendance-primary-action,
        .attendance-state-actions .attendance-secondary-action {
            width: 100%;
        }

        .scanner-panel {
            max-height: 92vh;
        }
    }
</style>
<main class="student-attendance-page" data-attendance-authenticated="<?= $isAuthenticated ? '1' : '0' ?>">
    <section class="student-attendance-shell" aria-labelledby="studentAttendanceTitle">
        <div class="student-attendance-icon"><i class="fas fa-clipboard-check"></i></div>
        <span class="attendance-eyebrow">Student attendance</span>
        <h1 id="studentAttendanceTitle">Join an attendance session</h1>
        <p>Scan the QR code provided by OSA or enter the six-digit attendance code.</p>

        <div class="student-attendance-actions">
            <button type="button" class="student-attendance-scan" id="scanQrButton"><i class="fas fa-qrcode"></i> Scan QR Code</button>
        </div>

        <div class="attendance-divider">OR</div>

        <form id="studentAttendanceForm" data-endpoint="<?= htmlspecialchars(BASE_URL . '/modules/cocurricular/pages/student-attendance-process.php', ENT_QUOTES) ?>">
            <label class="student-attendance-input">
                <span>Six-digit attendance code</span>
                <input id="attendanceCredential" name="credential" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" value="" placeholder="000000" aria-label="Six-digit attendance code">
            </label>
            <button class="attendance-primary-action" type="submit"><i class="fas fa-arrow-right"></i> Verify Attendance</button>
        </form>

        <div id="studentAttendanceMessage" class="student-attendance-message" role="status" aria-live="polite"></div>
        <div id="studentAttendanceResult" class="student-attendance-result" hidden></div>

    </section>
</main>

<div id="attendanceStateModal" class="attendance-state-modal" aria-hidden="true">
    <div class="attendance-state-panel" role="dialog" aria-modal="true" aria-labelledby="attendanceStateTitle">
        <div class="attendance-state-heading">
            <span class="attendance-state-icon"><i id="attendanceStateIcon" class="fas fa-clipboard-check"></i></span>
            <div>
                <h2 id="attendanceStateTitle">Attendance Confirmation</h2>
                <p id="attendanceStateSubtitle">Review the session details before confirming.</p>
            </div>
        </div>
        <div id="attendanceConfirmationContent">
            <dl class="attendance-details">
                <div><dt>Event</dt><dd id="confirmEventName">-</dd></div>
                <div><dt>Club</dt><dd id="confirmClubName">-</dd></div>
                <div><dt>Date</dt><dd id="confirmAttendanceDate">-</dd></div>
                <div><dt>Time</dt><dd id="confirmAttendanceTime">-</dd></div>
                <div><dt>Location</dt><dd id="confirmAttendanceLocation">-</dd></div>
            </dl>
            <p class="attendance-state-copy">Your attendance will be recorded using your authenticated student account.</p>
        </div>
        <div id="attendanceOutcomeContent" hidden>
            <p id="attendanceOutcomeCopy" class="attendance-state-copy"></p>
            <dl id="attendanceOutcomeDetails" class="attendance-details" hidden>
                <div><dt>Event</dt><dd id="outcomeEventName">-</dd></div>
                <div><dt>Date and time</dt><dd id="outcomeDateTime">-</dd></div>
            </dl>
        </div>
        <div class="attendance-state-actions">
            <button type="button" class="attendance-secondary-action" id="cancelConfirmationButton">Cancel</button>
            <button type="button" class="attendance-primary-action" id="confirmAttendanceButton">Confirm Attendance</button>
            <a class="attendance-primary-action" id="attendanceOutcomeAction" href="#" hidden style="text-align:center;text-decoration:none;">Back to My Club</a>
        </div>
    </div>
</div>

<div id="scannerModal" class="scanner-modal" aria-hidden="true">
    <div class="scanner-panel" role="dialog" aria-modal="true" aria-labelledby="scannerTitle">
        <div class="scanner-header">
            <h2 id="scannerTitle">Scan Attendance QR</h2>
            <button type="button" class="attendance-secondary-action" id="closeScannerButton">Cancel</button>
        </div>
        <div class="scanner-video-wrap">
            <video id="scannerVideo" playsinline muted></video>
        </div>
        <div class="scanner-body">
            <p>Point your camera at the QR code displayed by OSA.</p>
            <span id="scannerStatus" class="scanner-status" aria-live="polite"></span>
            <div class="scanner-actions double">
                <button type="button" class="attendance-quiet-action" id="scannerTryAgainButton">Try Again</button>
                <button type="button" class="attendance-secondary-action" id="scannerEnterCodeButton">Enter Code Instead</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script>
(() => {
    const form = document.getElementById('studentAttendanceForm');
    const message = document.getElementById('studentAttendanceMessage');
    const result = document.getElementById('studentAttendanceResult');
    const stateModal = document.getElementById('attendanceStateModal');
    const statePanel = stateModal?.querySelector('.attendance-state-panel');
    const stateIcon = document.getElementById('attendanceStateIcon');
    const stateTitle = document.getElementById('attendanceStateTitle');
    const stateSubtitle = document.getElementById('attendanceStateSubtitle');
    const confirmationContent = document.getElementById('attendanceConfirmationContent');
    const outcomeContent = document.getElementById('attendanceOutcomeContent');
    const outcomeCopy = document.getElementById('attendanceOutcomeCopy');
    const outcomeDetails = document.getElementById('attendanceOutcomeDetails');
    const outcomeEventName = document.getElementById('outcomeEventName');
    const outcomeDateTime = document.getElementById('outcomeDateTime');
    const outcomeAction = document.getElementById('attendanceOutcomeAction');
    const confirmationEventName = document.getElementById('confirmEventName');
    const confirmationClubName = document.getElementById('confirmClubName');
    const confirmationDate = document.getElementById('confirmAttendanceDate');
    const confirmationTime = document.getElementById('confirmAttendanceTime');
    const confirmationLocation = document.getElementById('confirmAttendanceLocation');
    const credentialInput = document.getElementById('attendanceCredential');
    const scanQrButton = document.getElementById('scanQrButton');
    const scannerModal = document.getElementById('scannerModal');
    const scannerVideo = document.getElementById('scannerVideo');
    const scannerStatus = document.getElementById('scannerStatus');
    const scannerTryAgainButton = document.getElementById('scannerTryAgainButton');
    const scannerEnterCodeButton = document.getElementById('scannerEnterCodeButton');
    const closeScannerButton = document.getElementById('closeScannerButton');
    const cancelConfirmationButton = document.getElementById('cancelConfirmationButton');
    const confirmAttendanceButton = document.getElementById('confirmAttendanceButton');
    const endpoint = form?.dataset.endpoint || '';
    const attendanceAuthenticated = document.querySelector('.student-attendance-page')?.dataset.attendanceAuthenticated === '1';
    const myClubUrl = '<?= htmlspecialchars(BASE_URL . '/modules/cocurricular/pages/student-club-membership.php', ENT_QUOTES) ?>';
    let activeCredential = '';
    let currentStream = null;
    let scanningFrameToken = 0;
    let submissionInProgress = false;

    const redirectToLoginWithToken = (credential) => {
        const resolved = normalizeCredential(credential || credentialInput.value || '');
        if (!resolved) {
            return;
        }
        const loginUrl = '<?= htmlspecialchars(BASE_URL . '/login/login.php', ENT_QUOTES) ?>';
        window.location.href = loginUrl + '?attendance=' + encodeURIComponent(resolved);
    };

    const setMessage = (text, type = 'info') => {
        if (!message) return;
        message.textContent = text || '';
        message.className = 'student-attendance-message';
        if (type) {
            message.classList.add('is-' + type);
        }
    };

    const clearResult = () => {
        if (!result) return;
        result.hidden = true;
        result.textContent = '';
        result.className = 'student-attendance-result';
    };

    const resetMainPageState = () => {
        if (form) {
            form.hidden = false;
        }
        clearResult();
        closeStateModal();
        setMessage('', 'info');
    };

    const closeStateModal = () => {
        if (!stateModal) return;
        stateModal.classList.remove('is-open', 'is-processing');
        stateModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('attendance-modal-open');
    };

    const openStateModal = () => {
        if (!stateModal) return;
        stateModal.classList.add('is-open');
        stateModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('attendance-modal-open');
    };

    const showConfirmationActions = () => {
        cancelConfirmationButton.hidden = false;
        cancelConfirmationButton.textContent = 'Cancel';
        confirmAttendanceButton.hidden = false;
        confirmAttendanceButton.disabled = false;
        confirmAttendanceButton.innerHTML = '<i class="fas fa-check"></i> Confirm Attendance';
        outcomeAction.hidden = true;
    };

    const renderConfirmation = (data) => {
        if (!stateModal) return;
        confirmationEventName.textContent = data.event || '-';
        confirmationClubName.textContent = data.club || 'Club';
        confirmationDate.textContent = data.date || '-';
        confirmationTime.textContent = data.time || '-';
        confirmationLocation.textContent = data.location || '-';
        stateTitle.textContent = 'Attendance Confirmation';
        stateSubtitle.textContent = 'Review the session details before confirming.';
        stateIcon.className = 'fas fa-clipboard-check';
        stateIcon.parentElement.style.background = 'rgba(26, 111, 196, 0.10)';
        stateIcon.parentElement.style.color = '#0d4fae';
        confirmationContent.hidden = false;
        outcomeContent.hidden = true;
        showConfirmationActions();
        if (form) form.hidden = false;
        clearResult();
        openStateModal();
    };

    const getReturnUrl = (data) => data?.return_url || myClubUrl;

    const renderOutcomeState = (data, heading, copy, isSuccess = false) => {
        if (!stateModal) return;
        stateModal.classList.remove('is-processing');
        stateTitle.textContent = heading;
        stateSubtitle.textContent = isSuccess ? 'Your check-in has been saved.' : '';
        stateIcon.className = isSuccess ? 'fas fa-circle-check' : 'fas fa-circle-exclamation';
        stateIcon.parentElement.style.background = isSuccess ? 'rgba(16, 185, 129, 0.12)' : 'rgba(220, 38, 38, 0.10)';
        stateIcon.parentElement.style.color = isSuccess ? '#047857' : '#b91c1c';
        confirmationContent.hidden = true;
        outcomeContent.hidden = false;
        outcomeCopy.textContent = copy;
        outcomeDetails.hidden = !isSuccess;
        outcomeEventName.textContent = data.event || 'Event';
        outcomeDateTime.textContent = formatAttendanceDate(data.date) + ' · ' + formatAttendanceTime(data.time || data.recorded_at);
        cancelConfirmationButton.hidden = true;
        confirmAttendanceButton.hidden = true;
        outcomeAction.hidden = false;
        outcomeAction.href = getReturnUrl(data);
        if (form) form.hidden = false;
        clearResult();
        openStateModal();
    };

    const renderRecordedState = (data, isAlreadyRecorded = false) => {
        const checkIn = data.recorded_at || data.marked_at || '-';
        const note = isAlreadyRecorded ? 'Your attendance for this session has already been recorded.' : 'Your attendance has been successfully recorded.';
        renderOutcomeState(data, isAlreadyRecorded ? 'Already Recorded' : 'Attendance Recorded', note + ' Check-in time: ' + checkIn, !isAlreadyRecorded);
    };

    const renderClosedState = (messageText, data = null) => {
        renderOutcomeState(data || {}, 'Session Closed', messageText || 'This attendance session is no longer accepting submissions.');
    };

    const renderInvalidState = (isQrInvalid = false) => {
        renderOutcomeState({}, isQrInvalid ? 'Invalid QR Code' : 'Invalid Attendance Code', isQrInvalid ? 'This QR code is not a valid attendance session.' : 'This attendance code is not valid. Please try again.');
        cancelConfirmationButton.hidden = false;
        cancelConfirmationButton.textContent = 'Try Again';
    };

    const renderNotEligibleState = (data = null) => {
        renderOutcomeState(data || {}, 'Not Eligible', 'You are not an approved participant for this attendance session.');
    };

    const formatTimeRange = (startTime, endTime) => {
        if (!startTime && !endTime) {
            return '-';
        }
        const start = startTime ? startTime : 'N/A';
        const end = endTime ? endTime : 'N/A';
        return start + ' - ' + end;
    };

    const formatAttendanceDate = (value) => {
        if (!value) return '-';
        const date = new Date(String(value).slice(0, 10) + 'T00:00:00');
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' });
    };

    const formatAttendanceTime = (value) => {
        if (!value) return '-';
        const range = String(value).split(' - ');
        return range.map((part) => {
            const time = part.trim().slice(0, 5);
            const parsed = new Date('1970-01-01T' + time + ':00');
            return Number.isNaN(parsed.getTime()) ? part.trim() : parsed.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
        }).join(' - ');
    };

    const normalizeCredential = (value) => {
        const raw = (value || '').trim();
        if (!raw) {
            return '';
        }
        try {
            const parsed = new URL(raw, window.location.origin);
            const candidates = ['session', 'token', 'attendance_token', 'attendance', 'credential'];
            for (const key of candidates) {
                const param = parsed.searchParams.get(key);
                if (param && param.trim()) {
                    return param.trim();
                }
            }
        } catch (error) {
            // Ignore malformed URL and treat raw value as the token itself.
        }
        return raw;
    };

    const stopScanner = () => {
        scanningFrameToken += 1;
        if (currentStream) {
            currentStream.getTracks().forEach((track) => track.stop());
            currentStream = null;
        }
        if (scannerVideo) {
            scannerVideo.srcObject = null;
        }
        if (scannerModal) {
            scannerModal.classList.remove('is-open');
            scannerModal.setAttribute('aria-hidden', 'true');
        }
        if (!stateModal?.classList.contains('is-open')) {
            document.body.classList.remove('attendance-modal-open');
        }
    };

    const openScanner = async () => {
        if (!scannerModal || !scannerVideo) {
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            scannerStatus.textContent = 'Camera access is unavailable. You can allow camera permission and try again, or enter the six-digit attendance code instead.';
            scannerModal.classList.add('is-open');
            scannerModal.setAttribute('aria-hidden', 'false');
            return;
        }

        scannerModal.classList.add('is-open');
        scannerModal.setAttribute('aria-hidden', 'false');
        scannerStatus.textContent = 'Requesting camera access…';
        try {
            stopScanner();
            scannerModal.classList.add('is-open');
            scannerModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('attendance-modal-open');
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                },
                audio: false
            });
            currentStream = stream;
            scannerVideo.srcObject = stream;
            await scannerVideo.play();
            scannerStatus.textContent = 'Scanning…';

            const frameToken = ++scanningFrameToken;
            const decodeFrame = () => {
                if (frameToken !== scanningFrameToken) {
                    return;
                }
                if (scannerVideo.readyState < 2 || scannerVideo.videoWidth === 0 || scannerVideo.videoHeight === 0) {
                    requestAnimationFrame(decodeFrame);
                    return;
                }
                const canvas = document.createElement('canvas');
                const context = canvas.getContext('2d');
                if (!context) {
                    requestAnimationFrame(decodeFrame);
                    return;
                }
                canvas.width = scannerVideo.videoWidth;
                canvas.height = scannerVideo.videoHeight;
                context.drawImage(scannerVideo, 0, 0, canvas.width, canvas.height);
                const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
                const code = typeof jsQR !== 'undefined' ? jsQR(imageData.data, canvas.width, canvas.height) : null;
                if (code && code.data) {
                    const credential = normalizeCredential(code.data);
                    if (!credential) {
                        scannerStatus.textContent = 'This QR code is not a valid attendance session.';
                        requestAnimationFrame(decodeFrame);
                        return;
                    }
                    scannerStatus.textContent = 'QR detected. Verifying session…';
                    stopScanner();
                    credentialInput.value = credential;
                    activeCredential = credential;
                    validateAttendanceCredential(credential, 'qr');
                    return;
                }
                requestAnimationFrame(decodeFrame);
            };
            requestAnimationFrame(decodeFrame);
        } catch (error) {
            stopScanner();
            const messageText = error && error.name === 'NotAllowedError'
                ? 'Camera access is unavailable. You can allow camera permission and try again, or enter the six-digit attendance code instead.'
                : 'Camera access is unavailable. Please try again or enter the six-digit attendance code instead.';
            scannerStatus.textContent = messageText;
            setMessage('', 'info');
        }
    };

    const validateAttendanceCredential = async (credential, origin = 'manual') => {
        const normalized = normalizeCredential(credential);
        if (!normalized) {
            setMessage('Please enter a valid attendance code or scan a valid attendance QR.', 'error');
            return;
        }

        if (!attendanceAuthenticated) {
            redirectToLoginWithToken(normalized);
            return;
        }

        credentialInput.value = normalized;
        activeCredential = normalized;
        clearResult();
        closeStateModal();
        setMessage(origin === 'qr' ? 'Checking this attendance session…' : '', 'info');

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: new URLSearchParams({ action: 'validate', credential: normalized })
            });
            const data = await response.json();
            const handledStatuses = ['ready', 'already_recorded', 'expired', 'closed', 'finalized', 'invalid_code', 'not_open', 'not_approved'];
            if (!response.ok && !handledStatuses.includes(data.status)) {
                throw new Error(data.message || 'Attendance session could not be validated.');
            }

            if (data.status === 'ready') {
                renderConfirmation({
                    event: data.event || 'Event',
                    club: data.club || 'Club',
                        date: formatAttendanceDate(data.date),
                        time: formatAttendanceTime(data.time),
                    location: data.location || '-'
                });
                setMessage('Attendance session verified.', 'success');
                return;
            }

            if (data.status === 'already_recorded') {
                renderRecordedState(data, true);
                setMessage('This attendance session has already been recorded.', 'success');
                return;
            }

            if (data.status === 'expired' || data.status === 'closed' || data.status === 'finalized') {
                renderClosedState('This attendance session is no longer accepting submissions.', data);
                setMessage('Attendance is closed for this session.', 'error');
                return;
            }

            if (data.status === 'invalid_code') {
                renderInvalidState(origin === 'qr');
                setMessage('Attendance session not found.', 'error');
                return;
            }

            if (data.status === 'not_open') {
                renderClosedState('Attendance will open at a later time.', data);
                setMessage('Attendance is not yet open.', 'error');
                return;
            }

            if (data.status === 'not_approved') {
                renderNotEligibleState(data);
                setMessage('You are not an approved participant for this attendance session.', 'error');
                return;
            }

            setMessage(data.message || 'Attendance could not be processed.', 'error');
        } catch (error) {
            setMessage(error.message || 'Unable to continue with this attendance session.', 'error');
        }
    };

    const submitAttendance = async () => {
        const normalized = normalizeCredential(activeCredential || credentialInput.value || '');
        if (!normalized) {
            setMessage('Please select a valid attendance session first.', 'error');
            return;
        }

        if (!attendanceAuthenticated) {
            redirectToLoginWithToken(normalized);
            return;
        }

        if (submissionInProgress) {
            return;
        }

        submissionInProgress = true;
        stateModal?.classList.add('is-processing');
        confirmAttendanceButton.disabled = true;
        confirmAttendanceButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Recording…';
        setMessage('Recording your attendance…', 'info');

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: new URLSearchParams({ action: 'submit', credential: normalized })
            });
            const data = await response.json();
            const handledStatuses = ['recorded', 'already_recorded', 'expired', 'closed', 'finalized', 'not_open', 'not_approved', 'invalid_code'];
            if (!response.ok && !handledStatuses.includes(data.status)) {
                throw new Error(data.message || 'Attendance could not be submitted.');
            }

            if (data.status === 'recorded') {
                renderRecordedState({ ...data, recorded_at: data.recorded_at || data.marked_at || '-', marked_at: data.recorded_at || data.marked_at || '-' }, false);
                setMessage('Your attendance has been successfully recorded.', 'success');
                return;
            }

            if (data.status === 'already_recorded') {
                renderRecordedState(data, true);
                setMessage('You already recorded your attendance for this session.', 'success');
                return;
            }

            if (data.status === 'expired' || data.status === 'closed' || data.status === 'finalized') {
                renderClosedState('This attendance session is no longer accepting submissions.', data);
                setMessage('Attendance is closed for this session.', 'error');
                return;
            }

            if (data.status === 'not_approved') {
                renderNotEligibleState(data);
                setMessage('You are not an approved participant for this attendance session.', 'error');
                return;
            }

            setMessage(data.message || 'Attendance could not be submitted.', 'error');
        } catch (error) {
            setMessage(error.message || 'Unable to record attendance right now.', 'error');
            stateModal?.classList.remove('is-processing');
            confirmAttendanceButton.disabled = false;
            confirmAttendanceButton.innerHTML = '<i class="fas fa-check"></i> Confirm Attendance';
        } finally {
            submissionInProgress = false;
        }
    };

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const value = credentialInput.value.trim();
        if (!value) {
            setMessage('Please enter the six-digit attendance code.', 'error');
            return;
        }
        await validateAttendanceCredential(value, 'manual');
    });

    scanQrButton?.addEventListener('click', () => {
        resetMainPageState();
        scannerStatus.textContent = 'Requesting camera access…';
        openScanner();
    });

    closeScannerButton?.addEventListener('click', () => {
        stopScanner();
        scannerStatus.textContent = '';
        setMessage('', 'info');
    });

    scannerTryAgainButton?.addEventListener('click', () => {
        scannerStatus.textContent = 'Requesting camera access…';
        openScanner();
    });

    scannerEnterCodeButton?.addEventListener('click', () => {
        stopScanner();
        scannerStatus.textContent = '';
        setMessage('Enter the six-digit attendance code to continue.', 'info');
        credentialInput.focus();
    });

    cancelConfirmationButton?.addEventListener('click', () => {
        activeCredential = '';
        if (credentialInput) credentialInput.value = '';
        resetMainPageState();
    });

    confirmAttendanceButton?.addEventListener('click', () => {
        submitAttendance();
    });

    stateModal?.addEventListener('click', (event) => {
        if (event.target === stateModal && !submissionInProgress) {
            cancelConfirmationButton.click();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && stateModal?.classList.contains('is-open') && !submissionInProgress && !confirmAttendanceButton.hidden) {
            cancelConfirmationButton.click();
        }
    });

})();
</script>
<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
