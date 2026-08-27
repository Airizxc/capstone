<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';
requireAuth();
if (getCurrentUserRoleKey() !== 'osa' || !userCanAccessModule('cocurricular')) { http_response_code(403); exit('Unauthorized'); }

$clubs = cocurricularFetchAttendanceClubs();
$clubId = (int) ($_GET['club_id'] ?? 0);
$eventId = (int) ($_GET['event_id'] ?? 0);
$attendanceId = (int) ($_GET['attendance_id'] ?? 0);
$isCreating = !empty($_GET['create']);
if ($isCreating) { $attendanceId = 0; }
$selectedClub = null;
foreach ($clubs as $club) { if ((int) $club['id'] === $clubId) { $selectedClub = $club; break; } }
if (!$selectedClub) { $clubId = 0; }
$events = $clubId ? cocurricularFetchAttendanceEventsForClub($clubId) : [];
$selectedEvent = null;
foreach ($events as $event) { if ((int) $event['id'] === $eventId) { $selectedEvent = $event; break; } }
$sessions = $selectedEvent ? cocurricularFetchAttendanceSessions($eventId) : [];
$session = $selectedEvent && $attendanceId > 0 && !$isCreating ? cocurricularGetAttendanceSession($attendanceId) : null;
if ($session && (int) $session['event_id'] !== $eventId) { $session = null; $attendanceId = 0; }
$sessionData = is_array($session) ? $session : [];
$sessionStatus = is_array($session) ? (string) ($session['status'] ?? 'Not Started') : 'Not Started';
$attendanceCode = $sessionData['attendance_code'] ?? null;
$studentAttendanceUrl = $sessionData['access_token']
	? BASE_URL . '/modules/cocurricular/pages/student-attendance.php?session=' . rawurlencode((string) $sessionData['access_token'])
	: '';
