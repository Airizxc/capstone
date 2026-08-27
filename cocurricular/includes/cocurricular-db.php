<?php
/**
 * Co-Curricular Module Data Access
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../../../includes/security.php';

function getCocurricularConnection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=cocurricular_db;charset=' . DB_CHARSET;

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException('Co-Curricular database unavailable. Import modules/cocurricular/database/cocurricular.sql and ensure cocurricular_db exists.');
    }

    return $pdo;
}

function cocurricularDb(): ?PDO
{
    try {
        return getCocurricularConnection();
    } catch (Throwable $e) {
        return null;
    }
}

function cocurricularStudentProfile(): array
{
    $userId = getCurrentUserId();
    if ($userId === null) {
        return [];
    }

    $pdo = db();
    if (!$pdo) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT id, full_name, email, student_id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return [];
    }

    return [
        'user_id' => (int) $row['id'],
        'full_name' => (string) $row['full_name'],
        'email' => (string) $row['email'],
        'student_id' => (string) $row['student_id'],
        'program' => trim((string) ($_SESSION['student_program'] ?? 'Not set')),
        'year_level' => trim((string) ($_SESSION['student_year_level'] ?? 'Not set')),
    ];
}

function cocurricularGetDirectorySummary(): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return ['total' => 0, 'active' => 0, 'pending' => 0, 'inactive' => 0];
    }

    $counts = ['total' => 0, 'active' => 0, 'pending' => 0, 'inactive' => 0];
    $stmt = $pdo->query('SELECT status, COUNT(*) AS count FROM clubs GROUP BY status');
    foreach ($stmt->fetchAll() as $row) {
        $status = (string) $row['status'];
        $count = (int) $row['count'];
        $counts['total'] += $count;
        if ($status === 'Active') {
            $counts['active'] = $count;
        }
        if ($status === 'Pending') {
            $counts['pending'] = $count;
        }
        if ($status === 'Inactive') {
            $counts['inactive'] = $count;
        }
    }

    return $counts;
}

function cocurricularFetchCategories(): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

    $stmt = $pdo->query('SELECT DISTINCT category FROM clubs ORDER BY category ASC');
    return array_values(array_filter(array_map(static fn($row) => trim((string) ($row['category'] ?? '')), $stmt->fetchAll())));
}

function cocurricularFetchClubs(string $search = '', string $status = '', string $category = ''): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

    $sql = 'SELECT * FROM clubs WHERE 1=1';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (club_name LIKE ? OR category LIKE ? OR description LIKE ? OR adviser LIKE ?)';
        $term = '%' . $search . '%';
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    if (in_array($status, ['Active', 'Pending', 'Inactive'], true)) {
        $sql .= ' AND status = ?';
        $params[] = $status;
    }

    if ($category !== '') {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }

    $sql .= ' ORDER BY FIELD(status, "Active", "Pending", "Inactive"), club_name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function cocurricularGetClubById(int $clubId): ?array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM clubs WHERE id = ? LIMIT 1');
    $stmt->execute([$clubId]);
    $club = $stmt->fetch();
    return $club ?: null;
}

function cocurricularFetchClubOfficers(int $clubId): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT officer_name, position FROM club_officers WHERE club_id = ? ORDER BY position ASC, officer_name ASC');
    $stmt->execute([$clubId]);
    return $stmt->fetchAll();
}

function cocurricularCountClubMembers(int $clubId): int
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return 0;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM club_membership_applications WHERE club_id = ? AND status = 'Approved'");
    $stmt->execute([$clubId]);
    return (int) $stmt->fetchColumn();
}

function cocurricularFetchStudentMemberships(int $userId): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

    $sql = 'SELECT a.*, c.club_name, c.category, c.status AS club_status
            FROM club_membership_applications a
            LEFT JOIN clubs c ON c.id = a.club_id
            WHERE a.user_id = ?
            ORDER BY a.submitted_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function cocurricularFetchLatestStudentMembershipForClub(int $clubId, int $userId): ?array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return null;
    }

    $sql = 'SELECT a.*, c.club_name, c.category
            FROM club_membership_applications a
            LEFT JOIN clubs c ON c.id = a.club_id
            WHERE a.club_id = ?
              AND a.user_id = ?
            ORDER BY a.submitted_at DESC
            LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$clubId, $userId]);
    $membership = $stmt->fetch();
    return $membership ?: null;
}

function cocurricularFetchApprovedMembershipForClub(int $clubId, int $userId): ?array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return null;
    }

    $sql = 'SELECT a.*, c.club_name, c.category, c.description, c.adviser, c.adviser_email, c.contact_phone, c.status AS club_status
            FROM club_membership_applications a
            INNER JOIN clubs c ON c.id = a.club_id
            WHERE a.club_id = ?
              AND a.user_id = ?
              AND a.status = "Approved"
            ORDER BY a.reviewed_at DESC, a.submitted_at DESC
            LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$clubId, $userId]);
    $membership = $stmt->fetch();
    return $membership ?: null;
}

function cocurricularHasApprovedMembershipForClub(int $clubId, int $userId): bool
{
    return cocurricularFetchApprovedMembershipForClub($clubId, $userId) !== null;
}

function cocurricularStudentMembershipStatus(int $clubId, int $userId): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return ['pending' => false, 'approved' => false, 'rejected' => false];
    }

    $stmt = $pdo->prepare('SELECT status FROM club_membership_applications WHERE club_id = ? AND user_id = ?');
    $stmt->execute([$clubId, $userId]);

    $status = ['pending' => false, 'approved' => false, 'rejected' => false];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['status'] === 'Pending') {
            $status['pending'] = true;
        }
        if ($row['status'] === 'Approved') {
            $status['approved'] = true;
        }
        if ($row['status'] === 'Rejected') {
            $status['rejected'] = true;
        }
    }

    return $status;
}

function cocurricularCreateMembershipApplication(array $input): bool
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO club_membership_applications
                (club_id, user_id, student_id, reason_for_joining, areas_of_interest, preferred_participation, agreement, status)
             VALUES
                (:club_id, :user_id, :student_id, :reason_for_joining, :areas_of_interest, :preferred_participation, :agreement, :status)'
        );

        return $stmt->execute([
            ':club_id' => $input['club_id'],
            ':user_id' => $input['user_id'],
            ':student_id' => $input['student_id'],
            ':reason_for_joining' => $input['reason_for_joining'],
            ':areas_of_interest' => $input['areas_of_interest'],
            ':preferred_participation' => $input['preferred_participation'],
            ':agreement' => $input['agreement'] ? 1 : 0,
            ':status' => 'Pending',
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function cocurricularGetClubAnnouncements(int $clubId): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

    $sql = 'SELECT *
            FROM club_announcements
            WHERE club_id = ?
            ORDER BY is_pinned DESC, posted_at DESC, created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$clubId]);
    return $stmt->fetchAll();
}

function cocurricularGetLatestClubAnnouncements(int $clubId, int $limit = 3): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

    $limit = max(1, (int) $limit);
    $sql = 'SELECT *
            FROM club_announcements
            WHERE club_id = ?
            ORDER BY is_pinned DESC, posted_at DESC, created_at DESC
            LIMIT ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$clubId, $limit]);
    return $stmt->fetchAll();
}

function cocurricularGetAnnouncementsForManagement(): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

    $sql = 'SELECT a.*, c.club_name
            FROM club_announcements a
            INNER JOIN clubs c ON c.id = a.club_id
            ORDER BY a.is_pinned DESC, a.posted_at DESC, a.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll();
}

function cocurricularGetAnnouncementById(int $announcementId): ?array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM club_announcements WHERE id = ? LIMIT 1');
    $stmt->execute([$announcementId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function cocurricularCreateClubAnnouncement(array $input): bool
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return false;
    }

    $clubId = (int) ($input['club_id'] ?? 0);
    $title = trim((string) ($input['title'] ?? ''));
    $content = trim((string) ($input['content'] ?? ''));
    $postedAt = trim((string) ($input['posted_at'] ?? ''));
    $isPinned = !empty($input['is_pinned']) ? 1 : 0;
    $attachmentPath = trim((string) ($input['attachment_path'] ?? ''));

    if ($clubId <= 0 || $title === '' || $content === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO club_announcements
                (club_id, title, content, posted_at, is_pinned, attachment_path)
             VALUES
                (:club_id, :title, :content, :posted_at, :is_pinned, :attachment_path)'
        );
        return $stmt->execute([
            ':club_id' => $clubId,
            ':title' => $title,
            ':content' => $content,
            ':posted_at' => $postedAt !== '' ? $postedAt : date('Y-m-d H:i:s'),
            ':is_pinned' => $isPinned,
            ':attachment_path' => $attachmentPath !== '' ? $attachmentPath : null,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function cocurricularUpdateClubAnnouncement(int $announcementId, array $input): bool
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return false;
    }

    $title = trim((string) ($input['title'] ?? ''));
    $content = trim((string) ($input['content'] ?? ''));
    $postedAt = trim((string) ($input['posted_at'] ?? ''));
    $isPinned = !empty($input['is_pinned']) ? 1 : 0;
    $attachmentPath = trim((string) ($input['attachment_path'] ?? ''));

    if ($announcementId <= 0 || $title === '' || $content === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE club_announcements
             SET title = :title,
                 content = :content,
                 posted_at = :posted_at,
                 is_pinned = :is_pinned,
                 attachment_path = :attachment_path,
                 updated_at = NOW()
             WHERE id = :id'
        );
        return $stmt->execute([
            ':title' => $title,
            ':content' => $content,
            ':posted_at' => $postedAt !== '' ? $postedAt : date('Y-m-d H:i:s'),
            ':is_pinned' => $isPinned,
            ':attachment_path' => $attachmentPath !== '' ? $attachmentPath : null,
            ':id' => $announcementId,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function cocurricularDeleteClubAnnouncement(int $announcementId): bool
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM club_announcements WHERE id = ?');
        return $stmt->execute([$announcementId]);
    } catch (Throwable $e) {
        return false;
    }
}

function cocurricularNormalizeText(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value));
}

function cocurricularGetEventSummaryCounts(): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return ['total' => 0, 'upcoming' => 0, 'published' => 0, 'draft' => 0, 'completed' => 0];
    }

    $stats = ['total' => 0, 'upcoming' => 0, 'published' => 0, 'draft' => 0, 'completed' => 0];

    try {
        $stmt = $pdo->query("SELECT status, COUNT(*) AS count FROM club_events GROUP BY status");
        foreach ($stmt->fetchAll() as $row) {
            $status = (string) ($row['status'] ?? '');
            $count = (int) ($row['count'] ?? 0);
            $stats['total'] += $count;
            if ($status === 'Published') {
                $stats['published'] = $count;
            }
            if ($status === 'Draft') {
                $stats['draft'] = $count;
            }
            if ($status === 'Completed') {
                $stats['completed'] = $count;
            }
        }

        $upcomingStmt = $pdo->query("SELECT COUNT(*) FROM club_events WHERE status = 'Published' AND event_date >= CURDATE()");
        $stats['upcoming'] = (int) $upcomingStmt->fetchColumn();
    } catch (Throwable $e) {
        return ['total' => 0, 'upcoming' => 0, 'published' => 0, 'draft' => 0, 'completed' => 0];
    }

    return $stats;
}

function cocurricularFetchEventsForManagement(string $search = '', int $clubId = 0, string $status = ''): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

    $sql = 'SELECT e.*, c.club_name
            FROM club_events e
            INNER JOIN clubs c ON c.id = e.club_id
            WHERE 1=1';
    $params = [];

    if ($search !== '') {
        $term = '%' . $search . '%';
        $sql .= ' AND (e.title LIKE ? OR e.event_type LIKE ? OR e.venue LIKE ? OR c.club_name LIKE ?)';
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    if ($clubId > 0) {
        $sql .= ' AND e.club_id = ?';
        $params[] = $clubId;
    }

    if (in_array($status, ['Draft', 'Published', 'Completed', 'Cancelled'], true)) {
        $sql .= ' AND e.status = ?';
        $params[] = $status;
    }

    $sql .= ' ORDER BY e.event_date DESC, e.start_time DESC, e.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function cocurricularFetchEventParticipants(int $eventId, string $search = '', string $status = ''): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $eventId <= 0) {
        return [];
    }

        $sql = 'SELECT ep.id, p.student_id, p.full_name, ep.registered_at, ep.status,
               ep.reviewed_at, ep.rejection_note, ep.event_id, ep.club_id,
                                     e.title AS event_title, c.club_name,
                                     CASE WHEN NOT EXISTS (
                                             SELECT 1
                                             FROM club_event_participants newer
                                             WHERE newer.event_id = ep.event_id
                                                 AND newer.user_id = ep.user_id
                                                 AND (newer.registered_at > ep.registered_at
                                                            OR (newer.registered_at = ep.registered_at AND newer.id > ep.id))
                                     ) THEN 1 ELSE 0 END AS is_current
            FROM club_event_participants ep
            INNER JOIN sms2_db.users p ON p.id = ep.user_id
            INNER JOIN club_events e ON e.id = ep.event_id
            INNER JOIN clubs c ON c.id = ep.club_id
            WHERE ep.event_id = ?';
    $params = [$eventId];
    $search = trim($search);
    if ($search !== '') {
        $sql .= ' AND (p.full_name LIKE ? OR p.student_id LIKE ?)';
        $term = '%' . $search . '%';
        $params[] = $term;
        $params[] = $term;
    }
    if (in_array($status, ['Pending', 'Approved', 'Rejected'], true)) {
        $sql .= ' AND ep.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY ep.registered_at ASC, p.full_name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function cocurricularCountEventParticipantsByStatus(int $eventId): array
{
    $counts = ['Total' => 0, 'Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
    $pdo = cocurricularDb();
    if (!$pdo || $eventId <= 0) {
        return $counts;
    }

    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS total FROM club_event_participants WHERE event_id = ? GROUP BY status');
    $stmt->execute([$eventId]);
    foreach ($stmt->fetchAll() as $row) {
        $status = (string) $row['status'];
        if (isset($counts[$status])) {
            $counts[$status] = (int) $row['total'];
            $counts['Total'] += (int) $row['total'];
        }
    }
    return $counts;
}

function cocurricularCountEventParticipants(int $eventId): int
{
    $pdo = cocurricularDb();
    if (!$pdo || $eventId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM club_event_participants WHERE event_id = ?');
    $stmt->execute([$eventId]);
    return (int) $stmt->fetchColumn();
}

function cocurricularGetEventById(int $eventId): ?array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM club_events WHERE id = ? LIMIT 1');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    return $event ?: null;
}

function cocurricularFetchPublishedEventsForClub(int $clubId): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT *
         FROM club_events
         WHERE club_id = ?
           AND status = "Published"
         ORDER BY event_date ASC, start_time ASC, created_at DESC'
    );
    $stmt->execute([$clubId]);
    return $stmt->fetchAll();
}

function cocurricularGetAttendanceSessionStatus(
    string $startDate,
    string $startTime,
    string $endDate,
    string $endTime,
    ?DateTimeImmutable $now = null,
    bool $finalized = false
): string {
    if ($finalized) {
        return 'past';
    }

    try {
        $startAt = new DateTimeImmutable(trim($startDate . ' ' . $startTime));
        $endAt = new DateTimeImmutable(trim($endDate . ' ' . $endTime));
    } catch (Throwable $exception) {
        return 'past';
    }

    $current = $now ?? new DateTimeImmutable('now');
    if ($current < $startAt) {
        return 'upcoming';
    }
    if ($current <= $endAt) {
        return 'ongoing';
    }
    return 'past';
}

function cocurricularFetchStudentClubCalendarEvents(int $clubId, int $studentUserId): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $clubId <= 0 || $studentUserId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT e.id, e.title, e.event_date, e.start_time, e.end_time, e.venue, c.club_name,
                EXISTS (
                    SELECT 1 FROM club_event_attendance a WHERE a.event_id = e.id
                ) AS has_attendance_session
         FROM club_events e
         INNER JOIN clubs c ON c.id = e.club_id
         WHERE e.club_id = ? AND e.status = "Published"
         ORDER BY e.event_date ASC, e.start_time ASC, e.id ASC'
    );
    $stmt->execute([$clubId]);
    $now = new DateTimeImmutable('now');
    $events = [];
    foreach ($stmt->fetchAll() as $event) {
        $event['attendance_state'] = cocurricularGetAttendanceSessionStatus(
            (string) $event['event_date'],
            (string) $event['start_time'],
            (string) $event['event_date'],
            (string) $event['end_time'],
            $now
        );
        $events[] = $event;
    }
    return $events;
}

function cocurricularFetchPinnedEventsForClub(int $clubId): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT *
         FROM club_events
         WHERE club_id = ?
           AND status = "Published"
           AND is_pinned = 1
           AND event_date >= CURDATE()
         ORDER BY event_date ASC, start_time ASC, created_at DESC'
    );
    $stmt->execute([$clubId]);
    return $stmt->fetchAll();
}

function cocurricularHasEventInterest(int $eventId, int $userId): bool
{
    $pdo = cocurricularDb();
    if (!$pdo || $eventId <= 0 || $userId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM club_event_participants WHERE event_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$eventId, $userId]);
    return (bool) $stmt->fetchColumn();
}

function cocurricularGetEventParticipationStatus(int $eventId, int $userId): ?array
{
    $pdo = cocurricularDb();
    if (!$pdo || $eventId <= 0 || $userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT status, rejection_note FROM club_event_participants WHERE event_id = ? AND user_id = ? ORDER BY registered_at DESC, id DESC LIMIT 1');
    $stmt->execute([$eventId, $userId]);
    $participation = $stmt->fetch();
    if (!$participation) {
        return null;
    }
    return [
        'status' => (string) $participation['status'],
        'rejection_note' => (string) ($participation['rejection_note'] ?? ''),
    ];
}

function cocurricularRegisterEventInterest(int $eventId, int $userId, int $clubId): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $eventId <= 0 || $userId <= 0 || $clubId <= 0) {
        return ['status' => 'error'];
    }

    try {
        $pdo->beginTransaction();
        $eventStmt = $pdo->prepare(
            'SELECT id, club_id, status, event_date
             FROM club_events
             WHERE id = ? AND club_id = ?
             LIMIT 1'
        );
        $eventStmt->execute([$eventId, $clubId]);
        $event = $eventStmt->fetch();
        if (!$event || (string) $event['status'] !== 'Published' || (string) $event['event_date'] < date('Y-m-d')) {
            $pdo->rollBack();
            return ['status' => 'invalid_event'];
        }

        $latestStmt = $pdo->prepare(
            'SELECT status
             FROM club_event_participants
             WHERE event_id = ? AND user_id = ?
             ORDER BY registered_at DESC, id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $latestStmt->execute([$eventId, $userId]);
        $latestStatus = $latestStmt->fetchColumn();
        if ($latestStatus === 'Pending') {
            $pdo->commit();
            return ['status' => 'pending'];
        }
        if ($latestStatus === 'Approved') {
            $pdo->commit();
            return ['status' => 'approved'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO club_event_participants (event_id, club_id, user_id, status)
             VALUES (:event_id, :club_id, :user_id, "Pending")'
        );
        $stmt->execute([
            ':event_id' => $eventId,
            ':club_id' => (int) $event['club_id'],
            ':user_id' => $userId,
        ]);
        $pdo->commit();
        return ['status' => 'registered'];
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['status' => 'error'];
    }
}

function cocurricularFetchEventParticipant(int $participantId): ?array
{
    $pdo = cocurricularDb();
    if (!$pdo || $participantId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT ep.id, ep.event_id, ep.club_id, ep.user_id, ep.status, ep.registered_at,
                ep.reviewed_at, ep.reviewed_by, ep.rejection_note,
                e.title AS event_title, c.club_name, u.full_name, u.student_id
         FROM club_event_participants ep
         INNER JOIN club_events e ON e.id = ep.event_id
         INNER JOIN clubs c ON c.id = ep.club_id
         INNER JOIN sms2_db.users u ON u.id = ep.user_id
         WHERE ep.id = ?
         LIMIT 1'
    );
    $stmt->execute([$participantId]);
    $participant = $stmt->fetch();
    return $participant ?: null;
}

function cocurricularReviewEventParticipant(int $participantId, int $osaUserId, string $status, string $rejectionNote = ''): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $participantId <= 0 || $osaUserId <= 0 || !in_array($status, ['Approved', 'Rejected'], true)) {
        return ['status' => 'error'];
    }
    if ($status === 'Rejected' && trim($rejectionNote) === '') {
        return ['status' => 'invalid_reason'];
    }

    $participant = cocurricularFetchEventParticipant($participantId);
    if (!$participant || (string) $participant['status'] !== 'Pending') {
        return ['status' => 'invalid_transition'];
    }

    $stmt = $pdo->prepare(
        'UPDATE club_event_participants
         SET status = :status,
             reviewed_at = NOW(),
             reviewed_by = :reviewed_by,
             rejection_note = :rejection_note
         WHERE id = :id AND status = "Pending"'
    );
    $stmt->execute([
        ':status' => $status,
        ':reviewed_by' => $osaUserId,
        ':rejection_note' => $status === 'Rejected' ? trim($rejectionNote) : null,
        ':id' => $participantId,
    ]);

    return $stmt->rowCount() === 1 ? ['status' => strtolower($status)] : ['status' => 'invalid_transition'];
}

function cocurricularCreateClubEvent(array $input): bool
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return false;
    }

    $clubId = (int) ($input['club_id'] ?? 0);
    $title = trim((string) ($input['title'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $eventType = trim((string) ($input['event_type'] ?? ''));
    $eventDate = trim((string) ($input['event_date'] ?? ''));
    $startTime = trim((string) ($input['start_time'] ?? ''));
    $endTime = trim((string) ($input['end_time'] ?? ''));
    $venue = trim((string) ($input['venue'] ?? ''));
    $imagePath = trim((string) ($input['image_path'] ?? ''));
    $isPinned = !empty($input['is_pinned']) ? 1 : 0;
    $status = in_array((string) ($input['status'] ?? 'Draft'), ['Draft', 'Published', 'Completed', 'Cancelled'], true)
        ? (string) $input['status']
        : 'Draft';
    $createdBy = (int) ($input['created_by'] ?? 0);

    if ($clubId <= 0 || $title === '' || $eventType === '' || $eventDate === '' || $startTime === '' || $endTime === '' || $createdBy <= 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO club_events
                (club_id, title, description, event_type, event_date, start_time, end_time, venue, image_path, status, is_pinned, created_by)
             VALUES
                (:club_id, :title, :description, :event_type, :event_date, :start_time, :end_time, :venue, :image_path, :status, :is_pinned, :created_by)'
        );

        return $stmt->execute([
            ':club_id' => $clubId,
            ':title' => $title,
            ':description' => $description,
            ':event_type' => $eventType,
            ':event_date' => $eventDate,
            ':start_time' => $startTime,
            ':end_time' => $endTime,
            ':venue' => $venue !== '' ? $venue : null,
            ':image_path' => $imagePath !== '' ? $imagePath : null,
            ':status' => $status,
            ':is_pinned' => $isPinned,
            ':created_by' => $createdBy,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function cocurricularGetAttendanceSession(int $attendanceId): ?array
{
    $pdo = cocurricularDb();
    if (!$pdo || $attendanceId <= 0) {
        return null;
    }

    $pdo->prepare('UPDATE club_event_attendance SET status = "Closed", closed_at = COALESCE(closed_at, NOW()), updated_at = NOW() WHERE id = ? AND status = "Open" AND attendance_deadline IS NOT NULL AND NOW() >= attendance_deadline')->execute([$attendanceId]);
    $stmt = $pdo->prepare('SELECT * FROM club_event_attendance WHERE id = ? LIMIT 1');
    $stmt->execute([$attendanceId]);
    $session = $stmt->fetch();
    return $session ?: null;
}

function cocurricularFetchAttendanceSessions(int $eventId): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $eventId <= 0) {
        return [];
    }
    $pdo->prepare('UPDATE club_event_attendance SET status = "Closed", closed_at = COALESCE(closed_at, NOW()), updated_at = NOW() WHERE event_id = ? AND status = "Open" AND attendance_deadline IS NOT NULL AND NOW() >= attendance_deadline')->execute([$eventId]);
    $stmt = $pdo->prepare(
        'SELECT a.*,
            (SELECT COUNT(*) FROM club_event_attendance_records r WHERE r.attendance_id = a.id) AS roster_total,
            (SELECT COUNT(*) FROM club_event_attendance_records r WHERE r.attendance_id = a.id AND r.attendance_status = "Present") AS present_count,
            0 AS late_count,
            (SELECT COUNT(*) FROM club_event_attendance_records r WHERE r.attendance_id = a.id AND r.attendance_status = "Not Marked") AS not_marked_count
         FROM club_event_attendance a WHERE a.event_id = ? ORDER BY a.session_date ASC, a.session_start_time ASC, a.id ASC'
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

function cocurricularFetchStudentAttendanceData(int $clubId, int $studentUserId): array
{
    $empty = [
        'summary' => ['rate' => 0, 'present' => 0, 'absent' => 0, 'total' => 0],
        'sessions' => [],
        'available_sessions' => [],
    ];
    $pdo = cocurricularDb();
    if (!$pdo || $clubId <= 0 || $studentUserId <= 0) {
        return $empty;
    }

    $pdo->prepare(
        'UPDATE club_event_attendance a
         INNER JOIN club_events e ON e.id = a.event_id
         SET a.status = "Closed", a.closed_at = COALESCE(a.closed_at, NOW()), a.updated_at = NOW()
         WHERE e.club_id = ? AND a.status = "Open" AND a.attendance_deadline IS NOT NULL AND NOW() >= a.attendance_deadline'
    )->execute([$clubId]);

    $stmt = $pdo->prepare(
        'SELECT a.id AS attendance_id, a.status AS session_status, a.session_date, a.session_start_time,
                a.session_end_time, a.attendance_open_at, a.attendance_deadline, a.finalized_at,
                a.location, a.access_token, e.id AS event_id, e.title, e.event_date, e.start_time,
                e.end_time, e.venue, c.club_name, ar.attendance_status, ar.marked_at,
                ep.id AS approved_participant_id
         FROM club_event_attendance a
         INNER JOIN club_events e ON e.id = a.event_id AND e.status = "Published"
         INNER JOIN clubs c ON c.id = e.club_id
         INNER JOIN club_event_attendance_records ar ON ar.attendance_id = a.id AND ar.user_id = ?
         INNER JOIN club_event_participants ep
           ON ep.id = ar.participant_id AND ep.event_id = e.id AND ep.user_id = ? AND ep.status = "Approved"
         WHERE e.club_id = ?
           AND NOT EXISTS (
               SELECT 1 FROM club_event_participants newer
               WHERE newer.event_id = ep.event_id AND newer.user_id = ep.user_id
                 AND (newer.registered_at > ep.registered_at
                      OR (newer.registered_at = ep.registered_at AND newer.id > ep.id))
           )
         ORDER BY COALESCE(a.session_date, e.event_date) DESC,
                  COALESCE(a.session_start_time, e.start_time) DESC, a.id DESC'
    );
    $stmt->execute([$studentUserId, $studentUserId, $clubId]);

    $sessions = [];
    $availableSessions = [];
    $summary = ['rate' => 0, 'present' => 0, 'absent' => 0, 'total' => 0];
    $now = new DateTimeImmutable('now');
    foreach ($stmt->fetchAll() as $row) {
        $attendanceStatus = (string) ($row['attendance_status'] ?? 'Not Marked');
        $openAt = !empty($row['attendance_open_at']) ? new DateTimeImmutable((string) $row['attendance_open_at']) : null;
        $deadline = !empty($row['attendance_deadline']) ? new DateTimeImmutable((string) $row['attendance_deadline']) : null;
        $sessionStatus = (string) ($row['session_status'] ?? 'Not Started');
        $sessionDate = (string) ($row['session_date'] ?: $row['event_date']);
        $startTime = (string) ($row['session_start_time'] ?: $row['start_time']);
        $endTime = (string) ($row['session_end_time'] ?: $row['end_time']);
        $attendanceState = cocurricularGetAttendanceSessionStatus(
            $sessionDate,
            $startTime,
            $sessionDate,
            $endTime,
            $now,
            !empty($row['finalized_at'])
        );
        $studentStatus = 'Not Available';
        $available = false;

        if ($attendanceStatus === 'Present') {
            $studentStatus = 'Present';
        } elseif ($attendanceStatus === 'Absent') {
            $studentStatus = 'Absent';
        } elseif ($attendanceState === 'upcoming') {
            $studentStatus = 'Upcoming';
        } elseif ($attendanceState === 'ongoing') {
            $studentStatus = 'Attendance Available';
            $available = $sessionStatus === 'Open' && $openAt && $deadline && $now >= $openAt && $now < $deadline;
        } elseif ($sessionStatus === 'Closed' || ($deadline && $now >= $deadline)) {
            $studentStatus = !empty($row['finalized_at']) ? 'Absent' : 'Closed';
        }

        if ($attendanceState === 'past' && $studentStatus === 'Present') {
            $summary['present']++;
        } elseif ($attendanceState === 'past' && $studentStatus === 'Absent') {
            $summary['absent']++;
        }
        if ($attendanceState === 'past' && in_array($studentStatus, ['Present', 'Absent'], true)) {
            $summary['total']++;
        }
        if ($available && !empty($row['access_token'])) {
            $availableSessions[] = [
                'attendance_id' => (int) $row['attendance_id'],
                'event_id' => (int) $row['event_id'],
                'access_token' => (string) $row['access_token'],
            ];
        }
        if (!in_array($attendanceStatus, ['Present', 'Absent'], true)) {
            continue;
        }
        $sessions[] = [
            'attendance_id' => (int) $row['attendance_id'],
            'event_id' => (int) $row['event_id'],
            'club_name' => (string) $row['club_name'],
            'title' => (string) $row['title'],
            'date' => $sessionDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'venue' => (string) ($row['location'] ?: $row['venue'] ?: ''),
            'status' => $studentStatus,
            'attendance_state' => $attendanceState,
            'check_in_time' => $attendanceStatus === 'Present' ? (string) ($row['marked_at'] ?? '') : '',
            'available' => $available,
            'access_token' => $available ? (string) ($row['access_token'] ?? '') : '',
        ];
    }

    $summary['rate'] = $summary['total'] > 0 ? (int) round(($summary['present'] / $summary['total']) * 100) : 0;
    return ['summary' => $summary, 'sessions' => $sessions, 'available_sessions' => $availableSessions];
}

function cocurricularFetchAttendanceEvents(): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

    $stmt = $pdo->query(
        'SELECT e.id, e.title, e.club_id, e.event_date, e.start_time, e.end_time, e.status AS event_status,
            c.club_name, COUNT(DISTINCT ep.id) AS approved_count
         FROM club_events e
         INNER JOIN clubs c ON c.id = e.club_id
         INNER JOIN club_event_participants ep ON ep.event_id = e.id AND ep.status = "Approved"
         LEFT JOIN club_event_participants newer
           ON newer.event_id = ep.event_id AND newer.user_id = ep.user_id
          AND (newer.registered_at > ep.registered_at
               OR (newer.registered_at = ep.registered_at AND newer.id > ep.id))
         WHERE newer.id IS NULL
         GROUP BY e.id, e.title, e.club_id, e.event_date, e.start_time, e.end_time, e.status, c.club_name
         ORDER BY e.event_date DESC, e.start_time DESC, e.id DESC'
    );
    return $stmt->fetchAll();
}

function cocurricularFetchAttendanceClubs(): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }
    $stmt = $pdo->query(
        'SELECT DISTINCT c.id, c.club_name
         FROM clubs c
         INNER JOIN club_events e ON e.club_id = c.id AND e.status = "Published"
         INNER JOIN club_event_participants ep ON ep.event_id = e.id AND ep.status = "Approved"
         WHERE NOT EXISTS (
             SELECT 1 FROM club_event_participants newer
             WHERE newer.event_id = ep.event_id AND newer.user_id = ep.user_id
               AND (newer.registered_at > ep.registered_at
                    OR (newer.registered_at = ep.registered_at AND newer.id > ep.id))
         )
         ORDER BY c.club_name ASC'
    );
    return $stmt->fetchAll();
}

function cocurricularFetchAttendanceEventsForClub(int $clubId): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $clubId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT e.id, e.club_id, e.title, e.event_type, e.event_date, e.start_time, e.end_time, e.venue,
                c.club_name, COUNT(DISTINCT ep.id) AS approved_count
         FROM club_events e
         INNER JOIN clubs c ON c.id = e.club_id
         INNER JOIN club_event_participants ep ON ep.event_id = e.id AND ep.status = "Approved"
         LEFT JOIN club_event_participants newer
           ON newer.event_id = ep.event_id AND newer.user_id = ep.user_id
          AND (newer.registered_at > ep.registered_at
               OR (newer.registered_at = ep.registered_at AND newer.id > ep.id))
         WHERE e.club_id = ? AND e.status = "Published" AND newer.id IS NULL
         GROUP BY e.id, e.club_id, e.title, e.event_type, e.event_date, e.start_time, e.end_time, e.venue, c.club_name
         ORDER BY (e.event_date < CURDATE()), e.event_date ASC, e.start_time ASC'
    );
    $stmt->execute([$clubId]);
    return $stmt->fetchAll();
}

function cocurricularCreateAttendanceSession(int $eventId, int $osaUserId, array $configuration = []): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $eventId <= 0 || $osaUserId <= 0) {
        return ['status' => 'error'];
    }

    try {
        $pdo->beginTransaction();
        $eventStmt = $pdo->prepare('SELECT id, club_id, title, event_date, start_time, end_time, venue, status FROM club_events WHERE id = ? LIMIT 1 FOR UPDATE');
        $eventStmt->execute([$eventId]);
        $event = $eventStmt->fetch();
        $clubId = (int) ($configuration['club_id'] ?? 0);
        if (!$event || $clubId <= 0 || (int) $event['club_id'] !== $clubId || (string) $event['status'] !== 'Published') {
            $pdo->rollBack();
            return ['status' => 'invalid_event'];
        }

        $location = trim((string) ($configuration['location'] ?? $event['venue'] ?? ''));
        $sessionDate = trim((string) ($configuration['session_date'] ?? $event['event_date']));
        $sessionStart = trim((string) ($configuration['session_start_time'] ?? $event['start_time']));
        $sessionEnd = trim((string) ($configuration['session_end_time'] ?? $event['end_time']));
        $openAt = trim((string) ($configuration['attendance_open_at'] ?? ''));
        $deadline = trim((string) ($configuration['attendance_deadline'] ?? ''));
        $method = (string) ($configuration['attendance_method'] ?? 'QR Code');
        if ($location === '' || $sessionDate === '' || $sessionStart === '' || $sessionEnd === '' || $openAt === '' || $deadline === '' || !in_array($method, ['QR Code', 'Attendance Code', 'QR + Attendance Code'], true)) {
            $pdo->rollBack();
            return ['status' => 'invalid_configuration'];
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $sessionDate);
        $start = DateTimeImmutable::createFromFormat('!H:i', $sessionStart);
        $end = DateTimeImmutable::createFromFormat('!H:i', $sessionEnd);
        $openDateTime = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $openAt);
        $deadlineDateTime = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $deadline);
        if (!$date || !$start || !$end || !$openDateTime || !$deadlineDateTime || $date->format('Y-m-d') !== $sessionDate
            || $start >= $end || $openDateTime >= $deadlineDateTime || $openDateTime->format('Y-m-d') !== $sessionDate
            || $deadlineDateTime->format('Y-m-d') !== $sessionDate) {
            $pdo->rollBack();
            $message = (!$start || !$end || $start >= $end)
                ? 'Session start time must be before the end time.'
                : ((!$openDateTime || !$deadlineDateTime || $openDateTime >= $deadlineDateTime)
                    ? 'Attendance deadline must be after the attendance opening time.'
                    : 'Session date and attendance times must be valid and use the session date.');
            return ['status' => 'invalid_configuration', 'message' => $message];
        }

        $duplicateStmt = $pdo->prepare(
            'SELECT id FROM club_event_attendance
             WHERE event_id = ? AND location = ? AND session_date = ? AND session_start_time = ?
               AND session_end_time = ? AND attendance_open_at = ? AND attendance_deadline = ? AND attendance_method = ?
             LIMIT 1 FOR UPDATE'
        );
        $duplicateStmt->execute([$eventId, $location, $sessionDate, $sessionStart, $sessionEnd, $openDateTime->format('Y-m-d H:i:s'), $deadlineDateTime->format('Y-m-d H:i:s'), $method]);
        $duplicateId = (int) ($duplicateStmt->fetchColumn() ?: 0);
        if ($duplicateId > 0) {
            $pdo->rollBack();
            return ['status' => 'existing', 'session' => cocurricularGetAttendanceSession($duplicateId), 'id' => $duplicateId];
        }

        $approvedStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM club_event_participants ep
             WHERE ep.event_id = ? AND ep.status = "Approved"
               AND NOT EXISTS (
                   SELECT 1 FROM club_event_participants newer
                   WHERE newer.event_id = ep.event_id AND newer.user_id = ep.user_id
                     AND (newer.registered_at > ep.registered_at
                          OR (newer.registered_at = ep.registered_at AND newer.id > ep.id))
               )'
        );
        $approvedStmt->execute([$eventId]);
        if ((int) $approvedStmt->fetchColumn() === 0) {
            $pdo->rollBack();
            return ['status' => 'no_approved_participants'];
        }

        $accessToken = bin2hex(random_bytes(24));
        $attendanceCode = (string) random_int(100000, 999999);
        $insert = $pdo->prepare(
            'INSERT INTO club_event_attendance
                (event_id, opened_at, status, location, session_date, session_start_time, session_end_time,
                 attendance_open_at, attendance_deadline, attendance_method, access_token, attendance_code, created_by)
             VALUES (?, NOW(), "Open", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$eventId, $location, $sessionDate, $sessionStart, $sessionEnd, $openDateTime->format('Y-m-d H:i:s'), $deadlineDateTime->format('Y-m-d H:i:s'), $method, $accessToken, $attendanceCode, $osaUserId]);
        $sessionId = (int) $pdo->lastInsertId();
        $recordInsert = $pdo->prepare(
            'INSERT INTO club_event_attendance_records (attendance_id, event_id, participant_id, user_id)
             SELECT ?, ep.event_id, ep.id, ep.user_id
             FROM club_event_participants ep
             LEFT JOIN club_event_participants newer
               ON newer.event_id = ep.event_id AND newer.user_id = ep.user_id
              AND (newer.registered_at > ep.registered_at
                   OR (newer.registered_at = ep.registered_at AND newer.id > ep.id))
             WHERE ep.event_id = ? AND ep.status = "Approved" AND newer.id IS NULL'
        );
        $recordInsert->execute([$sessionId, $eventId]);
        $pdo->commit();
        return ['status' => 'created', 'session' => cocurricularGetAttendanceSession($sessionId), 'id' => $sessionId];
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['status' => 'error'];
    }
}

function cocurricularOpenAttendance(int $attendanceId, int $osaUserId): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $attendanceId <= 0 || $osaUserId <= 0) {
        return ['status' => 'error'];
    }

    try {
        $pdo->beginTransaction();
        $sessionStmt = $pdo->prepare('SELECT * FROM club_event_attendance WHERE id = ? LIMIT 1 FOR UPDATE');
        $sessionStmt->execute([$attendanceId]);
        $session = $sessionStmt->fetch();
        if (!$session) {
            $pdo->rollBack();
            return ['status' => 'not_found'];
        }
        if (!empty($session['attendance_deadline']) && new DateTimeImmutable('now') >= new DateTimeImmutable((string) $session['attendance_deadline'])) {
            $pdo->prepare('UPDATE club_event_attendance SET status = "Closed", closed_at = COALESCE(closed_at, NOW()), updated_at = NOW() WHERE id = ?')->execute([$attendanceId]);
            $pdo->commit();
            return ['status' => 'closed', 'session' => cocurricularGetAttendanceSession($attendanceId)];
        }
        if ($session['status'] === 'Closed') {
            $pdo->rollBack();
            return ['status' => 'closed', 'session' => $session];
        }

        $insert = $pdo->prepare(
            'INSERT IGNORE INTO club_event_attendance_records
                (attendance_id, event_id, participant_id, user_id)
             SELECT :attendance_id, ep.event_id, ep.id, ep.user_id
             FROM club_event_participants ep
            WHERE ep.event_id = :event_id AND ep.status = "Approved"
               AND NOT EXISTS (
                   SELECT 1 FROM club_event_participants newer
                   WHERE newer.event_id = ep.event_id AND newer.user_id = ep.user_id
                     AND (newer.registered_at > ep.registered_at
                          OR (newer.registered_at = ep.registered_at AND newer.id > ep.id))
               )'
        );
        $insert->execute([':attendance_id' => (int) $session['id'], ':event_id' => (int) $session['event_id']]);

        $update = $pdo->prepare('UPDATE club_event_attendance SET status = "Open", opened_at = COALESCE(opened_at, NOW()), updated_at = NOW() WHERE id = ?');
        $update->execute([(int) $session['id']]);
        $pdo->commit();
        return ['status' => 'open', 'session' => cocurricularGetAttendanceSession($attendanceId)];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['status' => 'error'];
    }
}

function cocurricularFetchAttendanceRoster(int $attendanceId): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $attendanceId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT ar.*, u.full_name, u.student_id
         FROM club_event_attendance_records ar
         INNER JOIN sms2_db.users u ON u.id = ar.user_id
         INNER JOIN club_event_attendance a ON a.id = ar.attendance_id
         WHERE a.id = ?
         ORDER BY u.full_name ASC'
    );
    $stmt->execute([$attendanceId]);
    return $stmt->fetchAll();
}

function cocurricularUpdateAttendanceRecord(int $attendanceId, int $recordId, int $osaUserId, string $status, ?string $remarks = null): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $attendanceId <= 0 || $recordId <= 0 || $osaUserId <= 0 || !in_array($status, ['Not Marked', 'Present', 'Absent'], true)) {
        return ['status' => 'error'];
    }
    $markedAt = $status === 'Not Marked' ? null : date('Y-m-d H:i:s');
    $pdo->prepare('UPDATE club_event_attendance SET status = "Closed", closed_at = COALESCE(closed_at, NOW()), updated_at = NOW() WHERE id = ? AND status = "Open" AND attendance_deadline IS NOT NULL AND NOW() >= attendance_deadline')->execute([$attendanceId]);
    $stmt = $pdo->prepare(
        'UPDATE club_event_attendance_records ar
         INNER JOIN club_event_attendance a ON a.id = ar.attendance_id
         SET ar.attendance_status = ?, ar.marked_at = ?, ar.marked_by = ?, ar.remarks = ?, ar.updated_at = NOW()
                WHERE ar.id = ? AND a.id = ? AND a.status = "Open" AND a.finalized_at IS NULL'
            );
    $stmt->execute([$status, $markedAt, $status === 'Not Marked' ? null : $osaUserId, $remarks, $recordId, $attendanceId]);
    return $stmt->rowCount() === 1 ? ['status' => 'updated'] : ['status' => 'closed_or_missing'];
}

function cocurricularCloseAttendance(int $attendanceId): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $attendanceId <= 0) {
        return ['status' => 'error'];
    }
    $stmt = $pdo->prepare('UPDATE club_event_attendance SET status = "Closed", closed_at = NOW(), updated_at = NOW() WHERE id = ? AND status = "Open" AND finalized_at IS NULL');
    $stmt->execute([$attendanceId]);
    if ($stmt->rowCount() !== 1) {
        return ['status' => 'invalid_transition'];
    }
    return ['status' => 'closed', 'session' => cocurricularGetAttendanceSession($attendanceId)];
}

function cocurricularFinalizeAttendance(int $attendanceId): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $attendanceId <= 0) {
        return ['status' => 'error'];
    }
    try {
        $pdo->beginTransaction();
        $sessionStmt = $pdo->prepare('SELECT * FROM club_event_attendance WHERE id = ? LIMIT 1 FOR UPDATE');
        $sessionStmt->execute([$attendanceId]);
        $session = $sessionStmt->fetch();
        if (!$session) { $pdo->rollBack(); return ['status' => 'not_found']; }
        if (!empty($session['finalized_at'])) { $pdo->rollBack(); return ['status' => 'finalized']; }
        if ((string) $session['status'] === 'Open') { $pdo->rollBack(); return ['status' => 'still_open']; }
        $pdo->prepare('UPDATE club_event_attendance_records SET attendance_status = "Absent", updated_at = NOW() WHERE attendance_id = ? AND attendance_status = "Not Marked"')->execute([$attendanceId]);
        $pdo->prepare('UPDATE club_event_attendance SET status = "Closed", finalized_at = NOW(), closed_at = COALESCE(closed_at, NOW()), updated_at = NOW() WHERE id = ?')->execute([$attendanceId]);
        $pdo->commit();
        return ['status' => 'finalized', 'session' => cocurricularGetAttendanceSession($attendanceId)];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return ['status' => 'error'];
    }
}

function cocurricularGetAttendanceSummary(int $attendanceId): array
{
    $summary = ['total_eligible' => 0, 'present' => 0, 'absent' => 0, 'not_marked' => 0];
    $pdo = cocurricularDb();
    if (!$pdo || $attendanceId <= 0) {
        return $summary;
    }
    $stmt = $pdo->prepare('SELECT attendance_status, COUNT(*) AS total FROM club_event_attendance_records ar WHERE ar.attendance_id = ? GROUP BY attendance_status');
    $stmt->execute([$attendanceId]);
    foreach ($stmt->fetchAll() as $row) {
        $key = strtolower(str_replace(' ', '_', (string) $row['attendance_status']));
        if (isset($summary[$key])) {
            $summary[$key] = (int) $row['total'];
            $summary['total_eligible'] += (int) $row['total'];
        }
    }
    return $summary;
}

function cocurricularResolveAttendanceContext(string $credential): array
{
    $pdo = cocurricularDb();
    $credential = trim($credential);
    if (!$pdo || $credential === '') {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT a.id AS attendance_id, e.id AS event_id, e.club_id
         FROM club_event_attendance a
         INNER JOIN club_events e ON e.id = a.event_id
         WHERE a.access_token = ? OR a.attendance_code = ?
         LIMIT 1'
    );
    $stmt->execute([$credential, $credential]);
    $context = $stmt->fetch();
    if (!$context) {
        return [];
    }
    return [
        'attendance_id' => (int) $context['attendance_id'],
        'event_id' => (int) $context['event_id'],
        'club_id' => (int) $context['club_id'],
    ];
}

function cocurricularValidateStudentAttendance(string $credential, int $studentUserId): array
{
    $pdo = cocurricularDb();
    $credential = trim($credential);
    if (!$pdo || $credential === '' || $studentUserId <= 0) {
        return ['status' => 'invalid_code'];
    }

    try {
        $sessionStmt = $pdo->prepare(
            'SELECT a.*, e.title AS event_title, e.venue, e.status AS event_status, c.club_name
             FROM club_event_attendance a
             INNER JOIN club_events e ON e.id = a.event_id
             LEFT JOIN clubs c ON c.id = e.club_id
             WHERE a.access_token = ? OR a.attendance_code = ?
             LIMIT 1'
        );
        $sessionStmt->execute([$credential, $credential]);
        $session = $sessionStmt->fetch();
        if (!$session) {
            return ['status' => 'invalid_code'];
        }

        $now = new DateTimeImmutable('now');
        if ((string) $session['event_status'] !== 'Published') {
            return [
                'status' => 'closed',
                'event_title' => (string) ($session['event_title'] ?? ''),
                'club_name' => (string) ($session['club_name'] ?? ''),
                'session_date' => (string) ($session['session_date'] ?? ''),
                'location' => (string) ($session['location'] ?? ''),
                'time_range' => trim((string) (($session['session_start_time'] ?? '') . ' - ' . ($session['session_end_time'] ?? ''))),
            ];
        }
        if (!empty($session['finalized_at']) || (string) $session['status'] === 'Closed') {
            return [
                'status' => !empty($session['finalized_at']) ? 'finalized' : 'closed',
                'event_title' => (string) ($session['event_title'] ?? ''),
                'club_name' => (string) ($session['club_name'] ?? ''),
                'session_date' => (string) ($session['session_date'] ?? ''),
                'location' => (string) ($session['location'] ?? ''),
                'time_range' => trim((string) (($session['session_start_time'] ?? '') . ' - ' . ($session['session_end_time'] ?? ''))),
            ];
        }
        if ((string) $session['status'] !== 'Open') {
            return ['status' => 'not_open', 'event_title' => (string) ($session['event_title'] ?? ''), 'club_name' => (string) ($session['club_name'] ?? ''), 'session_date' => (string) ($session['session_date'] ?? ''), 'location' => (string) ($session['location'] ?? ''), 'time_range' => trim((string) (($session['session_start_time'] ?? '') . ' - ' . ($session['session_end_time'] ?? ''))), 'open_at' => (string) ($session['attendance_open_at'] ?? '')];
        }
        if (empty($session['attendance_open_at']) || empty($session['attendance_deadline']) || empty($session['session_date']) || empty($session['session_start_time'])) {
            return ['status' => 'not_open', 'event_title' => (string) ($session['event_title'] ?? ''), 'club_name' => (string) ($session['club_name'] ?? ''), 'session_date' => (string) ($session['session_date'] ?? ''), 'location' => (string) ($session['location'] ?? ''), 'time_range' => trim((string) (($session['session_start_time'] ?? '') . ' - ' . ($session['session_end_time'] ?? ''))), 'open_at' => (string) ($session['attendance_open_at'] ?? '')];
        }

        $openAt = new DateTimeImmutable((string) $session['attendance_open_at']);
        $deadline = new DateTimeImmutable((string) $session['attendance_deadline']);
        if ($now < $openAt) {
            return ['status' => 'not_open', 'event_title' => (string) ($session['event_title'] ?? ''), 'club_name' => (string) ($session['club_name'] ?? ''), 'session_date' => (string) ($session['session_date'] ?? ''), 'location' => (string) ($session['location'] ?? ''), 'time_range' => trim((string) (($session['session_start_time'] ?? '') . ' - ' . ($session['session_end_time'] ?? ''))), 'open_at' => (string) ($session['attendance_open_at'] ?? '')];
        }
        if ($now >= $deadline) {
            $pdo->prepare('UPDATE club_event_attendance SET status = "Closed", closed_at = COALESCE(closed_at, NOW()), updated_at = NOW() WHERE id = ?')->execute([(int) $session['id']]);
            return ['status' => 'expired', 'event_title' => (string) ($session['event_title'] ?? ''), 'club_name' => (string) ($session['club_name'] ?? ''), 'session_date' => (string) ($session['session_date'] ?? ''), 'location' => (string) ($session['location'] ?? ''), 'time_range' => trim((string) (($session['session_start_time'] ?? '') . ' - ' . ($session['session_end_time'] ?? ''))), 'open_at' => (string) ($session['attendance_open_at'] ?? '')];
        }

        $approvedStmt = $pdo->prepare(
            'SELECT ep.id
             FROM club_event_participants ep
             WHERE ep.event_id = ? AND ep.user_id = ? AND ep.status = "Approved"
               AND NOT EXISTS (
                   SELECT 1 FROM club_event_participants newer
                   WHERE newer.event_id = ep.event_id AND newer.user_id = ep.user_id
                     AND (newer.registered_at > ep.registered_at
                          OR (newer.registered_at = ep.registered_at AND newer.id > ep.id))
               )
             LIMIT 1'
        );
        $approvedStmt->execute([(int) $session['event_id'], $studentUserId]);
        $approvedParticipantId = (int) ($approvedStmt->fetchColumn() ?: 0);

        $recordStmt = $pdo->prepare(
            'SELECT ar.id, ar.attendance_status, ar.marked_at, ar.participant_id
             FROM club_event_attendance_records ar
             WHERE ar.attendance_id = ? AND ar.user_id = ?
             LIMIT 1'
        );
        $recordStmt->execute([(int) $session['id'], $studentUserId]);
        $record = $recordStmt->fetch();

        if (!$record || $approvedParticipantId <= 0 || (int) $record['participant_id'] !== $approvedParticipantId) {
            return ['status' => 'not_approved'];
        }

        if ((string) $record['attendance_status'] !== 'Not Marked') {
            return [
                'status' => 'already_recorded',
                'event_title' => (string) ($session['event_title'] ?? ''),
                'club_name' => (string) ($session['club_name'] ?? ''),
                'session_date' => (string) ($session['session_date'] ?? ''),
                'location' => (string) ($session['location'] ?? ''),
                'time_range' => trim((string) (($session['session_start_time'] ?? '') . ' - ' . ($session['session_end_time'] ?? ''))),
                'marked_at' => (string) ($record['marked_at'] ?? ''),
                'attendance_status' => (string) ($record['attendance_status'] ?? ''),
            ];
        }

        return [
            'status' => 'ready',
            'event_title' => (string) ($session['event_title'] ?? ''),
            'club_name' => (string) ($session['club_name'] ?? ''),
            'session_date' => (string) ($session['session_date'] ?? ''),
            'location' => (string) ($session['location'] ?? ''),
            'time_range' => trim((string) (($session['session_start_time'] ?? '') . ' - ' . ($session['session_end_time'] ?? ''))),
            'open_at' => (string) ($session['attendance_open_at'] ?? ''),
        ];
    } catch (Throwable $exception) {
        return ['status' => 'error'];
    }
}

function cocurricularSubmitStudentAttendance(string $credential, int $studentUserId): array
{
    $pdo = cocurricularDb();
    $credential = trim($credential);
    if (!$pdo || $credential === '' || $studentUserId <= 0) {
        return ['status' => 'invalid_code'];
    }

    $validation = cocurricularValidateStudentAttendance($credential, $studentUserId);
    $status = (string) ($validation['status'] ?? 'error');
    if (!in_array($status, ['ready', 'already_recorded'], true)) {
        return $validation;
    }

    if ($status === 'already_recorded') {
        return $validation;
    }

    try {
        $pdo->beginTransaction();
        $sessionStmt = $pdo->prepare(
            'SELECT a.*, e.title AS event_title, e.status AS event_status, c.club_name
             FROM club_event_attendance a
             INNER JOIN club_events e ON e.id = a.event_id
             LEFT JOIN clubs c ON c.id = e.club_id
             WHERE a.access_token = ? OR a.attendance_code = ?
             LIMIT 1
             FOR UPDATE'
        );
        $sessionStmt->execute([$credential, $credential]);
        $session = $sessionStmt->fetch();
        if (!$session) {
            $pdo->rollBack();
            return ['status' => 'invalid_code'];
        }

        if ((string) $session['event_status'] !== 'Published') {
            $pdo->rollBack();
            return ['status' => 'closed'];
        }

        $recordStmt = $pdo->prepare(
            'SELECT ar.id, ar.attendance_status, ar.marked_at, ar.participant_id
             FROM club_event_attendance_records ar
             WHERE ar.attendance_id = ? AND ar.user_id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $recordStmt->execute([(int) $session['id'], $studentUserId]);
        $record = $recordStmt->fetch();

        if (!$record) {
            $pdo->rollBack();
            return ['status' => 'not_approved'];
        }
        if ((string) $record['attendance_status'] !== 'Not Marked') {
            $pdo->rollBack();
            return [
                'status' => 'already_recorded',
                'event_title' => (string) ($session['event_title'] ?? ''),
                'club_name' => (string) ($session['club_name'] ?? ''),
                'session_date' => (string) ($session['session_date'] ?? ''),
                'location' => (string) ($session['location'] ?? ''),
                'time_range' => trim((string) (($session['session_start_time'] ?? '') . ' - ' . ($session['session_end_time'] ?? ''))),
                'marked_at' => (string) ($record['marked_at'] ?? ''),
                'attendance_status' => (string) ($record['attendance_status'] ?? ''),
            ];
        }

        $attendanceStatus = 'Present';
        $update = $pdo->prepare(
            'UPDATE club_event_attendance_records
             SET attendance_status = ?, marked_at = NOW(), marked_by = ?, updated_at = NOW()
             WHERE id = ? AND attendance_status = "Not Marked"'
        );
        $update->execute([$attendanceStatus, $studentUserId, (int) $record['id']]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            return ['status' => 'already_recorded'];
        }

        $pdo->commit();
        return [
            'status' => 'recorded',
            'event_title' => (string) ($session['event_title'] ?? ''),
            'club_name' => (string) ($session['club_name'] ?? ''),
            'session_date' => (string) ($session['session_date'] ?? ''),
            'location' => (string) ($session['location'] ?? ''),
            'time_range' => trim((string) (($session['session_start_time'] ?? '') . ' - ' . ($session['session_end_time'] ?? ''))),
            'marked_at' => date('Y-m-d H:i:s'),
            'attendance_status' => $attendanceStatus,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['status' => 'error'];
    }
}

function cocurricularUpdateClubEvent(int $eventId, array $input): bool
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return false;
    }

    $clubId = (int) ($input['club_id'] ?? 0);
    $title = trim((string) ($input['title'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $eventType = trim((string) ($input['event_type'] ?? ''));
    $eventDate = trim((string) ($input['event_date'] ?? ''));
    $startTime = trim((string) ($input['start_time'] ?? ''));
    $endTime = trim((string) ($input['end_time'] ?? ''));
    $venue = trim((string) ($input['venue'] ?? ''));
    $imagePath = trim((string) ($input['image_path'] ?? ''));
    $isPinned = !empty($input['is_pinned']) ? 1 : 0;
    $status = in_array((string) ($input['status'] ?? 'Draft'), ['Draft', 'Published', 'Completed', 'Cancelled'], true)
        ? (string) $input['status']
        : 'Draft';

    if ($eventId <= 0 || $clubId <= 0 || $title === '' || $eventType === '' || $eventDate === '' || $startTime === '' || $endTime === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE club_events
             SET club_id = :club_id,
                 title = :title,
                 description = :description,
                 event_type = :event_type,
                 event_date = :event_date,
                 start_time = :start_time,
                 end_time = :end_time,
                 venue = :venue,
                 image_path = :image_path,
                 status = :status,
                 is_pinned = :is_pinned,
                 updated_at = NOW()
             WHERE id = :id'
        );

        return $stmt->execute([
            ':club_id' => $clubId,
            ':title' => $title,
            ':description' => $description,
            ':event_type' => $eventType,
            ':event_date' => $eventDate,
            ':start_time' => $startTime,
            ':end_time' => $endTime,
            ':venue' => $venue !== '' ? $venue : null,
            ':image_path' => $imagePath !== '' ? $imagePath : null,
            ':status' => $status,
            ':is_pinned' => $isPinned,
            ':id' => $eventId,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function cocurricularUpdateEventStatus(int $eventId, string $newStatus): bool
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return false;
    }

    if (!in_array($newStatus, ['Draft', 'Published', 'Completed', 'Cancelled'], true)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('UPDATE club_events SET status = :status, updated_at = NOW() WHERE id = :id');
        return $stmt->execute([
            ':status' => $newStatus,
            ':id' => $eventId,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function cocurricularDeleteClubEvent(int $eventId): bool
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM club_events WHERE id = ?');
        return $stmt->execute([$eventId]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Fetch every application for Student Affairs, including its existing club.
 */
