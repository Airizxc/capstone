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

function cocurricularNormalizeText(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value));
}
