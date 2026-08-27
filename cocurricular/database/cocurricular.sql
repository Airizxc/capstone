-- Co-Curricular Module Database Schema
-- Use this file to create the clubs and membership tables for the student-side co-curricular module.

CREATE DATABASE IF NOT EXISTS `cocurricular_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `cocurricular_db`;

-- Clubs table
CREATE TABLE IF NOT EXISTS `clubs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `club_name` varchar(191) NOT NULL,
  `category` varchar(120) NOT NULL,
  `description` text NOT NULL,
  `adviser` varchar(150) NOT NULL,
  `adviser_email` varchar(190) DEFAULT NULL,
  `contact_phone` varchar(60) DEFAULT NULL,
  `status` enum('Active','Pending','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_club_name` (`club_name`),
  KEY `idx_club_status` (`status`),
  KEY `idx_club_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Club officers table
CREATE TABLE IF NOT EXISTS `club_officers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `club_id` int(10) unsigned NOT NULL,
  `officer_name` varchar(150) NOT NULL,
  `position` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_officer_club` (`club_id`),
  CONSTRAINT `fk_officer_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Club membership applications table
CREATE TABLE IF NOT EXISTS `club_membership_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `club_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `student_id` varchar(40) NOT NULL,
  `reason_for_joining` text NOT NULL,
  `areas_of_interest` text NOT NULL,
  `preferred_participation` varchar(120) NOT NULL,
  `agreement` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_application_pending` (`club_id`,`user_id`,`status`),
  KEY `idx_membership_club` (`club_id`),
  KEY `idx_membership_user` (`user_id`),
  CONSTRAINT `fk_application_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Club announcements table
CREATE TABLE IF NOT EXISTS `club_announcements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `club_id` int(10) unsigned NOT NULL,
  `title` varchar(191) NOT NULL,
  `content` text NOT NULL,
  `posted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `attachment_path` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_announcement_club` (`club_id`),
  KEY `idx_announcement_pinned_date` (`club_id`,`is_pinned`,`posted_at`),
  CONSTRAINT `fk_announcement_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Club events table
CREATE TABLE IF NOT EXISTS `club_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `club_id` int(10) unsigned NOT NULL,
  `title` varchar(191) NOT NULL,
  `description` text NOT NULL,
  `event_type` varchar(120) NOT NULL,
  `event_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `venue` varchar(191) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `status` enum('Draft','Published','Completed','Cancelled') NOT NULL DEFAULT 'Draft',
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_event_club_date` (`club_id`,`event_date`),
  KEY `idx_event_status` (`status`),
  KEY `idx_event_hero` (`club_id`,`status`,`is_pinned`,`event_date`),
  KEY `idx_event_created_by` (`created_by`),
  CONSTRAINT `fk_event_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event participation / interest records
CREATE TABLE IF NOT EXISTS `club_event_participants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `club_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `registered_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `rejection_note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_event_participant_user_event` (`user_id`,`event_id`),
  KEY `idx_event_participant_event` (`event_id`),
  KEY `idx_event_participant_club` (`club_id`),
  KEY `idx_event_participant_status` (`event_id`,`status`),
  CONSTRAINT `fk_event_participant_event` FOREIGN KEY (`event_id`) REFERENCES `club_events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_event_participant_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_event_participant_user` FOREIGN KEY (`user_id`) REFERENCES `sms2_db`.`users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attendance session / roster snapshot
CREATE TABLE IF NOT EXISTS `club_event_attendance` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `opened_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `status` enum('Not Started','Open','Closed') NOT NULL DEFAULT 'Not Started',
  `location` varchar(191) DEFAULT NULL,
  `session_date` date DEFAULT NULL,
  `session_start_time` time DEFAULT NULL,
  `session_end_time` time DEFAULT NULL,
  `attendance_open_at` datetime DEFAULT NULL,
  `attendance_deadline` datetime DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `attendance_method` enum('QR Code','Attendance Code','QR + Attendance Code') NOT NULL DEFAULT 'QR Code',
  `access_token` varchar(64) DEFAULT NULL,
  `attendance_code` varchar(12) DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_attendance_event` (`event_id`),
  UNIQUE KEY `uq_attendance_access_token` (`access_token`),
  UNIQUE KEY `uq_attendance_code` (`attendance_code`),
  KEY `idx_attendance_created_by` (`created_by`),
  CONSTRAINT `fk_attendance_event` FOREIGN KEY (`event_id`) REFERENCES `club_events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_created_by` FOREIGN KEY (`created_by`) REFERENCES `sms2_db`.`users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `club_event_attendance_records` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `attendance_id` int(10) unsigned NOT NULL,
  `event_id` int(10) unsigned NOT NULL,
  `participant_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `attendance_status` enum('Not Marked','Present','Absent') NOT NULL DEFAULT 'Not Marked',
  `marked_at` datetime DEFAULT NULL,
  `marked_by` int(10) unsigned DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_record_participant` (`attendance_id`,`participant_id`),
  UNIQUE KEY `uq_attendance_record_user` (`attendance_id`,`user_id`),
  KEY `idx_attendance_record_event` (`event_id`),
  KEY `idx_attendance_record_participant` (`participant_id`),
  KEY `idx_attendance_record_marked_by` (`marked_by`),
  CONSTRAINT `fk_attendance_record_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `club_event_attendance` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_record_event` FOREIGN KEY (`event_id`) REFERENCES `club_events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_record_participant` FOREIGN KEY (`participant_id`) REFERENCES `club_event_participants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_record_user` FOREIGN KEY (`user_id`) REFERENCES `sms2_db`.`users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_record_marked_by` FOREIGN KEY (`marked_by`) REFERENCES `sms2_db`.`users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed data
INSERT INTO `clubs` (`club_name`, `category`, `description`, `adviser`, `adviser_email`, `contact_phone`, `status`) VALUES
('CCS Programming Guild', 'Academic', 'A student organization dedicated to coding, hackathons, and collaborative software development projects.', 'Prof. Ana Reyes', 'ana.reyes@bcp.edu.ph', '0917-555-0101', 'Active'),
('Future Educators Club', 'Academic', 'Supporting aspiring teachers via workshops, mentoring, teaching demonstrations, and education advocacy.', 'Prof. Clara Santos', 'clara.santos@bcp.edu.ph', '0917-555-0102', 'Active'),
('Young Entrepreneurs Society', 'Business', 'A business-minded club that fosters entrepreneurial skills, startup planning, and small enterprise projects.', 'Prof. Mark Lim', 'mark.lim@bcp.edu.ph', '0917-555-0103', 'Active'),
('Criminology Circle', 'Safety & Security', 'A community for criminology students to study crime prevention, research case studies, and organize public safety outreach.', 'Prof. Joel Cruz', 'joel.cruz@bcp.edu.ph', '0917-555-0104', 'Pending'),
('Sports Club', 'Athletics', 'Connecting students through team sports, fitness challenges, and intercollegiate athletic events.', 'Prof. Ramil Santos', 'ramil.santos@bcp.edu.ph', '0917-555-0105', 'Active');

INSERT INTO `club_officers` (`club_id`, `officer_name`, `position`) VALUES
(1, 'Sofia Reyes', 'President'),
(1, 'Luis Fernandez', 'Vice President'),
(1, 'Maria Garcia', 'Secretary'),
(2, 'Mark Villanueva', 'President'),
(2, 'Angela Cruz', 'Treasurer'),
(3, 'Jose Ramirez', 'President'),
(3, 'Jules Ramos', 'Vice President'),
(4, 'Carlos Mendoza', 'President'),
(4, 'Nina Santos', 'Secretary'),
(5, 'Gerry Valdez', 'President'),
(5, 'Lea Santos', 'Events Coordinator');
