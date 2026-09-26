<?php
/**
 * Co-Curricular Module Data Access
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/cocurricular-notifications.php';
require_once __DIR__ . '/cocurricular-notification-triggers.php';

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

/**
 * Idempotently ensure the adviser_id column exists in cocurricular_db.clubs.
 */
function cocurricularEnsureAdviserSchema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $pdo = getCocurricularConnection();
        $colStmt = $pdo->query("SHOW COLUMNS FROM `clubs` LIKE 'adviser_id'");
        if (!$colStmt->fetch()) {
            $pdo->exec("ALTER TABLE `clubs` ADD COLUMN `adviser_id` INT(10) UNSIGNED DEFAULT NULL AFTER `description`, ADD KEY `idx_club_adviser_id` (`adviser_id`)");
            try {
                $pdo->exec("ALTER TABLE `clubs` ADD CONSTRAINT `fk_club_adviser_user` FOREIGN KEY (`adviser_id`) REFERENCES `sms2_db`.`users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE");
            } catch (Throwable $fkError) {
                // Ignore if constraint already exists or cross-db FK unsupported by engine
            }
        }
    } catch (Throwable $e) {
        error_log('cocurricularEnsureAdviserSchema error: ' . $e->getMessage());
    }
}

function cocurricularDb(): ?PDO
{
    try {
        $pdo = getCocurricularConnection();
        cocurricularEnsureAdviserSchema();
        return $pdo;
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

/**
 * Fetch all active faculty accounts from sms2_db.users eligible to be club advisers.
 *
 * @return list<array{id: int, username: string, full_name: string, email: string, role_key: string, role_label: string}>
 */
function cocurricularGetEligibleFacultyUsers(): array
{
    $pdo = db(); // Connect to main system db (sms2_db)
    if (!$pdo) {
        return [];
    }

    try {
        $sql = "
            SELECT u.id, u.username, u.full_name, u.email, u.role_key,
                   COALESCE(r.label, u.role_key) AS role_label
            FROM users u
            LEFT JOIN roles r ON r.role_key = u.role_key
            WHERE u.status = 'active'
              AND (
                  u.role_key IN ('faculty', 'adviser', 'panel', 'grammarian', 'research_director', 'hr', 'department_chair')
                  OR EXISTS (
                      SELECT 1 FROM role_permissions rp
                      WHERE rp.role_key = u.role_key
                        AND rp.module_key = 'faculty'
                        AND rp.granted = 1
                        AND rp.role_key NOT IN ('superadmin', 'sms_admin', 'student')
                  )
              )
            ORDER BY u.full_name ASC
        ";
        $stmt = $pdo->query($sql);
        $results = [];
        foreach ($stmt->fetchAll() as $row) {
            $results[] = [
                'id'         => (int) $row['id'],
                'username'   => (string) $row['username'],
                'full_name'  => (string) $row['full_name'],
                'email'      => (string) $row['email'],
                'role_key'   => (string) $row['role_key'],
                'role_label' => (string) $row['role_label'],
            ];
        }
        return $results;
    } catch (Throwable $e) {
        error_log('cocurricularGetEligibleFacultyUsers error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Validate and retrieve a single active faculty user from sms2_db.users.
 *
 * @param int $userId
 * @return array{id: int, username: string, full_name: string, email: string, role_key: string, role_label: string}|null
 */
function cocurricularGetFacultyUserById(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $pdo = db();
    if (!$pdo) {
        return null;
    }

    try {
        $sql = "
            SELECT u.id, u.username, u.full_name, u.email, u.role_key,
                   COALESCE(r.label, u.role_key) AS role_label
            FROM users u
            LEFT JOIN roles r ON r.role_key = u.role_key
            WHERE u.id = ? AND u.status = 'active'
              AND (
                  u.role_key IN ('faculty', 'adviser', 'panel', 'grammarian', 'research_director', 'hr', 'department_chair')
                  OR EXISTS (
                      SELECT 1 FROM role_permissions rp
                      WHERE rp.role_key = u.role_key
                        AND rp.module_key = 'faculty'
                        AND rp.granted = 1
                        AND rp.role_key NOT IN ('superadmin', 'sms_admin', 'student')
                  )
              )
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return [
            'id'         => (int) $row['id'],
            'username'   => (string) $row['username'],
            'full_name'  => (string) $row['full_name'],
            'email'      => (string) $row['email'],
            'role_key'   => (string) $row['role_key'],
            'role_label' => (string) $row['role_label'],
        ];
    } catch (Throwable $e) {
        error_log('cocurricularGetFacultyUserById error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Check whether a user is an active faculty member in sms2_db.
 */
function cocurricularIsValidFacultyUser(int $userId): bool
{
    return cocurricularGetFacultyUserById($userId) !== null;
}

/**
 * Assign an existing Faculty user as the adviser of a specific club.
 *
 * @param int $clubId
 * @param int $facultyUserId Must be a valid active Faculty user in sms2_db.users (or 0 to unassign)
 * @param int $assignedByUserId OSA / Admin performing the assignment
 * @return array{success: bool, message: string, club?: array, adviser?: array}
 */
function cocurricularAssignClubAdviser(int $clubId, int $facultyUserId, int $assignedByUserId): array
{
    cocurricularEnsureAdviserSchema();

    $pdo = cocurricularDb();
    if (!$pdo || $clubId <= 0) {
        return ['success' => false, 'message' => 'Invalid club selected.'];
    }

    $club = cocurricularGetClubById($clubId);
    if (!$club) {
        return ['success' => false, 'message' => 'Club not found.'];
    }

    // Support unassigning if 0 or negative
    if ($facultyUserId <= 0) {
        return cocurricularUnassignClubAdviser($clubId, $assignedByUserId);
    }

    $faculty = cocurricularGetFacultyUserById($facultyUserId);
    if (!$faculty) {
        return ['success' => false, 'message' => 'The selected user is not a valid active Faculty member.'];
    }

    try {
        $stmt = $pdo->prepare('
            UPDATE clubs
            SET adviser_id = :adviser_id,
                adviser = :adviser_name,
                adviser_email = :adviser_email,
                updated_at = NOW()
            WHERE id = :id
        ');

        $stmt->execute([
            ':adviser_id'    => $faculty['id'],
            ':adviser_name'  => $faculty['full_name'],
            ':adviser_email' => $faculty['email'] !== '' ? $faculty['email'] : null,
            ':id'            => $clubId,
        ]);

        $updatedClub = cocurricularGetClubById($clubId);

        // Record audit trail in main system
        if (function_exists('smsLogAudit')) {
            smsLogAudit(
                $assignedByUserId,
                'assign_faculty_adviser',
                "Assigned Faculty {$faculty['full_name']} (ID: {$faculty['id']}) as adviser to club '{$club['club_name']}' (ID: {$clubId})",
                'cocurricular'
            );
        }

        // Send in-app notification to the assigned faculty user
        if (function_exists('cocurricularCreateNotification')) {
            cocurricularCreateNotification([
                'user_id'         => $faculty['id'],
                'title'           => 'Assigned as Faculty Adviser',
                'message'         => "You have been assigned as the Faculty Adviser for {$club['club_name']}.",
                'type'            => 'adviser_assigned',
                'related_id'      => $clubId,
                'destination_url' => '/modules/cocurricular/pages/club-directory.php',
            ]);
        }

        return [
            'success' => true,
            'message' => "Successfully assigned {$faculty['full_name']} as the Faculty Adviser for {$club['club_name']}.",
            'club'    => $updatedClub,
            'adviser' => $faculty,
        ];
    } catch (Throwable $e) {
        error_log('cocurricularAssignClubAdviser error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to save adviser assignment: ' . $e->getMessage()];
    }
}

/**
 * Remove / unassign the faculty adviser from a specific club.
 *
 * @param int $clubId
 * @param int $assignedByUserId
 * @return array{success: bool, message: string, club?: array}
 */
function cocurricularUnassignClubAdviser(int $clubId, int $assignedByUserId): array
{
    cocurricularEnsureAdviserSchema();

    $pdo = cocurricularDb();
    if (!$pdo || $clubId <= 0) {
        return ['success' => false, 'message' => 'Invalid club selected.'];
    }

    $club = cocurricularGetClubById($clubId);
    if (!$club) {
        return ['success' => false, 'message' => 'Club not found.'];
    }

    try {
        $stmt = $pdo->prepare('
            UPDATE clubs
            SET adviser_id = NULL,
                adviser = "None",
                adviser_email = NULL,
                updated_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([':id' => $clubId]);

        if (function_exists('smsLogAudit')) {
            smsLogAudit(
                $assignedByUserId,
                'unassign_faculty_adviser',
                "Removed adviser from club '{$club['club_name']}' (ID: {$clubId})",
                'cocurricular'
            );
        }

        return [
            'success' => true,
            'message' => "Adviser removed from {$club['club_name']}.",
            'club'    => cocurricularGetClubById($clubId),
        ];
    } catch (Throwable $e) {
        error_log('cocurricularUnassignClubAdviser error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to unassign adviser: ' . $e->getMessage()];
    }
}

/**
 * Create a new student club in cocurricular_db.clubs.
 * Enforces server-side validation, uniqueness of club name, and optional verified faculty adviser assignment.
 *
 * @param array $input
 * @param int $createdByUserId
 * @return array{success: bool, message: string, club_id?: int, club?: array}
 */
function cocurricularCreateClub(array $input, int $createdByUserId): array
{
    cocurricularEnsureAdviserSchema();

    $pdo = cocurricularDb();
    if (!$pdo) {
        return ['success' => false, 'message' => 'Database connection unavailable.'];
    }

    $clubName = trim((string) ($input['club_name'] ?? ''));
    if ($clubName === '') {
        return ['success' => false, 'message' => 'Club name is required.'];
    }
    if (mb_strlen($clubName) > 191) {
        return ['success' => false, 'message' => 'Club name must not exceed 191 characters.'];
    }

    // Verify name uniqueness
    $stmt = $pdo->prepare('SELECT id FROM clubs WHERE club_name = ? LIMIT 1');
    $stmt->execute([$clubName]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'A club with this name already exists. Please choose a unique name.'];
    }

    $category = trim((string) ($input['category'] ?? ''));
    if ($category === '') {
        return ['success' => false, 'message' => 'Club category is required.'];
    }
    if (mb_strlen($category) > 120) {
        return ['success' => false, 'message' => 'Category must not exceed 120 characters.'];
    }

    $description = trim((string) ($input['description'] ?? ''));
    if ($description === '') {
        return ['success' => false, 'message' => 'Club description is required.'];
    }

    $contactPhone = trim((string) ($input['contact_phone'] ?? ''));
    if ($contactPhone !== '' && mb_strlen($contactPhone) > 60) {
        return ['success' => false, 'message' => 'Contact phone must not exceed 60 characters.'];
    }
    $contactPhoneVal = $contactPhone !== '' ? $contactPhone : null;

    $status = trim((string) ($input['status'] ?? 'Active'));
    if (!in_array($status, ['Active', 'Pending', 'Inactive'], true)) {
        $status = 'Active';
    }

    // Process optional adviser assignment
    $facultyUserId = isset($input['faculty_user_id']) ? (int) $input['faculty_user_id'] : (isset($input['adviser_id']) ? (int) $input['adviser_id'] : 0);
    $adviserId = null;
    $adviserName = 'None';
    $adviserEmail = null;

    if ($facultyUserId > 0) {
        $faculty = cocurricularGetFacultyUserById($facultyUserId);
        if (!$faculty) {
            return ['success' => false, 'message' => 'The selected adviser is not a valid active Faculty member.'];
        }
        $adviserId = (int) $faculty['id'];
        $adviserName = (string) $faculty['full_name'];
        $adviserEmail = $faculty['email'] !== '' ? (string) $faculty['email'] : null;
    }

    try {
        $stmt = $pdo->prepare('
            INSERT INTO clubs (club_name, category, description, adviser_id, adviser, adviser_email, contact_phone, status, created_at, updated_at)
            VALUES (:club_name, :category, :description, :adviser_id, :adviser, :adviser_email, :contact_phone, :status, NOW(), NOW())
        ');
        $stmt->execute([
            ':club_name'     => $clubName,
            ':category'      => $category,
            ':description'   => $description,
            ':adviser_id'    => $adviserId,
            ':adviser'       => $adviserName,
            ':adviser_email' => $adviserEmail,
            ':contact_phone' => $contactPhoneVal,
            ':status'        => $status,
        ]);

        $newClubId = (int) $pdo->lastInsertId();

        if (function_exists('smsLogAudit')) {
            smsLogAudit(
                $createdByUserId,
                'create_club',
                "Created new club '{$clubName}' (ID: {$newClubId}) with status '{$status}'",
                'cocurricular'
            );
        }

        return [
            'success' => true,
            'message' => "Club '{$clubName}' has been successfully created.",
            'club_id' => $newClubId,
            'club'    => cocurricularGetClubById($newClubId),
        ];
    } catch (Throwable $e) {
        error_log('cocurricularCreateClub error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to create club: ' . $e->getMessage()];
    }
}

/**
 * Update an existing student club in cocurricular_db.clubs.
 * Uses explicit field handling and parameterized queries.
 *
 * @param int $clubId
 * @param array $input
 * @param int $updatedByUserId
 * @return array{success: bool, message: string, club?: array}
 */
function cocurricularUpdateClub(int $clubId, array $input, int $updatedByUserId): array
{
    cocurricularEnsureAdviserSchema();

    $pdo = cocurricularDb();
    if (!$pdo || $clubId <= 0) {
        return ['success' => false, 'message' => 'Invalid club selected.'];
    }

    $club = cocurricularGetClubById($clubId);
    if (!$club) {
        return ['success' => false, 'message' => 'Club not found.'];
    }

    $clubName = trim((string) ($input['club_name'] ?? $club['club_name']));
    if ($clubName === '') {
        return ['success' => false, 'message' => 'Club name is required.'];
    }
    if (mb_strlen($clubName) > 191) {
        return ['success' => false, 'message' => 'Club name must not exceed 191 characters.'];
    }

    // Verify name uniqueness among OTHER clubs
    $stmt = $pdo->prepare('SELECT id FROM clubs WHERE club_name = ? AND id != ? LIMIT 1');
    $stmt->execute([$clubName, $clubId]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Another club already uses this name. Please choose a unique name.'];
    }

    $category = trim((string) ($input['category'] ?? $club['category']));
    if ($category === '') {
        return ['success' => false, 'message' => 'Category is required.'];
    }
    if (mb_strlen($category) > 120) {
        return ['success' => false, 'message' => 'Category must not exceed 120 characters.'];
    }

    $description = trim((string) ($input['description'] ?? $club['description']));
    if ($description === '') {
        return ['success' => false, 'message' => 'Description is required.'];
    }

    $contactPhone = array_key_exists('contact_phone', $input)
        ? trim((string) $input['contact_phone'])
        : (string) ($club['contact_phone'] ?? '');
    if ($contactPhone !== '' && mb_strlen($contactPhone) > 60) {
        return ['success' => false, 'message' => 'Contact phone must not exceed 60 characters.'];
    }
    $contactPhoneVal = $contactPhone !== '' ? $contactPhone : null;

    $status = trim((string) ($input['status'] ?? $club['status']));
    if (!in_array($status, ['Active', 'Pending', 'Inactive'], true)) {
        $status = (string) $club['status'];
    }

    // Determine adviser fields
    $adviserId = !empty($club['adviser_id']) ? (int) $club['adviser_id'] : null;
    $adviserName = (string) ($club['adviser'] ?? 'None');
    $adviserEmail = !empty($club['adviser_email']) ? (string) $club['adviser_email'] : null;

    if (array_key_exists('faculty_user_id', $input) || array_key_exists('adviser_id', $input)) {
        $submittedFacultyId = isset($input['faculty_user_id']) ? (int) $input['faculty_user_id'] : (int) ($input['adviser_id'] ?? 0);
        if ($submittedFacultyId > 0) {
            $faculty = cocurricularGetFacultyUserById($submittedFacultyId);
            if (!$faculty) {
                return ['success' => false, 'message' => 'The selected adviser is not a valid active Faculty member.'];
            }
            $adviserId = (int) $faculty['id'];
            $adviserName = (string) $faculty['full_name'];
            $adviserEmail = $faculty['email'] !== '' ? (string) $faculty['email'] : null;
        } elseif ($submittedFacultyId === 0) {
            // Unassign explicitly
            $adviserId = null;
            $adviserName = 'None';
            $adviserEmail = null;
        }
    }

    try {
        $stmt = $pdo->prepare('
            UPDATE clubs
            SET club_name = :club_name,
                category = :category,
                description = :description,
                contact_phone = :contact_phone,
                status = :status,
                adviser_id = :adviser_id,
                adviser = :adviser,
                adviser_email = :adviser_email,
                updated_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([
            ':club_name'     => $clubName,
            ':category'      => $category,
            ':description'   => $description,
            ':contact_phone' => $contactPhoneVal,
            ':status'        => $status,
            ':adviser_id'    => $adviserId,
            ':adviser'       => $adviserName,
            ':adviser_email' => $adviserEmail,
            ':id'            => $clubId,
        ]);

        if (function_exists('smsLogAudit')) {
            smsLogAudit(
                $updatedByUserId,
                'update_club',
                "Updated club '{$clubName}' (ID: {$clubId})",
                'cocurricular'
            );
        }

        return [
            'success' => true,
            'message' => "Club '{$clubName}' has been updated successfully.",
            'club'    => cocurricularGetClubById($clubId),
        ];
    } catch (Throwable $e) {
        error_log('cocurricularUpdateClub error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update club: ' . $e->getMessage()];
    }
}

/**
 * Activate, deactivate, or update status of a club.
 * Non-destructive: strictly a status change; preserves all child records.
 *
 * @param int $clubId
 * @param string $newStatus 'Active' | 'Inactive' | 'Pending'
 * @param int $updatedByUserId
 * @return array{success: bool, message: string, club?: array}
 */
function cocurricularSetClubStatus(int $clubId, string $newStatus, int $updatedByUserId): array
{
    cocurricularEnsureAdviserSchema();

    $pdo = cocurricularDb();
    if (!$pdo || $clubId <= 0) {
        return ['success' => false, 'message' => 'Invalid club selected.'];
    }

    if (!in_array($newStatus, ['Active', 'Inactive', 'Pending'], true)) {
        return ['success' => false, 'message' => 'Invalid status specified. Valid statuses are Active, Inactive, and Pending.'];
    }

    $club = cocurricularGetClubById($clubId);
    if (!$club) {
        return ['success' => false, 'message' => 'Club not found.'];
    }

    try {
        $stmt = $pdo->prepare('UPDATE clubs SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            ':status' => $newStatus,
            ':id'     => $clubId,
        ]);

        if (function_exists('smsLogAudit')) {
            smsLogAudit(
                $updatedByUserId,
                'set_club_status',
                "Changed status of club '{$club['club_name']}' (ID: {$clubId}) from '{$club['status']}' to '{$newStatus}'",
                'cocurricular'
            );
        }

        $actionLabel = $newStatus === 'Active' ? 'activated' : ($newStatus === 'Inactive' ? 'deactivated' : 'set to Pending');
        return [
            'success' => true,
            'message' => "Club '{$club['club_name']}' has been {$actionLabel}.",
            'club'    => cocurricularGetClubById($clubId),
        ];
    } catch (Throwable $e) {
        error_log('cocurricularSetClubStatus error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update club status: ' . $e->getMessage()];
    }
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

function cocurricularCreateClubAnnouncement(array $input): int|bool
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

    $isAiGenerated = !empty($input['is_ai_generated']) ? 1 : 0;
    $aiModel = trim((string) ($input['ai_model'] ?? ''));
    $aiGeneratedAt = trim((string) ($input['ai_generated_at'] ?? ''));

    if ($clubId <= 0 || $title === '' || $content === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO club_announcements
                (club_id, title, content, posted_at, is_pinned, attachment_path, is_ai_generated, ai_model, ai_generated_at)
             VALUES
                (:club_id, :title, :content, :posted_at, :is_pinned, :attachment_path, :is_ai_generated, :ai_model, :ai_generated_at)'
        );
        $executed = $stmt->execute([
            ':club_id' => $clubId,
            ':title' => $title,
            ':content' => $content,
            ':posted_at' => $postedAt !== '' ? $postedAt : date('Y-m-d H:i:s'),
            ':is_pinned' => $isPinned,
            ':attachment_path' => $attachmentPath !== '' ? $attachmentPath : null,
            ':is_ai_generated' => $isAiGenerated,
            ':ai_model' => $isAiGenerated && $aiModel !== '' ? $aiModel : ($isAiGenerated ? 'gpt-4.1' : null),
            ':ai_generated_at' => $isAiGenerated && $aiGeneratedAt !== '' ? $aiGeneratedAt : ($isAiGenerated ? date('Y-m-d H:i:s') : null),
        ]);
        return $executed ? (int) $pdo->lastInsertId() : false;
    } catch (Throwable $e) {
        error_log('cocurricularCreateClubAnnouncement error: ' . $e->getMessage());
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
    $isAiGenerated = isset($input['is_ai_generated']) ? (!empty($input['is_ai_generated']) ? 1 : 0) : null;
    $aiModel = trim((string) ($input['ai_model'] ?? ''));
    $aiGeneratedAt = trim((string) ($input['ai_generated_at'] ?? ''));

    if ($announcementId <= 0 || $title === '' || $content === '') {
        return false;
    }

    try {
        if ($isAiGenerated !== null) {
            $stmt = $pdo->prepare(
                'UPDATE club_announcements
                 SET title = :title,
                     content = :content,
                     posted_at = :posted_at,
                     is_pinned = :is_pinned,
                     attachment_path = :attachment_path,
                     is_ai_generated = :is_ai_generated,
                     ai_model = :ai_model,
                     ai_generated_at = :ai_generated_at,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            return $stmt->execute([
                ':title' => $title,
                ':content' => $content,
                ':posted_at' => $postedAt !== '' ? $postedAt : date('Y-m-d H:i:s'),
                ':is_pinned' => $isPinned,
                ':attachment_path' => $attachmentPath !== '' ? $attachmentPath : null,
                ':is_ai_generated' => $isAiGenerated,
                ':ai_model' => $isAiGenerated && $aiModel !== '' ? $aiModel : ($isAiGenerated ? 'gpt-4.1' : null),
                ':ai_generated_at' => $isAiGenerated && $aiGeneratedAt !== '' ? $aiGeneratedAt : ($isAiGenerated ? date('Y-m-d H:i:s') : null),
                ':id' => $announcementId,
            ]);
        }

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

        $sql = 'SELECT ep.id, ep.user_id, ep.reviewed_by, p.student_id, p.full_name, ep.registered_at, ep.status,
               ep.reviewed_at, ep.rejection_note, ep.event_id, ep.club_id,
               c.adviser_id, e.title AS event_title, c.club_name,
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

    $stmt = $pdo->prepare('SELECT id, status, rejection_note FROM club_event_participants WHERE event_id = ? AND user_id = ? ORDER BY registered_at DESC, id DESC LIMIT 1');
    $stmt->execute([$eventId, $userId]);
    $participation = $stmt->fetch();
    if (!$participation) {
        return null;
    }
    return [
        'id' => (int) $participation['id'],
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
             WHERE id = ?' . ($clubId > 0 ? ' AND club_id = ?' : '') . '
             LIMIT 1'
        );
        $eventStmt->execute($clubId > 0 ? [$eventId, $clubId] : [$eventId]);
        $event = $eventStmt->fetch();
        if (!$event || (string) $event['status'] !== 'Published' || (string) $event['event_date'] < date('Y-m-d')) {
            $pdo->rollBack();
            return ['status' => 'invalid_event'];
        }

        // Verify that student holds an Approved membership in this club
        if (!cocurricularHasApprovedMembershipForClub((int) $event['club_id'], $userId)) {
            $pdo->rollBack();
            return ['status' => 'not_member'];
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
                e.title AS event_title, c.club_name, c.adviser_id, u.full_name, u.student_id
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

function cocurricularReviewEventParticipant(int $participantId, int $reviewerUserId, string $status, string $rejectionNote = ''): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $participantId <= 0 || $reviewerUserId <= 0 || !in_array($status, ['Approved', 'Rejected'], true)) {
        return ['status' => 'error'];
    }
    if ($status === 'Rejected' && trim($rejectionNote) === '') {
        return ['status' => 'invalid_reason'];
    }

    $participant = cocurricularFetchEventParticipant($participantId);
    if (!$participant) {
        return ['status' => 'error'];
    }

    // Strict Authorization: Must be assigned Faculty Adviser for this club OR Co-Curricular Admin / OSA
    $isOsaAdmin = smsRoleAllowedForModule(['osa'], 'cocurricular');
    $isAssignedAdviser = cocurricularIsFacultyAdviserOfClub($reviewerUserId, (int) $participant['club_id']);

    if (!$isOsaAdmin && !$isAssignedAdviser) {
        return ['status' => 'unauthorized'];
    }

    if ((string) $participant['status'] !== 'Pending') {
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
    $executed = $stmt->execute([
        ':status' => $status,
        ':reviewed_by' => $reviewerUserId,
        ':rejection_note' => $status === 'Rejected' ? trim($rejectionNote) : null,
        ':id' => $participantId,
    ]);
    $updated = $executed && $stmt->rowCount() === 1;
    if ($updated) {
        if ($status === 'Approved') {
            @cocurricularNotifyParticipationApproved($participantId);
        } else {
            @cocurricularNotifyParticipationRejected($participantId, trim($rejectionNote));
        }

        if (function_exists('smsLogAudit')) {
            smsLogAudit(
                $reviewerUserId,
                'review_event_participant',
                "Participant #{$participantId} marked as {$status} by user #{$reviewerUserId}",
                'cocurricular'
            );
        }

        return ['status' => strtolower($status)];
    }

    return ['status' => 'invalid_transition'];
}

function cocurricularCreateClubEvent(array $input): int|bool
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

        $executed = $stmt->execute([
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
        $newId = $executed ? (int) $pdo->lastInsertId() : false;
        if ($newId && $status === 'Published') {
            @cocurricularNotifyEventPublished($newId);
        }
        return $newId;
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

function cocurricularFetchAttendanceClubs(?int $facultyUserId = null): array
{
    $pdo = cocurricularDb();
    if (!$pdo) {
        return [];
    }

    $whereClause = 'WHERE NOT EXISTS (
        SELECT 1 FROM club_event_participants newer
        WHERE newer.event_id = ep.event_id AND newer.user_id = ep.user_id
          AND (newer.registered_at > ep.registered_at
               OR (newer.registered_at = ep.registered_at AND newer.id > ep.id))
    )';
    $params = [];

    if ($facultyUserId !== null && $facultyUserId > 0) {
        $whereClause .= ' AND c.adviser_id = ?';
        $params[] = $facultyUserId;
    }

    $sql = "SELECT DISTINCT c.id, c.club_name
     FROM clubs c
     INNER JOIN club_events e ON e.club_id = c.id AND e.status = 'Published'
     INNER JOIN club_event_participants ep ON ep.event_id = e.id AND ep.status = 'Approved'
     {$whereClause}
     ORDER BY c.club_name ASC";

    if (empty($params)) {
        $stmt = $pdo->query($sql);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
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
        @cocurricularNotifyAttendanceOpen($sessionId);
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
            @cocurricularNotifyAttendanceClosed($attendanceId);
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
        @cocurricularNotifyAttendanceOpen($attendanceId);
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
    @cocurricularNotifyAttendanceClosed($attendanceId);
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
        @cocurricularNotifyAttendanceClosed($attendanceId);
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

        $executed = $stmt->execute([
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
        if ($executed && $status === 'Published') {
            @cocurricularNotifyEventPublished($eventId);
        }
        return $executed;
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
        $executed = $stmt->execute([
            ':status' => $newStatus,
            ':id' => $eventId,
        ]);
        if ($executed && $newStatus === 'Published') {
            @cocurricularNotifyEventPublished($eventId);
        }
        return $executed;
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

    $sql = 'SELECT a.*, c.id AS club_id, c.club_name, c.adviser, c.adviser_id, c.adviser_email, c.category
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

    $sql = 'SELECT a.*, c.id AS club_id, c.club_name, c.adviser, c.adviser_id, c.adviser_email, c.category
            FROM club_membership_applications a
            INNER JOIN clubs c ON c.id = a.club_id
            WHERE a.id = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$applicationId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Update a pending application status to Approved or Rejected.
 * Records reviewer ID, review timestamp, and rejection reason (if rejected).
 *
 * @param int $applicationId
 * @param string $newStatus 'Approved'|'Rejected'
 * @param int $reviewedBy User ID of reviewer (Faculty Adviser or OSA)
 * @param string|null $rejectionReason
 * @return bool
 */
function cocurricularUpdateMembershipApplicationStatus(
    int $applicationId,
    string $newStatus,
    int $reviewedBy = 0,
    ?string $rejectionReason = null
): bool {
    $pdo = cocurricularDb();
    if (!$pdo || $applicationId <= 0) {
        return false;
    }

    // Only allow specific transition statuses from Pending
    if (!in_array($newStatus, ['Approved', 'Rejected'], true)) {
        return false;
    }

    try {
        $sql = 'UPDATE club_membership_applications
                SET status = :status,
                    reviewed_at = NOW(),
                    reviewed_by = :reviewed_by,
                    rejection_reason = :rejection_reason,
                    updated_at = NOW()
                WHERE id = :id AND status = "Pending"';

        $stmt = $pdo->prepare($sql);
        $executed = $stmt->execute([
            ':status' => $newStatus,
            ':reviewed_by' => $reviewedBy > 0 ? $reviewedBy : null,
            ':rejection_reason' => ($newStatus === 'Rejected' && $rejectionReason !== null && trim($rejectionReason) !== '') ? trim($rejectionReason) : null,
            ':id' => $applicationId,
        ]);

        if ($executed && $stmt->rowCount() > 0) {
            if (function_exists('smsLogAudit')) {
                smsLogAudit(
                    $reviewedBy > 0 ? $reviewedBy : null,
                    'review_membership_application',
                    "Application #{$applicationId} marked as {$newStatus}",
                    'cocurricular'
                );
            }
            return true;
        }

        return false;
    } catch (Throwable $e) {
        error_log('cocurricularUpdateMembershipApplicationStatus error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Resign from an approved club membership.
 * Transitions status from 'Approved' to 'Resigned' and sets resigned_at = NOW().
 * Preserves the historical record and frees active membership constraint.
 *
 * @param int $clubId
 * @param int $userId
 * @return array ['success' => bool, 'message' => string]
 */
function cocurricularResignMembership(int $clubId, int $userId): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $clubId <= 0 || $userId <= 0) {
        return ['success' => false, 'message' => 'Invalid club or user.'];
    }

    try {
        $findStmt = $pdo->prepare('
            SELECT id, club_id, user_id, status
            FROM club_membership_applications
            WHERE club_id = :club_id AND user_id = :user_id AND status = "Approved"
            ORDER BY submitted_at DESC
            LIMIT 1
        ');
        $findStmt->execute([':club_id' => $clubId, ':user_id' => $userId]);
        $app = $findStmt->fetch();

        if (!$app) {
            return ['success' => false, 'message' => 'No active approved membership found for this club.'];
        }

        $appId = (int) $app['id'];

        $updateStmt = $pdo->prepare('
            UPDATE club_membership_applications
            SET status = "Resigned",
                resigned_at = NOW(),
                updated_at = NOW()
            WHERE id = :id AND status = "Approved"
        ');
        $res = $updateStmt->execute([':id' => $appId]);

        if ($res && $updateStmt->rowCount() > 0) {
            if (function_exists('smsLogAudit')) {
                smsLogAudit(
                    $userId,
                    'resign_club_membership',
                    "User #{$userId} resigned from club #{$clubId} (Application #{$appId})",
                    'cocurricular'
                );
            }
            return ['success' => true, 'message' => 'You have successfully resigned from the club.'];
        }

        return ['success' => false, 'message' => 'Unable to update membership status.'];
    } catch (Throwable $e) {
        error_log('cocurricularResignMembership error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Database error while processing resignation.'];
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

/**
 * Retrieve all clubs assigned to a specific Faculty Adviser.
 *
 * @param int $facultyUserId
 * @return array
 */
function cocurricularGetClubsForFacultyAdviser(int $facultyUserId): array
{
    cocurricularEnsureAdviserSchema();

    $pdo = cocurricularDb();
    if (!$pdo || $facultyUserId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare('
            SELECT c.*,
                   (SELECT COUNT(*) FROM club_membership_applications m WHERE m.club_id = c.id AND m.status = "Approved") AS member_count,
                   (SELECT COUNT(*) FROM club_membership_applications p WHERE p.club_id = c.id AND p.status = "Pending") AS pending_count,
                   (SELECT COUNT(*) FROM club_events e WHERE e.club_id = c.id) AS event_count,
                   (SELECT COUNT(*) FROM club_announcements a WHERE a.club_id = c.id) AS announcement_count
            FROM clubs c
            WHERE c.adviser_id = ?
            ORDER BY c.club_name ASC
        ');
        $stmt->execute([$facultyUserId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('cocurricularGetClubsForFacultyAdviser error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Check whether a user is the assigned Faculty Adviser for a specific club.
 *
 * @param int $facultyUserId
 * @param int $clubId
 * @return bool
 */
function cocurricularIsFacultyAdviserOfClub(int $facultyUserId, int $clubId): bool
{
    cocurricularEnsureAdviserSchema();

    $pdo = cocurricularDb();
    if (!$pdo || $facultyUserId <= 0 || $clubId <= 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT 1 FROM clubs WHERE id = ? AND adviser_id = ? LIMIT 1');
        $stmt->execute([$clubId, $facultyUserId]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('cocurricularIsFacultyAdviserOfClub error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Fetch approved members for a club enriched with user details from sms2_db.users.
 *
 * @param int $clubId
 * @return array
 */
function cocurricularGetClubApprovedMembersEnriched(int $clubId): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $clubId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare('
            SELECT a.id, a.club_id, a.user_id, a.student_id, a.reason_for_joining,
                   a.areas_of_interest, a.preferred_participation, a.status,
                   a.submitted_at, a.reviewed_at, c.club_name
            FROM club_membership_applications a
            INNER JOIN clubs c ON c.id = a.club_id
            WHERE a.club_id = ? AND a.status = "Approved"
            ORDER BY a.reviewed_at DESC, a.submitted_at DESC
        ');
        $stmt->execute([$clubId]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return [];
        }

        // Enrich with sms2_db.users
        $mainDb = db();
        if ($mainDb) {
            $userIds = array_values(array_unique(array_filter(array_column($rows, 'user_id'))));
            if (!empty($userIds)) {
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $userStmt = $mainDb->prepare("SELECT id, full_name, email, student_id FROM users WHERE id IN ($placeholders)");
                $userStmt->execute($userIds);
                $usersMap = [];
                foreach ($userStmt->fetchAll() as $u) {
                    $usersMap[(int) $u['id']] = $u;
                }

                foreach ($rows as &$row) {
                    $uid = (int) $row['user_id'];
                    $userData = $usersMap[$uid] ?? null;
                    $row['student_name'] = $userData['full_name'] ?? 'Student #' . $row['student_id'];
                    $row['student_email'] = $userData['email'] ?? '';
                    if (!empty($userData['student_id'])) {
                        $row['student_id'] = $userData['student_id'];
                    }
                }
                unset($row);
            }
        }

        return $rows;
    } catch (Throwable $e) {
        error_log('cocurricularGetClubApprovedMembersEnriched error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Fetch pending membership applications for an adviser's club.
 *
 * @param int $clubId
 * @return array
 */
function cocurricularGetClubPendingApplicationsEnriched(int $clubId): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $clubId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare('
            SELECT a.id, a.club_id, a.user_id, a.student_id, a.reason_for_joining,
                   a.areas_of_interest, a.preferred_participation, a.status,
                   a.submitted_at, c.club_name
            FROM club_membership_applications a
            INNER JOIN clubs c ON c.id = a.club_id
            WHERE a.club_id = ? AND a.status = "Pending"
            ORDER BY a.submitted_at ASC
        ');
        $stmt->execute([$clubId]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return [];
        }

        $mainDb = db();
        if ($mainDb) {
            $userIds = array_values(array_unique(array_filter(array_column($rows, 'user_id'))));
            if (!empty($userIds)) {
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $userStmt = $mainDb->prepare("SELECT id, full_name, email, student_id FROM users WHERE id IN ($placeholders)");
                $userStmt->execute($userIds);
                $usersMap = [];
                foreach ($userStmt->fetchAll() as $u) {
                    $usersMap[(int) $u['id']] = $u;
                }

                foreach ($rows as &$row) {
                    $uid = (int) $row['user_id'];
                    $userData = $usersMap[$uid] ?? null;
                    $row['student_name'] = $userData['full_name'] ?? 'Applicant #' . $row['student_id'];
                    $row['student_email'] = $userData['email'] ?? '';
                    if (!empty($userData['student_id'])) {
                        $row['student_id'] = $userData['student_id'];
                    }
                }
                unset($row);
            }
        }

        return $rows;
    } catch (Throwable $e) {
        error_log('cocurricularGetClubPendingApplicationsEnriched error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Fetch all events belonging to an assigned club with participant counts.
 *
 * @param int $clubId
 * @param string|null $status Optional filter
 * @return array
 */
function cocurricularFetchEventsForAssignedClub(int $clubId, ?string $status = null): array
{
    $pdo = cocurricularDb();
    if (!$pdo || $clubId <= 0) {
        return [];
    }

    try {
        $sql = '
            SELECT e.*, c.club_name,
                   (SELECT COUNT(*) FROM club_event_participants p WHERE p.event_id = e.id) AS participant_count
            FROM club_events e
            INNER JOIN clubs c ON c.id = e.club_id
            WHERE e.club_id = :club_id
        ';
        $params = [':club_id' => $clubId];

        if ($status !== null && $status !== '') {
            $sql .= ' AND e.status = :status';
            $params[':status'] = $status;
        }

        $sql .= ' ORDER BY e.is_pinned DESC, e.event_date DESC, e.start_time DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('cocurricularFetchEventsForAssignedClub error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Securely create a new event by the assigned Faculty Adviser for their club.
 *
 * @param int $clubId
 * @param int $facultyUserId
 * @param array $data
 * @return array{success: bool, message: string, event_id?: int}
 */
function cocurricularCreateAdviserEvent(int $clubId, int $facultyUserId, array $data): array
{
    if (!cocurricularIsFacultyAdviserOfClub($facultyUserId, $clubId) && !smsRoleAllowedForModule(['osa'], 'cocurricular')) {
        return ['success' => false, 'message' => 'Unauthorized. You are not the assigned adviser of this club.'];
    }

    $title = trim((string) ($data['title'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $eventType = trim((string) ($data['event_type'] ?? 'General Meeting'));
    $eventDate = trim((string) ($data['event_date'] ?? ''));
    $startTime = trim((string) ($data['start_time'] ?? ''));
    $endTime = trim((string) ($data['end_time'] ?? ''));
    $rawStatus = (string) ($data['status'] ?? 'Published');
    $status = in_array($rawStatus, ['Draft', 'Published', 'Completed', 'Cancelled'], true)
        ? $rawStatus
        : 'Published';
    $isPinned = !empty($data['is_pinned']) ? 1 : 0;

    if ($title === '') {
        return ['success' => false, 'message' => 'Please provide an event title.'];
    }
    if ($eventDate === '') {
        return ['success' => false, 'message' => 'Please provide an event date.'];
    }

    $pdo = cocurricularDb();
    if (!$pdo) {
        return ['success' => false, 'message' => 'Database unavailable.'];
    }

    try {
        $stmt = $pdo->prepare('
            INSERT INTO club_events (
                club_id, title, description, event_type, event_date,
                start_time, end_time, venue, status, is_pinned,
                created_by, created_at, updated_at
            ) VALUES (
                :club_id, :title, :description, :event_type, :event_date,
                :start_time, :end_time, :venue, :status, :is_pinned,
                :created_by, NOW(), NOW()
            )
        ');
        $stmt->execute([
            ':club_id'     => $clubId,
            ':title'       => $title,
            ':description' => $description,
            ':event_type'  => $eventType !== '' ? $eventType : 'Activity',
            ':event_date'  => $eventDate,
            ':start_time'  => $startTime !== '' ? $startTime : '09:00:00',
            ':end_time'    => $endTime !== '' ? $endTime : '12:00:00',
            ':venue'       => $venue !== '' ? $venue : 'Campus Venue',
            ':status'      => $status,
            ':is_pinned'   => $isPinned,
            ':created_by'  => $facultyUserId,
        ]);
        $eventId = (int) $pdo->lastInsertId();

        // Notify club members if published
        if ($status === 'Published' && function_exists('cocurricularNotifyEventPublished')) {
            try {
                cocurricularNotifyEventPublished($eventId);
            } catch (Throwable $e) {
                // Ignore notification failure
            }
        }

        if (function_exists('smsLogAudit')) {
            smsLogAudit(
                $facultyUserId,
                'create_event',
                "Event #{$eventId} created for club #{$clubId}",
                'cocurricular'
            );
        }

        return [
            'success'  => true,
            'message'  => 'Event successfully scheduled for your club.',
            'event_id' => $eventId,
        ];
    } catch (Throwable $e) {
        error_log('cocurricularCreateAdviserEvent error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to schedule event: ' . $e->getMessage()];
    }
}

/**
 * Securely update an event by the assigned Faculty Adviser or OSA.
 * Preserves the original club_id to prevent cross-club event reassignment.
 *
 * @param int $eventId
 * @param int $facultyUserId
 * @param array $data
 * @return array{success: bool, message: string}
 */
function cocurricularUpdateAdviserEvent(int $eventId, int $facultyUserId, array $data): array
{
    if ($eventId <= 0 || $facultyUserId <= 0) {
        return ['success' => false, 'message' => 'Invalid event or user.'];
    }

    $event = cocurricularGetEventById($eventId);
    if (!$event) {
        return ['success' => false, 'message' => 'Event not found.'];
    }

    $actualClubId = (int) $event['club_id'];
    $isOsa = smsRoleAllowedForModule(['osa'], 'cocurricular');
    $isAdviser = cocurricularIsFacultyAdviserOfClub($facultyUserId, $actualClubId);

    if (!$isOsa && !$isAdviser) {
        return ['success' => false, 'message' => 'Unauthorized. You are not the assigned adviser of this club.'];
    }

    $title = trim((string) ($data['title'] ?? $event['title']));
    $description = trim((string) ($data['description'] ?? $event['description']));
    $eventType = trim((string) ($data['event_type'] ?? $event['event_type']));
    $eventDate = trim((string) ($data['event_date'] ?? $event['event_date']));
    $startTime = trim((string) ($data['start_time'] ?? $event['start_time']));
    $endTime = trim((string) ($data['end_time'] ?? $event['end_time']));
    $venue = trim((string) ($data['venue'] ?? ($event['venue'] ?? '')));
    $isPinned = isset($data['is_pinned']) ? (!empty($data['is_pinned']) ? 1 : 0) : (int) $event['is_pinned'];

    $status = in_array((string) ($data['status'] ?? $event['status']), ['Draft', 'Published', 'Completed', 'Cancelled'], true)
        ? (string) ($data['status'] ?? $event['status'])
        : (string) $event['status'];

    if ($title === '') {
        return ['success' => false, 'message' => 'Please provide an event title.'];
    }
    if ($eventDate === '') {
        return ['success' => false, 'message' => 'Please provide an event date.'];
    }
    if ($startTime === '' || $endTime === '') {
        return ['success' => false, 'message' => 'Please provide start and end times.'];
    }
    if ($startTime !== '' && $endTime !== '' && $endTime < $startTime) {
        return ['success' => false, 'message' => 'End time must not be earlier than start time.'];
    }

    $pdo = cocurricularDb();
    if (!$pdo) {
        return ['success' => false, 'message' => 'Database unavailable.'];
    }

    try {
        // club_id is strictly preserved as $actualClubId (never user input)
        $stmt = $pdo->prepare('
            UPDATE club_events
            SET title = :title,
                description = :description,
                event_type = :event_type,
                event_date = :event_date,
                start_time = :start_time,
                end_time = :end_time,
                venue = :venue,
                status = :status,
                is_pinned = :is_pinned,
                updated_at = NOW()
            WHERE id = :id AND club_id = :club_id
        ');
        $executed = $stmt->execute([
            ':title'       => $title,
            ':description' => $description,
            ':event_type'  => $eventType !== '' ? $eventType : 'Activity',
            ':event_date'  => $eventDate,
            ':start_time'  => $startTime,
            ':end_time'    => $endTime,
            ':venue'       => $venue !== '' ? $venue : 'Campus Venue',
            ':status'      => $status,
            ':is_pinned'   => $isPinned,
            ':id'          => $eventId,
            ':club_id'     => $actualClubId,
        ]);

        if ($executed) {
            if ($status === 'Published' && (string) $event['status'] !== 'Published' && function_exists('cocurricularNotifyEventPublished')) {
                try {
                    @cocurricularNotifyEventPublished($eventId);
                } catch (Throwable $e) {}
            }

            if (function_exists('smsLogAudit')) {
                smsLogAudit(
                    $facultyUserId,
                    'update_event',
                    "Event #{$eventId} updated for club #{$actualClubId}",
                    'cocurricular'
                );
            }

            return ['success' => true, 'message' => 'Event updated successfully.'];
        }

        return ['success' => false, 'message' => 'Unable to update event.'];
    } catch (Throwable $e) {
        error_log('cocurricularUpdateAdviserEvent error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update event: ' . $e->getMessage()];
    }
}

/**
 * Update event status (Draft, Published, Completed, Cancelled) with adviser/OSA authorization.
 *
 * @param int $eventId
 * @param string $newStatus 'Draft'|'Published'|'Completed'|'Cancelled'
 * @param int $userId
 * @return array{success: bool, message: string}
 */
function cocurricularSetEventStatus(int $eventId, string $newStatus, int $userId): array
{
    if ($eventId <= 0 || $userId <= 0) {
        return ['success' => false, 'message' => 'Invalid event or user.'];
    }

    if (!in_array($newStatus, ['Draft', 'Published', 'Completed', 'Cancelled'], true)) {
        return ['success' => false, 'message' => 'Invalid event status requested.'];
    }

    $event = cocurricularGetEventById($eventId);
    if (!$event) {
        return ['success' => false, 'message' => 'Event not found.'];
    }

    $actualClubId = (int) $event['club_id'];
    $isOsa = smsRoleAllowedForModule(['osa'], 'cocurricular');
    $isAdviser = cocurricularIsFacultyAdviserOfClub($userId, $actualClubId);

    if (!$isOsa && !$isAdviser) {
        return ['success' => false, 'message' => 'Unauthorized. You cannot modify events for this club.'];
    }

    $pdo = cocurricularDb();
    if (!$pdo) {
        return ['success' => false, 'message' => 'Database unavailable.'];
    }

    try {
        $stmt = $pdo->prepare('UPDATE club_events SET status = :status, updated_at = NOW() WHERE id = :id');
        $executed = $stmt->execute([
            ':status' => $newStatus,
            ':id'     => $eventId,
        ]);

        if ($executed && $stmt->rowCount() > 0) {
            if ($newStatus === 'Published' && (string) $event['status'] !== 'Published' && function_exists('cocurricularNotifyEventPublished')) {
                try {
                    @cocurricularNotifyEventPublished($eventId);
                } catch (Throwable $e) {}
            }

            if (function_exists('smsLogAudit')) {
                smsLogAudit(
                    $userId,
                    'set_event_status',
                    "Event #{$eventId} status changed to {$newStatus}",
                    'cocurricular'
                );
            }

            return ['success' => true, 'message' => "Event marked as {$newStatus}."];
        }

        return ['success' => false, 'message' => 'Event status was already set or could not be updated.'];
    } catch (Throwable $e) {
        error_log('cocurricularSetEventStatus error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update event status: ' . $e->getMessage()];
    }
}

/**
 * Securely create a new announcement by the assigned Faculty Adviser for their club.
 *
 * @param int $clubId
 * @param int $facultyUserId
 * @param array $data
 * @return array{success: bool, message: string, announcement_id?: int}
 */
function cocurricularCreateAdviserAnnouncement(int $clubId, int $facultyUserId, array $data): array
{
    if (!cocurricularIsFacultyAdviserOfClub($facultyUserId, $clubId)) {
        return ['success' => false, 'message' => 'Unauthorized. You are not the assigned adviser of this club.'];
    }

    $title = trim((string) ($data['title'] ?? ''));
    $content = trim((string) ($data['content'] ?? ''));
    $isPinned = !empty($data['is_pinned']) ? 1 : 0;

    if ($title === '') {
        return ['success' => false, 'message' => 'Please provide an announcement title.'];
    }
    if ($content === '') {
        return ['success' => false, 'message' => 'Please provide announcement content.'];
    }

    $isAiGenerated = !empty($data['is_ai_generated']) ? 1 : 0;
    $aiModel = $isAiGenerated ? trim((string) ($data['ai_model'] ?? 'gpt-4.1')) : null;
    $aiGeneratedAt = $isAiGenerated ? trim((string) ($data['ai_generated_at'] ?? date('Y-m-d H:i:s'))) : null;

    $pdo = cocurricularDb();
    if (!$pdo) {
        return ['success' => false, 'message' => 'Database unavailable.'];
    }

    try {
        $stmt = $pdo->prepare('
            INSERT INTO club_announcements (
                club_id, title, content, is_pinned, is_ai_generated, ai_model, ai_generated_at, posted_at, created_at, updated_at
            ) VALUES (
                :club_id, :title, :content, :is_pinned, :is_ai_generated, :ai_model, :ai_generated_at, NOW(), NOW(), NOW()
            )
        ');
        $stmt->execute([
            ':club_id'         => $clubId,
            ':title'           => $title,
            ':content'         => $content,
            ':is_pinned'       => $isPinned,
            ':is_ai_generated' => $isAiGenerated,
            ':ai_model'        => $aiModel,
            ':ai_generated_at' => $aiGeneratedAt,
        ]);
        $announcementId = (int) $pdo->lastInsertId();

        // Notify club members
        if (function_exists('cocurricularNotifyAnnouncementPublished')) {
            try {
                cocurricularNotifyAnnouncementPublished($announcementId);
            } catch (Throwable $e) {
                // Ignore notification failure
            }
        }

        return [
            'success'         => true,
            'message'         => 'Announcement successfully posted to your club.',
            'announcement_id' => $announcementId,
        ];
    } catch (Throwable $e) {
        error_log('cocurricularCreateAdviserAnnouncement error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to post announcement: ' . $e->getMessage()];
    }
}
