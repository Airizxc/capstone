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
