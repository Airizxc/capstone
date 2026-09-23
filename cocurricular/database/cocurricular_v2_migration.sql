-- Co-Curricular Module Schema Migration v2
-- AI Announcements & Notification Database Foundation

USE `cocurricular_db`;

-- 1. Co-Curricular Notifications Table
CREATE TABLE IF NOT EXISTS `cocurricular_notifications` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `message` TEXT NOT NULL,
  `type` VARCHAR(60) NOT NULL,
  `related_id` INT(10) UNSIGNED DEFAULT NULL,
  `related_module` VARCHAR(60) NOT NULL DEFAULT 'cocurricular',
  `destination_url` VARCHAR(255) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cocurricular_notif_user` (`user_id`, `is_read`, `created_at`),
  KEY `idx_cocurricular_notif_type_rel` (`type`, `related_id`),
  CONSTRAINT `fk_cocurricular_notif_user` FOREIGN KEY (`user_id`) REFERENCES `sms2_db`.`users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. FCM Device Tokens Table
CREATE TABLE IF NOT EXISTS `cocurricular_fcm_tokens` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `fcm_token` VARCHAR(255) NOT NULL,
  `device_type` VARCHAR(50) DEFAULT 'web',
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_used_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_fcm_token` (`user_id`, `fcm_token`),
  KEY `idx_cocurricular_fcm_user` (`user_id`, `is_active`),
  CONSTRAINT `fk_cocurricular_fcm_user` FOREIGN KEY (`user_id`) REFERENCES `sms2_db`.`users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Idempotent AI Columns Addition for club_announcements
SET @dbname = DATABASE();
SET @tablename = 'club_announcements';
SET @columnname = 'is_ai_generated';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `club_announcements` ADD COLUMN `is_ai_generated` TINYINT(1) NOT NULL DEFAULT 0 AFTER `attachment_path`'
));
PREPARE add_is_ai_generated FROM @preparedStatement;
EXECUTE add_is_ai_generated;
DEALLOCATE PREPARE add_is_ai_generated;

SET @columnname = 'ai_model';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `club_announcements` ADD COLUMN `ai_model` VARCHAR(60) DEFAULT NULL AFTER `is_ai_generated`'
));
PREPARE add_ai_model FROM @preparedStatement;
EXECUTE add_ai_model;
DEALLOCATE PREPARE add_ai_model;

SET @columnname = 'ai_generated_at';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `club_announcements` ADD COLUMN `ai_generated_at` DATETIME DEFAULT NULL AFTER `ai_model`'
));
PREPARE add_ai_generated_at FROM @preparedStatement;
EXECUTE add_ai_generated_at;
DEALLOCATE PREPARE add_ai_generated_at;