$roster = $session ? cocurricularFetchAttendanceRoster($attendanceId) : [];
$summary = $session ? cocurricularGetAttendanceSummary($attendanceId) : [];
$pageTitle = 'Attendance Tracker'; $activeModule = 'cocurricular'; $activePage = 'attendance-tracker';
$breadcrumbs = [['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/index.php'], ['label' => 'Attendance Tracker', 'url' => null]];
$hideModulePageBanner = true;
require_once __DIR__ . '/../../../includes/breadcrumbs.php'; require_once __DIR__ . '/../../../includes/layout-start.php'; renderBreadcrumbs($breadcrumbs);
?>
<link href="<?= BASE_URL ?>/modules/cocurricular/assets/css/cocurricular.css?v=21" rel="stylesheet">
<?php if ($session && $studentAttendanceUrl): ?><script src="<?= BASE_URL ?>/assets/js/vendor/qrcode.min.js"></script><?php endif; ?>
<main class="attendance-page" id="attendanceTracker" data-event-id="<?= $eventId ?>" data-attendance-id="<?= $attendanceId ?>" data-create-mode="<?= $isCreating ? 'true' : 'false' ?>">
<header class="attendance-page-header"><div><span class="attendance-eyebrow">Co-Curricular / OSA</span><h1>Attendance Tracker</h1><p>Manage attendance for current approved event participants.</p></div><?php if ($session): ?><a class="attendance-refresh" href="?club_id=<?= $clubId ?>&event_id=<?= $eventId ?>&attendance_id=<?= $attendanceId ?>" title="Refresh dashboard"><i class="fas fa-sync-alt"></i><span>Refresh</span></a><?php endif; ?></header>
<?php if (!empty($_GET['created'])): ?><div class="attendance-success-notice" role="status"><strong>Attendance session created.</strong><span>The new session is ready to manage below.</span></div><?php endif; ?>
<?php if ($selectedEvent && $session): ?><a class="attendance-new-session-link" href="?club_id=<?= $clubId ?>&event_id=<?= $eventId ?>&create=1"><i class="fas fa-plus"></i> Create New Session</a><?php endif; ?>
<?php if (!$selectedEvent): ?>
<section class="attendance-setup-shell"><div class="attendance-setup-step"><span>01</span><div><strong>Create Attendance</strong><small>Select a club and event to begin.</small></div></div><form class="attendance-setup-form" id="attendanceSelectionForm"><div class="attendance-setup-grid"><div class="attendance-field"><label for="clubSelect">Club</label><select id="clubSelect" required><option value="">Select club</option><?php foreach ($clubs as $club): ?><option value="<?= (int) $club['id'] ?>" <?= $clubId === (int) $club['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $club['club_name']) ?></option><?php endforeach; ?></select></div><div class="attendance-field"><label for="eventSelect">Event</label><select id="eventSelect" <?= $clubId ? '' : 'disabled' ?> required><option value="">Select event</option><?php foreach ($events as $event): ?><option value="<?= (int) $event['id'] ?>"><?= htmlspecialchars((string) $event['title']) ?> - <?= (int) $event['approved_count'] ?> approved</option><?php endforeach; ?></select></div></div></form><?php if ($clubId && empty($events)): ?><p class="attendance-form-note">No Published events with current approved participants are available for this club.</p><?php endif; ?></section>
<?php else: ?>
<section class="attendance-context-bar"><div class="attendance-context-main"><span class="attendance-event-icon"><i class="fas fa-calendar-check"></i></span><div><span class="attendance-label"><?= htmlspecialchars((string) $selectedEvent['club_name']) ?></span><h2><?= htmlspecialchars((string) $selectedEvent['title']) ?></h2><p><?= htmlspecialchars((string) ($sessionData['location'] ?? $selectedEvent['venue'] ?? '')) ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime((string) ($sessionData['session_date'] ?? $selectedEvent['event_date'])))) ?> &middot; <?= htmlspecialchars(date('g:i A', strtotime((string) ($sessionData['session_start_time'] ?? $selectedEvent['start_time'])))) ?> - <?= htmlspecialchars(date('g:i A', strtotime((string) ($sessionData['session_end_time'] ?? $selectedEvent['end_time'])))) ?></p></div></div><div class="attendance-context-meta"><span class="attendance-status status-<?= strtolower(str_replace(' ', '-', $sessionStatus)) ?>"><i class="fas fa-circle"></i><?= htmlspecialchars($sessionStatus) ?></span><?php if (!empty($sessionData)): ?><small><?= htmlspecialchars((string) ($sessionData['attendance_method'] ?? 'QR Code')) ?> &middot; <?= !empty($sessionData['attendance_deadline']) ? 'Deadline ' . htmlspecialchars(date('g:i A', strtotime((string) $sessionData['attendance_deadline']))) : 'Schedule unavailable' ?></small><?php endif; ?></div><?php if ($sessionStatus === 'Open'): ?><button class="attendance-close-action" type="button" data-attendance-action="close">Close session</button><?php endif; ?></section>
<?php if ($sessionStatus === 'Closed'): ?><div class="attendance-closed-notice"><strong><i class="fas fa-lock"></i> Attendance Closed</strong><span>This attendance session is now closed. Student attendance submissions are no longer accepted.</span></div><?php endif; ?>
<?php if ($session && $sessionStatus === 'Closed' && empty($sessionData['finalized_at'])): ?><button class="attendance-finalize-action" type="button" data-attendance-action="finalize"><i class="fas fa-check-double"></i> Finalize Attendance</button><?php elseif ($session && !empty($sessionData['finalized_at'])): ?><div class="attendance-closed-notice"><strong><i class="fas fa-lock"></i> Attendance Finalized</strong><span>Final attendance is read-only.</span></div><?php endif; ?>
<?php if ($session && $studentAttendanceUrl): ?><section class="attendance-access-panel"><div><span class="attendance-eyebrow">Attendance access</span><h2>Share this session with students</h2><p>Students can scan the QR code or enter the six-digit code.</p></div><div class="attendance-access-content"><div class="attendance-qr-wrap"><canvas id="attendanceQr" width="150" height="150" aria-label="QR code for this attendance session"></canvas></div><div class="attendance-code-block"><span>Attendance Code</span><strong id="attendanceCode"><?= htmlspecialchars(str_pad((string) $attendanceCode, 6, '0', STR_PAD_LEFT)) ?></strong><small>Use this code to record attendance.</small><div class="attendance-access-actions"><button class="attendance-copy-action" type="button" id="copyAttendanceCode"><i class="fas fa-copy"></i> Copy code</button><a class="attendance-open-student" href="<?= htmlspecialchars($studentAttendanceUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> Open student page</a></div><span id="attendanceCopyMessage" class="attendance-copy-message" role="status"></span></div></div></section><?php endif; ?>
<?php if ($selectedEvent): ?><section class="attendance-session-list"><div class="attendance-roster-toolbar"><div><span class="attendance-eyebrow">Attendance sessions</span><h2><?= htmlspecialchars((string) $selectedEvent['title']) ?></h2></div><span><?= count($sessions) ?> session<?= count($sessions) === 1 ? '' : 's' ?></span></div><?php if ($sessions): ?><div class="attendance-session-cards"><?php foreach ($sessions as $listedSession): ?><article class="attendance-session-card"><div><strong><?= htmlspecialchars(date('M j, Y', strtotime((string) $listedSession['session_date']))) ?></strong><span><?= htmlspecialchars(date('g:i A', strtotime((string) $listedSession['session_start_time']))) ?> - <?= htmlspecialchars(date('g:i A', strtotime((string) $listedSession['session_end_time']))) ?></span><small><?= htmlspecialchars((string) ($listedSession['location'] ?? '')) ?></small></div><div><span class="attendance-status status-<?= strtolower((string) $listedSession['status']) ?>"><?= htmlspecialchars((string) $listedSession['status']) ?></span><small>Present <?= (int) $listedSession['present_count'] ?> · Late <?= (int) $listedSession['late_count'] ?> · Not marked <?= (int) $listedSession['not_marked_count'] ?></small></div><a class="attendance-open-student" href="?club_id=<?= $clubId ?>&event_id=<?= $eventId ?>&attendance_id=<?= (int) $listedSession['id'] ?>"><?= (int) $listedSession['status'] ===  'Closed' ? 'View session' : 'Manage session' ?></a></article><?php endforeach; ?></div><?php endif; ?></section>
<section class="attendance-setup-shell"><div class="attendance-setup-step"><span>02</span><div><strong>Create Attendance Session</strong><small>Configure a new check-in period for this event.</small></div></div><form class="attendance-setup-form" id="attendanceCreateForm" data-endpoint="<?= htmlspecialchars(BASE_URL . '/modules/cocurricular/pages/attendance-process.php', ENT_QUOTES) ?>"><div class="attendance-setup-grid"><div class="attendance-field"><label for="location">Venue / Location</label><input id="location" name="location" value="<?= htmlspecialchars((string) ($selectedEvent['venue'] ?? '')) ?>" placeholder="e.g. MV 3rd Floor, Room 302" required></div><div class="attendance-field"><label for="sessionDate">Date</label><input id="sessionDate" name="session_date" type="date" value="<?= htmlspecialchars((string) $selectedEvent['event_date']) ?>" required></div><div class="attendance-field"><label for="sessionStart">Start Time</label><input id="sessionStart" name="session_start_time" type="time" value="<?= htmlspecialchars(substr((string) $selectedEvent['start_time'], 0, 5)) ?>" required></div><div class="attendance-field"><label for="sessionEnd">End Time</label><input id="sessionEnd" name="session_end_time" type="time" value="<?= htmlspecialchars(substr((string) $selectedEvent['end_time'], 0, 5)) ?>" required></div><div class="attendance-field"><label for="attendanceOpen">Attendance Opens</label><input id="attendanceOpen" name="attendance_open_at" type="datetime-local" value="<?= htmlspecialchars((string) $selectedEvent['event_date'] . 'T' . substr((string) $selectedEvent['start_time'], 0, 5)) ?>" required></div><div class="attendance-field"><label for="attendanceDeadline">Attendance Deadline</label><input id="attendanceDeadline" name="attendance_deadline" type="datetime-local" value="" required></div><div class="attendance-field"><label for="attendanceMethod">Method</label><select id="attendanceMethod" name="attendance_method"><option>QR Code</option><option>Attendance Code</option><option>QR + Attendance Code</option></select></div></div><div class="attendance-setup-actions"><button class="attendance-primary-action" type="submit"><i class="fas fa-plus"></i> Create Attendance Session</button><span id="formMessage" role="status"></span></div></form></section><?php if ($session): ?>
<section class="attendance-stat-grid" aria-label="Attendance summary"><?php foreach ([['total_eligible','Eligible','fa-users','neutral'],['present','Present','fa-circle-check','success'],['absent','Absent','fa-circle-xmark','danger'],['not_marked','Not Marked','fa-minus-circle','muted']] as [$key,$label,$icon,$tone]): ?><article class="attendance-stat-card tone-<?= $tone ?>"><span class="attendance-stat-icon"><i class="fas <?= $icon ?>"></i></span><div><strong><?= (int) ($summary[$key] ?? 0) ?></strong><span><?= $label ?></span></div></article><?php endforeach; ?></section>
<section class="attendance-roster-panel"><div class="attendance-roster-toolbar"><div><span class="attendance-eyebrow">Session roster</span><h2>Approved participants</h2></div><div class="attendance-tools"><label class="attendance-search"><i class="fas fa-search"></i><input id="attendanceSearch" type="search" placeholder="Search student or ID" aria-label="Search student or ID"></label><select id="attendanceFilter" aria-label="Filter attendance status"><option>All</option><option>Present</option><option>Absent</option><option>Late</option><option>Not Marked</option></select></div></div><div class="attendance-table-wrap"><table class="attendance-table"><thead><tr><th>Student name</th><th>Student ID</th><th>Check-in time</th><th>Status</th><th>Action</th></tr></thead><tbody id="attendanceRosterBody"><?php foreach ($roster as $record): ?><tr data-search="<?= htmlspecialchars(strtolower((string) $record['full_name'] . ' ' . (string) $record['student_id']), ENT_QUOTES) ?>" data-status="<?= htmlspecialchars((string) $record['attendance_status'], ENT_QUOTES) ?>"><td><strong><?= htmlspecialchars((string) $record['full_name']) ?></strong></td><td class="attendance-muted-cell"><?= htmlspecialchars((string) $record['student_id']) ?></td><td class="attendance-muted-cell"><?= $record['marked_at'] ? htmlspecialchars(date('g:i A', strtotime((string) $record['marked_at']))) : '-' ?></td><td><span class="attendance-record-badge record-<?= strtolower(str_replace(' ', '-', (string) $record['attendance_status'])) ?>"><i class="fas fa-circle"></i><?= htmlspecialchars((string) $record['attendance_status']) ?></span></td><td><select class="attendance-row-action" data-record-id="<?= (int) $record['id'] ?>" <?= $sessionStatus !== 'Open' ? 'disabled' : '' ?> aria-label="Change attendance for <?= htmlspecialchars((string) $record['full_name']) ?>"><?php foreach (['Not Marked','Present','Late','Absent'] as $status): ?><option value="<?= $status ?>" <?= $record['attendance_status'] === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?></tbody></table></div><p class="attendance-no-results" id="attendanceNoResults" hidden>No participants match the current search or filter.</p></section><?php endif; ?>
<?php endif; ?><?php endif; ?></main>
<script>
const obsoleteStatus = String.fromCharCode(76, 97, 116, 101);
document.querySelectorAll('#attendanceTracker option').forEach((option) => { if (option.textContent.trim() === obsoleteStatus) option.remove(); });
document.querySelectorAll('#attendanceTracker .attendance-session-card small').forEach((element) => { element.textContent = element.textContent.replace(new RegExp(obsoleteStatus + '\\s+\\d+\\s*·\\s*', 'g'), ''); });
document.querySelectorAll('#attendanceTracker .attendance-table tbody tr').forEach((row) => {
	if (row.dataset.status === 'Absent' || row.dataset.status === 'Not Marked') row.cells[2].textContent = '-';
});
const studentLoginLink = document.querySelector('#attendanceTracker .attendance-access-panel .attendance-open-student');
if (studentLoginLink) {
	const sessionUrl = new URL(studentLoginLink.href, window.location.origin);
	const sessionToken = new URLSearchParams(sessionUrl.search).get('session');
	if (sessionToken) studentLoginLink.href = '<?= htmlspecialchars(BASE_URL . '/login/login.php', ENT_QUOTES) ?>?attendance=' + encodeURIComponent(sessionToken);
	studentLoginLink.innerHTML = '<i class="fas fa-external-link-alt"></i> Open Student Login';
}
document.querySelector('[data-attendance-action="finalize"]')?.addEventListener('click', async (event) => {
	if (!confirm('Finalize attendance? Not Marked records will become Absent and cannot be edited.')) return;
	const page = document.getElementById('attendanceTracker');
	event.currentTarget.disabled = true;
	try {
		const response = await fetch('<?= htmlspecialchars(BASE_URL . '/modules/cocurricular/pages/attendance-process.php', ENT_QUOTES) ?>', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: new URLSearchParams({ action: 'finalize', event_id: page.dataset.eventId, attendance_id: page.dataset.attendanceId }) });
		const result = await response.json();
		if (!response.ok || !result.success) throw new Error(result.message || 'Unable to finalize attendance.');
		window.location.reload();
	} catch (error) { alert(error.message); event.currentTarget.disabled = false; }
});
(() => { const page = document.getElementById('attendanceTracker'); const endpoint = '<?= htmlspecialchars(BASE_URL . '/modules/cocurricular/pages/attendance-process.php', ENT_QUOTES) ?>'; const eventId = () => page?.dataset.eventId || ''; const attendanceId = () => page?.dataset.attendanceId || ''; const process = async (payload) => { payload.event_id = eventId(); payload.attendance_id = attendanceId(); const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: new URLSearchParams(payload) }); const result = await response.json(); if (!response.ok || !result.success) throw new Error(result.message || result.status || 'Attendance action failed.'); return result; }; const club = document.getElementById('clubSelect'); const event = document.getElementById('eventSelect'); club?.addEventListener('change', () => { window.location.href = '?club_id=' + encodeURIComponent(club.value); }); event?.addEventListener('change', () => { window.location.href = '?club_id=' + encodeURIComponent(club.value) + '&event_id=' + encodeURIComponent(event.value); }); const form = document.getElementById('attendanceCreateForm'); form?.addEventListener('submit', async (submitEvent) => { submitEvent.preventDefault(); const button = form.querySelector('button'); const data = Object.fromEntries(new FormData(form).entries()); data.action = 'create'; data.event_id = eventId(); data.club_id = '<?= (int) ($selectedEvent['club_id'] ?? 0) ?>'; button.disabled = true; try { await process(data); window.location.reload(); } catch (error) { const target = document.getElementById('formMessage'); target.textContent = error.message; target.className = 'is-error'; button.disabled = false; } }); document.querySelector('[data-attendance-action="close"]')?.addEventListener('click', async (closeEvent) => { if (!confirm('Close attendance? Closed records cannot be edited.')) return; closeEvent.currentTarget.disabled = true; try { await process({ action: 'close' }); window.location.reload(); } catch (error) { alert(error.message); closeEvent.currentTarget.disabled = false; } }); const search = document.getElementById('attendanceSearch'); const filter = document.getElementById('attendanceFilter'); const rows = [...document.querySelectorAll('#attendanceRosterBody tr')]; const update = () => { const term = (search?.value || '').toLowerCase(); const status = filter?.value || 'All'; let visible = 0; rows.forEach((row) => { const show = row.dataset.search.includes(term) && (status === 'All' || row.dataset.status === status); row.hidden = !show; if (show) visible++; }); const empty = document.getElementById('attendanceNoResults'); if (empty) empty.hidden = visible > 0; }; search?.addEventListener('input', update); filter?.addEventListener('change', update); document.querySelectorAll('.attendance-row-action').forEach((select) => select.addEventListener('change', async () => { select.disabled = true; try { await process({ action: 'mark', record_id: select.dataset.recordId, attendance_status: select.value }); window.location.reload(); } catch (error) { alert(error.message); select.disabled = false; } })); })();
	const createAttendanceForm = document.getElementById('attendanceCreateForm');
	if (createAttendanceForm) {
		const dateInput = document.getElementById('sessionDate');
		const startInput = document.getElementById('sessionStart');
		const endInput = document.getElementById('sessionEnd');
		const opensInput = document.getElementById('attendanceOpen');
		const deadlineInput = document.getElementById('attendanceDeadline');
		const formMessage = document.getElementById('formMessage');
		deadlineInput.value = '';
		let previousDate = dateInput.value;
		let previousStart = startInput.value;
		let previousEnd = endInput.value;
		let previousOpens = opensInput.value;
		let previousDeadline = deadlineInput.value;
		const syncWindow = () => {
			const dateChanged = dateInput.value !== previousDate;
			const startChanged = startInput.value !== previousStart;
			const endChanged = endInput.value !== previousEnd;
			const nextOpens = dateInput.value && startInput.value ? dateInput.value + 'T' + startInput.value : '';
			const nextDeadline = dateInput.value && endInput.value ? dateInput.value + 'T' + endInput.value : '';
			if ((dateChanged || startChanged) && opensInput.value === previousOpens) opensInput.value = nextOpens;
			if ((dateChanged || endChanged) && deadlineInput.value === previousDeadline) deadlineInput.value = nextDeadline;
			previousDate = dateInput.value; previousStart = startInput.value; previousEnd = endInput.value;
			previousOpens = opensInput.value; previousDeadline = deadlineInput.value;
		};
		[dateInput, startInput, endInput].forEach((input) => input.addEventListener('change', syncWindow));
		createAttendanceForm.addEventListener('submit', (event) => {
			if (startInput.value >= endInput.value) {
				event.preventDefault(); event.stopImmediatePropagation();
				formMessage.textContent = 'Session start time must be before the end time.';
				formMessage.className = 'is-error';
			} else if (!opensInput.value || !deadlineInput.value || opensInput.value >= deadlineInput.value) {
				event.preventDefault(); event.stopImmediatePropagation();
				formMessage.textContent = 'Attendance deadline must be after the attendance opening time.';
				formMessage.className = 'is-error';
			} else {
				event.preventDefault(); event.stopImmediatePropagation();
				const data = Object.fromEntries(new FormData(createAttendanceForm).entries());
				data.action = 'create'; data.event_id = '<?= $eventId ?>'; data.club_id = '<?= (int) ($selectedEvent['club_id'] ?? 0) ?>';
				const button = createAttendanceForm.querySelector('button'); button.disabled = true; formMessage.textContent = '';
				fetch(createAttendanceForm.dataset.endpoint, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: new URLSearchParams(data) })
					.then(async (response) => { const result = await response.json(); if (!response.ok || !result.success) throw new Error(result.message || 'Unable to create attendance session.'); return result; })
					.then((result) => { const sessionId = result.session?.id || result.id; window.location.href = '?club_id=<?= $clubId ?>&event_id=<?= $eventId ?>&attendance_id=' + encodeURIComponent(sessionId) + '&created=1'; })
					.catch((error) => { formMessage.textContent = error.message; formMessage.className = 'is-error'; button.disabled = false; });
			}
		}, true);
	}

	const qrCanvas = document.getElementById('attendanceQr');
	if (qrCanvas && typeof QRCode !== 'undefined') { QRCode.toCanvas(qrCanvas, <?= json_encode($studentAttendanceUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, { width: 150, margin: 2, errorCorrectionLevel: 'M', color: { dark: '#0f172a', light: '#ffffff' } }); }
	document.getElementById('copyAttendanceCode')?.addEventListener('click', async (event) => { const code = document.getElementById('attendanceCode')?.textContent?.trim() || ''; const message = document.getElementById('attendanceCopyMessage'); try { await navigator.clipboard.writeText(code); message.textContent = 'Code copied.'; } catch (error) { message.textContent = 'Copy unavailable. Select the code manually.'; } event.currentTarget.blur(); });
</script>
<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
