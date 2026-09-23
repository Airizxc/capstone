<?php
/**
 * Co-Curricular Module — Central Automatic Notification Triggers
 * Encapsulates notification creation and FCM dispatching for membership applications,
 * announcements, event publications, and participation reviews.
 */
declare(strict_types=1);

require_once __DIR__ . '/cocurricular-db.php';
require_once __DIR__ . '/cocurricular-notifications.php';
require_once __DIR__ . '/cocurricular-fcm-dispatcher.php';

/**
 * Helper to check whether a specific notification type and related_id has already been triggered for a user.
 */
function cocurricularHasExistingTriggerNotification(string $type, int $relatedId, int $userId): bool
{
    $pdo = cocurricularDb();
    if (!$pdo || $relatedId <= 0 || $userId <= 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT id
            FROM cocurricular_notifications
            WHERE type = :type AND related_id = :related_id AND user_id = :user_id
            LIMIT 1
        ');
        $stmt->execute([
            ':type' => $type,
            ':related_id' => $relatedId,
            ':user_id' => $userId,
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('cocurricularHasExistingTriggerNotification error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Trigger: Membership Application Approved.
 */
function cocurricularNotifyMembershipApproved(int $applicationId): bool
{
    if ($applicationId <= 0) {
        return false;
    }

    $application = cocurricularGetMembershipApplication($applicationId);
    if (!$application || (string) ($application['status'] ?? '') !== 'Approved') {
        return false;
    }

    $userId = (int) ($application['user_id'] ?? 0);
    $clubName = (string) ($application['club_name'] ?? 'Club');
    if ($userId <= 0) {
        return false;
    }

    // Duplicate check
    if (cocurricularHasExistingTriggerNotification('membership_approved', $applicationId, $userId)) {
        return true;
    }

    $notifId = cocurricularCreateNotification([
        'user_id' => $userId,
        'title' => 'Membership Approved',
        'message' => "Your application to join {$clubName} has been approved.",
        'type' => 'membership_approved',
        'related_id' => $applicationId,
        'destination_url' => '/modules/cocurricular/pages/student-club-membership.php',
    ]);

    if ($notifId) {
        @cocurricularSendStoredNotification($notifId);
        return true;
    }

    return false;
}

/**
 * Trigger: Membership Application Rejected.
 */
function cocurricularNotifyMembershipRejected(int $applicationId): bool
{
    if ($applicationId <= 0) {
        return false;
    }

    $application = cocurricularGetMembershipApplication($applicationId);
    if (!$application || (string) ($application['status'] ?? '') !== 'Rejected') {
        return false;
    }

    $userId = (int) ($application['user_id'] ?? 0);
    $clubName = (string) ($application['club_name'] ?? 'Club');
    if ($userId <= 0) {
        return false;
    }

    if (cocurricularHasExistingTriggerNotification('membership_rejected', $applicationId, $userId)) {
        return true;
    }

    $notifId = cocurricularCreateNotification([
        'user_id' => $userId,
        'title' => 'Membership Application Update',
        'message' => "Your application to join {$clubName} was not approved.",
        'type' => 'membership_rejected',
        'related_id' => $applicationId,
        'destination_url' => '/modules/cocurricular/pages/student-club-membership.php',
    ]);

    if ($notifId) {
        @cocurricularSendStoredNotification($notifId);
        return true;
    }

    return false;
}

/**
 * Trigger: New Announcement Published to Club Members.
 */
function cocurricularNotifyAnnouncementPublished(int $announcementId): bool
{
    if ($announcementId <= 0) {
        return false;
    }

    $announcement = cocurricularGetAnnouncementById($announcementId);
    if (!$announcement) {
        return false;
    }

    $clubId = (int) ($announcement['club_id'] ?? 0);
    $announcementTitle = (string) ($announcement['title'] ?? 'Announcement');
    if ($clubId <= 0) {
        return false;
    }

    $club = cocurricularGetClubById($clubId);
    $clubName = (string) ($club['club_name'] ?? 'Club');

    // Fetch approved members eligible to receive announcements
    $approvedMembers = cocurricularGetClubApprovedMembers($clubId);
    if (empty($approvedMembers)) {
        return true;
    }

    $destinationUrl = "/modules/cocurricular/pages/my-club.php?club_id={$clubId}&announcement_id={$announcementId}#announcements";
    $createdCount = 0;

    error_log(sprintf(
        "[Co-Curricular Announcement Notification] Announcement ID: %d, Club ID: %d, Title: '%s', Approved Members: %d",
        $announcementId,
        $clubId,
        $announcementTitle,
        count($approvedMembers)
    ));

    foreach ($approvedMembers as $member) {
        $userId = (int) ($member['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        if (cocurricularHasExistingTriggerNotification('announcement_new', $announcementId, $userId)) {
            continue;
        }

        $notifId = cocurricularCreateNotification([
            'user_id' => $userId,
            'title' => 'New Club Announcement',
            'message' => "{$clubName} posted a new announcement: {$announcementTitle}",
            'type' => 'announcement_new',
            'related_id' => $announcementId,
            'destination_url' => $destinationUrl,
        ]);

        if ($notifId) {
            $createdCount++;
            cocurricularSendStoredNotification($notifId);
        }
    }

    return true;
}

/**
 * Trigger: New Event Published to Club Members.
 */
function cocurricularNotifyEventPublished(int $eventId): bool
{
    if ($eventId <= 0) {
        return false;
    }

    $event = cocurricularGetEventById($eventId);
    if (!$event || (string) ($event['status'] ?? '') !== 'Published') {
        return false;
    }

    $clubId = (int) ($event['club_id'] ?? 0);
    $eventTitle = (string) ($event['title'] ?? 'Event');
    if ($clubId <= 0) {
        return false;
    }

    $club = cocurricularGetClubById($clubId);
    $clubName = (string) ($club['club_name'] ?? 'Club');

    $approvedMembers = cocurricularGetClubApprovedMembers($clubId);
    if (empty($approvedMembers)) {
        return true;
    }

    $destinationUrl = "/modules/cocurricular/pages/my-club.php?club_id={$clubId}#events";

    foreach ($approvedMembers as $member) {
        $userId = (int) ($member['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        if (cocurricularHasExistingTriggerNotification('event_new', $eventId, $userId)) {
            continue;
        }

        $notifId = cocurricularCreateNotification([
            'user_id' => $userId,
            'title' => 'New Club Event',
            'message' => "{$clubName} published a new event: {$eventTitle}",
            'type' => 'event_new',
            'related_id' => $eventId,
            'destination_url' => $destinationUrl,
        ]);

        if ($notifId) {
            @cocurricularSendStoredNotification($notifId);
        }
    }

    return true;
}

/**
 * Trigger: Event Participation Request Approved.
 */
function cocurricularNotifyParticipationApproved(int $participantId): bool
{
    if ($participantId <= 0) {
        return false;
    }

    $participant = cocurricularFetchEventParticipant($participantId);
    if (!$participant || (string) ($participant['status'] ?? '') !== 'Approved') {
        return false;
    }

    $userId = (int) ($participant['user_id'] ?? 0);
    $eventId = (int) ($participant['event_id'] ?? 0);
    $clubId = (int) ($participant['club_id'] ?? 0);
    $eventTitle = (string) ($participant['event_title'] ?? $participant['title'] ?? 'Event');

    if ($userId <= 0) {
        return false;
    }

    if (cocurricularHasExistingTriggerNotification('participation_approved', $participantId, $userId)) {
        return true;
    }

    $destinationUrl = "/modules/cocurricular/pages/my-club.php?club_id={$clubId}#events";

    $notifId = cocurricularCreateNotification([
        'user_id' => $userId,
        'title' => 'Event Registration Approved',
        'message' => "Your participation request for {$eventTitle} has been approved.",
        'type' => 'participation_approved',
        'related_id' => $participantId,
        'destination_url' => $destinationUrl,
    ]);

    if ($notifId) {
        @cocurricularSendStoredNotification($notifId);
        return true;
    }

    return false;
}

/**
 * Trigger: Event Participation Request Rejected.
 */
function cocurricularNotifyParticipationRejected(int $participantId, string $rejectionNote = ''): bool
{
    if ($participantId <= 0) {
        return false;
    }

    $participant = cocurricularFetchEventParticipant($participantId);
    if (!$participant || (string) ($participant['status'] ?? '') !== 'Rejected') {
        return false;
    }

    $userId = (int) ($participant['user_id'] ?? 0);
    $clubId = (int) ($participant['club_id'] ?? 0);
    $eventTitle = (string) ($participant['event_title'] ?? $participant['title'] ?? 'Event');

    if ($userId <= 0) {
        return false;
    }

    if (cocurricularHasExistingTriggerNotification('participation_rejected', $participantId, $userId)) {
        return true;
    }

    $note = trim($rejectionNote ?: (string) ($participant['rejection_note'] ?? ''));
    $message = "Your participation request for {$eventTitle} was not approved.";
    if ($note !== '') {
        $message .= " Reason: {$note}";
    }

    $destinationUrl = "/modules/cocurricular/pages/my-club.php?club_id={$clubId}#events";

    $notifId = cocurricularCreateNotification([
        'user_id' => $userId,
        'title' => 'Event Registration Update',
        'message' => $message,
        'type' => 'participation_rejected',
        'related_id' => $participantId,
        'destination_url' => $destinationUrl,
    ]);

    if ($notifId) {
        @cocurricularSendStoredNotification($notifId);
        return true;
    }

    return false;
}

/**
 * Trigger: Attendance Session Opened.
 */
function cocurricularNotifyAttendanceOpen(int $attendanceId): bool
{
    if ($attendanceId <= 0) {
        return false;
    }

    $session = cocurricularGetAttendanceSession($attendanceId);
    if (!$session || (string) ($session['status'] ?? '') !== 'Open') {
        return false;
    }

    $eventId = (int) ($session['event_id'] ?? 0);
    $event = cocurricularGetEventById($eventId);
    $eventTitle = (string) ($event['title'] ?? 'Event');
    $clubId = (int) ($event['club_id'] ?? 0);

    $roster = cocurricularFetchAttendanceRoster($attendanceId);
    if (empty($roster)) {
        return true;
    }

    $destinationUrl = "/modules/cocurricular/pages/my-club.php?club_id={$clubId}#events";

    foreach ($roster as $row) {
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        if (cocurricularHasExistingTriggerNotification('attendance_open', $attendanceId, $userId)) {
            continue;
        }

        $notifId = cocurricularCreateNotification([
            'user_id' => $userId,
            'title' => 'Attendance is Now Open',
            'message' => "Attendance for {$eventTitle} is now open. You may submit your attendance using the available attendance method.",
            'type' => 'attendance_open',
            'related_id' => $attendanceId,
            'destination_url' => $destinationUrl,
        ]);

        if ($notifId) {
            @cocurricularSendStoredNotification($notifId);
        }
    }

    return true;
}

/**
 * Trigger: Attendance Session Closed.
 */
function cocurricularNotifyAttendanceClosed(int $attendanceId): bool
{
    if ($attendanceId <= 0) {
        return false;
    }

    $session = cocurricularGetAttendanceSession($attendanceId);
    if (!$session || (string) ($session['status'] ?? '') !== 'Closed') {
        return false;
    }

    $eventId = (int) ($session['event_id'] ?? 0);
    $event = cocurricularGetEventById($eventId);
    $eventTitle = (string) ($event['title'] ?? 'Event');
    $clubId = (int) ($event['club_id'] ?? 0);

    $roster = cocurricularFetchAttendanceRoster($attendanceId);
    if (empty($roster)) {
        return true;
    }

    $destinationUrl = "/modules/cocurricular/pages/my-club.php?club_id={$clubId}#events";

    foreach ($roster as $row) {
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        if (cocurricularHasExistingTriggerNotification('attendance_closed', $attendanceId, $userId)) {
            continue;
        }

        $notifId = cocurricularCreateNotification([
            'user_id' => $userId,
            'title' => 'Attendance Session Closed',
            'message' => "Attendance for {$eventTitle} is now closed.",
            'type' => 'attendance_closed',
            'related_id' => $attendanceId,
            'destination_url' => $destinationUrl,
        ]);

        if ($notifId) {
            @cocurricularSendStoredNotification($notifId);
        }
    }

    return true;
}

/**
 * Scheduled Processor: Send attendance reminders and deadline reminders to eligible students who are Not Marked.
 */
function cocurricularProcessAttendanceReminders(?int $reminderMinutesBeforeDeadline = null): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return ['success' => false, 'inspected_sessions' => 0, 'reminders_sent' => 0, 'deadline_reminders_sent' => 0];
    }

    $reminderMinutes = $reminderMinutesBeforeDeadline;
    if ($reminderMinutes === null || $reminderMinutes <= 0) {
        $envVal = defined('COCURRICULAR_ATTENDANCE_REMINDER_MINUTES') ? (int) COCURRICULAR_ATTENDANCE_REMINDER_MINUTES : 30;
        $reminderMinutes = $envVal > 0 ? $envVal : 30;
    }

    // Auto-close expired sessions first
    $pdo->exec('UPDATE club_event_attendance SET status = "Closed", closed_at = COALESCE(closed_at, NOW()), updated_at = NOW() WHERE status = "Open" AND attendance_deadline IS NOT NULL AND NOW() >= attendance_deadline');

    $stmt = $pdo->query('
        SELECT a.*, e.title AS event_title, e.club_id
        FROM club_event_attendance a
        INNER JOIN club_events e ON e.id = a.event_id
        WHERE a.status = "Open" AND (a.attendance_deadline IS NULL OR NOW() < a.attendance_deadline)
    ');

    $sessions = $stmt->fetchAll() ?: [];
    $inspectedCount = count($sessions);
    $remindersSent = 0;
    $deadlineRemindersSent = 0;

    $now = new DateTimeImmutable('now');

    foreach ($sessions as $session) {
        $attendanceId = (int) $session['id'];
        $clubId = (int) ($session['club_id'] ?? 0);
        $eventTitle = (string) ($session['event_title'] ?? 'Event');
        $destinationUrl = "/modules/cocurricular/pages/my-club.php?club_id={$clubId}#events";

        $deadlineStr = trim((string) ($session['attendance_deadline'] ?? ''));
        $isDeadlineApproaching = false;

        if ($deadlineStr !== '') {
            try {
                $deadlineTime = new DateTimeImmutable($deadlineStr);
                $windowStartTime = $deadlineTime->modify("-{$reminderMinutes} minutes");
                if ($now >= $windowStartTime && $now < $deadlineTime) {
                    $isDeadlineApproaching = true;
                }
            } catch (Throwable $t) {
                $isDeadlineApproaching = false;
            }
        }

        // Target ONLY approved roster records where status is still 'Not Marked'
        $notMarkedStmt = $pdo->prepare('
            SELECT ar.user_id
            FROM club_event_attendance_records ar
            WHERE ar.attendance_id = :attendance_id AND ar.attendance_status = "Not Marked"
        ');
        $notMarkedStmt->execute([':attendance_id' => $attendanceId]);
        $unmarkedRows = $notMarkedStmt->fetchAll() ?: [];

        foreach ($unmarkedRows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            // General Attendance Reminder
            if (!cocurricularHasExistingTriggerNotification('attendance_reminder', $attendanceId, $userId)) {
                $notifId = cocurricularCreateNotification([
                    'user_id' => $userId,
                    'title' => 'Attendance Reminder',
                    'message' => "Attendance for {$eventTitle} is still open. Please submit your attendance before the deadline.",
                    'type' => 'attendance_reminder',
                    'related_id' => $attendanceId,
                    'destination_url' => $destinationUrl,
                ]);

                if ($notifId) {
                    @cocurricularSendStoredNotification($notifId);
                    $remindersSent++;
                }
            }

            // Deadline Approaching Reminder
            if ($isDeadlineApproaching && !cocurricularHasExistingTriggerNotification('attendance_deadline', $attendanceId, $userId)) {
                $notifId = cocurricularCreateNotification([
                    'user_id' => $userId,
                    'title' => 'Attendance Deadline Approaching',
                    'message' => "Attendance for {$eventTitle} is closing soon! Please submit your attendance immediately.",
                    'type' => 'attendance_reminder',
                    'related_id' => $attendanceId,
                    'destination_url' => $destinationUrl,
                ]);

                if ($notifId) {
                    @cocurricularSendStoredNotification($notifId);
                    $deadlineRemindersSent++;
                }
            }
        }
    }

    return [
        'success' => true,
        'inspected_sessions' => $inspectedCount,
        'reminders_sent' => $remindersSent,
        'deadline_reminders_sent' => $deadlineRemindersSent,
    ];
}