function cocurricularGetMembershipApplications(): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

        $sql = 'SELECT a.*, c.id AS club_id, c.club_name, c.adviser, c.adviser_email, c.category
            FROM club_membership_applications a
            INNER JOIN clubs c ON c.id = a.club_id
            ORDER BY a.submitted_at DESC';
    $stmt = $pdo->prepare($sql);
        $stmt->execute();
    return $stmt->fetchAll();
}

/**
     * Fetch one application and verify that its club still exists.
 */
    function cocurricularGetMembershipApplication(int $applicationId): ?array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return null;
    }

        $sql = 'SELECT a.*, c.id AS club_id, c.club_name, c.adviser, c.adviser_email, c.category
            FROM club_membership_applications a
            INNER JOIN clubs c ON c.id = a.club_id
            WHERE a.id = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
        $stmt->execute([$applicationId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Update a pending application status.
 */
function cocurricularUpdateMembershipApplicationStatus(int $applicationId, string $newStatus): bool
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return false;
    }

    // Only allow specific statuses
    if (!in_array($newStatus, ['Pending', 'Approved', 'Rejected'], true)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE club_membership_applications
             SET status = :status, reviewed_at = NOW()
             WHERE id = :id AND status = "Pending"'
        );

        return $stmt->execute([
            ':status' => $newStatus,
            ':id' => $applicationId,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get approved members for a specific club.
 */
function cocurricularGetClubApprovedMembers(int $clubId): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

    $sql = 'SELECT a.*, c.club_name
            FROM club_membership_applications a
            INNER JOIN clubs c ON c.id = a.club_id
            WHERE a.club_id = ? AND a.status = "Approved"
            ORDER BY a.submitted_at ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$clubId]);
    return $stmt->fetchAll();
}

/**
 * Get all approved members for Student Affairs.
 */
function cocurricularGetApprovedMembers(): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

    $sql = 'SELECT a.*, c.id AS club_id, c.club_name, c.adviser, c.adviser_email
            FROM club_membership_applications a
            INNER JOIN clubs c ON c.id = a.club_id
                WHERE a.status = "Approved"
                ORDER BY c.club_name ASC, a.submitted_at ASC';
    $stmt = $pdo->prepare($sql);
            $stmt->execute();
    return $stmt->fetchAll();
}
