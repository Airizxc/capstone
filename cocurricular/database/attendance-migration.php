<?php
/**
 * Idempotent Co-Curricular attendance migration.
 * CLI: C:\\xampp\\php\\php.exe modules/cocurricular/database/attendance-migration.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$pdo = getDatabaseConnection();
$pdo->exec('CREATE DATABASE IF NOT EXISTS `cocurricular_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE `cocurricular_db`');

$pdo->exec("CREATE TABLE IF NOT EXISTS club_event_attendance (
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    event_id int(10) unsigned NOT NULL,
    opened_at datetime DEFAULT NULL, closed_at datetime DEFAULT NULL,
    status enum('Not Started','Open','Closed') NOT NULL DEFAULT 'Not Started',
    location varchar(191) DEFAULT NULL, session_date date DEFAULT NULL,
    session_start_time time DEFAULT NULL, session_end_time time DEFAULT NULL,
    attendance_open_at datetime DEFAULT NULL, attendance_deadline datetime DEFAULT NULL,
    finalized_at datetime DEFAULT NULL,
    attendance_method enum('QR Code','Attendance Code','QR + Attendance Code') NOT NULL DEFAULT 'QR Code',
    access_token varchar(64) DEFAULT NULL,
    attendance_code varchar(12) DEFAULT NULL,
    created_by int(10) unsigned NOT NULL,
    created_at datetime NOT NULL DEFAULT current_timestamp(),
    updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (id), KEY idx_attendance_event (event_id), UNIQUE KEY uq_attendance_access_token (access_token), UNIQUE KEY uq_attendance_code (attendance_code), KEY idx_attendance_created_by (created_by),
    CONSTRAINT fk_attendance_event FOREIGN KEY (event_id) REFERENCES club_events (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_created_by FOREIGN KEY (created_by) REFERENCES sms2_db.users (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS club_event_attendance_records (
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    attendance_id int(10) unsigned NOT NULL, event_id int(10) unsigned NOT NULL,
    participant_id int(10) unsigned NOT NULL, user_id int(10) unsigned NOT NULL,
    attendance_status enum('Not Marked','Present','Absent') NOT NULL DEFAULT 'Not Marked',
    marked_at datetime DEFAULT NULL, marked_by int(10) unsigned DEFAULT NULL, remarks text DEFAULT NULL,
    created_at datetime NOT NULL DEFAULT current_timestamp(),
    updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (id), UNIQUE KEY uq_attendance_record_participant (attendance_id, participant_id),
    UNIQUE KEY uq_attendance_record_user (attendance_id, user_id), KEY idx_attendance_record_event (event_id),
    KEY idx_attendance_record_participant (participant_id), KEY idx_attendance_record_marked_by (marked_by),
    CONSTRAINT fk_attendance_record_attendance FOREIGN KEY (attendance_id) REFERENCES club_event_attendance (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_record_event FOREIGN KEY (event_id) REFERENCES club_events (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_record_participant FOREIGN KEY (participant_id) REFERENCES club_event_participants (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_record_user FOREIGN KEY (user_id) REFERENCES sms2_db.users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_record_marked_by FOREIGN KEY (marked_by) REFERENCES sms2_db.users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$columns = [
    'club_event_attendance' => [
        'event_id' => 'int(10) unsigned NOT NULL', 'opened_at' => 'datetime DEFAULT NULL',
        'closed_at' => 'datetime DEFAULT NULL', 'status' => "enum('Not Started','Open','Closed') NOT NULL DEFAULT 'Not Started'",
        'location' => 'varchar(191) DEFAULT NULL', 'session_date' => 'date DEFAULT NULL',
        'session_start_time' => 'time DEFAULT NULL', 'session_end_time' => 'time DEFAULT NULL',
        'attendance_open_at' => 'datetime DEFAULT NULL', 'attendance_deadline' => 'datetime DEFAULT NULL',
        'finalized_at' => 'datetime DEFAULT NULL',
        'attendance_method' => "enum('QR Code','Attendance Code','QR + Attendance Code') NOT NULL DEFAULT 'QR Code'", 'access_token' => 'varchar(64) DEFAULT NULL', 'attendance_code' => 'varchar(12) DEFAULT NULL',
        'created_by' => 'int(10) unsigned NOT NULL', 'created_at' => 'datetime NOT NULL DEFAULT current_timestamp()',
        'updated_at' => 'datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
    ],
    'club_event_attendance_records' => [
        'attendance_id' => 'int(10) unsigned NOT NULL', 'event_id' => 'int(10) unsigned NOT NULL',
        'participant_id' => 'int(10) unsigned NOT NULL', 'user_id' => 'int(10) unsigned NOT NULL',
        'attendance_status' => "enum('Not Marked','Present','Absent') NOT NULL DEFAULT 'Not Marked'",
        'marked_at' => 'datetime DEFAULT NULL', 'marked_by' => 'int(10) unsigned DEFAULT NULL',
        'remarks' => 'text DEFAULT NULL', 'created_at' => 'datetime NOT NULL DEFAULT current_timestamp()',
        'updated_at' => 'datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
    ],
];
$checkColumn = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
foreach ($columns as $table => $definitions) {
    foreach ($definitions as $column => $definition) {
        $checkColumn->execute([$table, $column]);
        if ((int) $checkColumn->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}

$pdo->exec("UPDATE club_event_attendance_records SET attendance_status = 'Present' WHERE attendance_status = 'Late'");
$recordStatusColumn = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'club_event_attendance_records' AND column_name = 'attendance_status'")->fetchColumn();
if (is_string($recordStatusColumn) && strpos($recordStatusColumn, "'Late'") !== false) {
    $pdo->exec("ALTER TABLE club_event_attendance_records MODIFY attendance_status enum('Not Marked','Present','Absent') NOT NULL DEFAULT 'Not Marked'");
}

$missingCodes = $pdo->query('SELECT id FROM club_event_attendance WHERE attendance_code IS NULL')->fetchAll(PDO::FETCH_COLUMN);
$codeCheck = $pdo->prepare('SELECT COUNT(*) FROM club_event_attendance WHERE attendance_code = ?');
$codeUpdate = $pdo->prepare('UPDATE club_event_attendance SET attendance_code = ? WHERE id = ? AND attendance_code IS NULL');
foreach ($missingCodes as $attendanceId) {
    do {
        $code = (string) random_int(100000, 999999);
        $codeCheck->execute([$code]);
    } while ((int) $codeCheck->fetchColumn() > 0);
    $codeUpdate->execute([$code, (int) $attendanceId]);
}

$oldEventUnique = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'club_event_attendance' AND index_name = 'uq_attendance_event'")->fetchColumn();
$eventIndex = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'club_event_attendance' AND index_name = 'idx_attendance_event'")->fetchColumn();
if ((int) $eventIndex === 0) {
    $pdo->exec('ALTER TABLE club_event_attendance ADD KEY idx_attendance_event (event_id)');
}
if ((int) $oldEventUnique > 0) {
    $pdo->exec('ALTER TABLE club_event_attendance DROP INDEX uq_attendance_event');
}
$methodColumn = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'club_event_attendance' AND column_name = 'attendance_method'")->fetchColumn();
if (is_string($methodColumn) && strpos($methodColumn, "'QR + Attendance Code'") === false) {
    $pdo->exec("ALTER TABLE club_event_attendance MODIFY attendance_method enum('QR Code','Attendance Code','QR + Attendance Code') NOT NULL DEFAULT 'QR Code'");
}
$indexes = [
    ['club_event_attendance', 'uq_attendance_access_token', 'UNIQUE KEY uq_attendance_access_token (access_token)'],
    ['club_event_attendance', 'uq_attendance_code', 'UNIQUE KEY uq_attendance_code (attendance_code)'],
    ['club_event_attendance', 'idx_attendance_created_by', 'KEY idx_attendance_created_by (created_by)'],
    ['club_event_attendance_records', 'uq_attendance_record_participant', 'UNIQUE KEY uq_attendance_record_participant (attendance_id, participant_id)'],
    ['club_event_attendance_records', 'uq_attendance_record_user', 'UNIQUE KEY uq_attendance_record_user (attendance_id, user_id)'],
    ['club_event_attendance_records', 'idx_attendance_record_event', 'KEY idx_attendance_record_event (event_id)'],
    ['club_event_attendance_records', 'idx_attendance_record_participant', 'KEY idx_attendance_record_participant (participant_id)'],
    ['club_event_attendance_records', 'idx_attendance_record_marked_by', 'KEY idx_attendance_record_marked_by (marked_by)'],
];
$checkIndex = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
foreach ($indexes as [$table, $index, $definition]) {
    $checkIndex->execute([$table, $index]);
    if ((int) $checkIndex->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD $definition");
    }
}

echo "Attendance schema is ready. Existing data was preserved.\n";
