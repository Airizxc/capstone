-- Co-Curricular Module Schema Migration v3
-- Assign Faculty Adviser Database Foundation
-- Links cocurricular_db.clubs with existing faculty users in sms2_db.users

USE `cocurricular_db`;

-- 1. Add adviser_id column to clubs if it doesn't already exist
SET @dbname = DATABASE();
SET @tablename = 'clubs';
SET @columnname = 'adviser_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `clubs` ADD COLUMN `adviser_id` INT(10) UNSIGNED DEFAULT NULL AFTER `description`, ADD KEY `idx_club_adviser_id` (`adviser_id`)'
));
PREPARE add_adviser_id FROM @preparedStatement;
EXECUTE add_adviser_id;
DEALLOCATE PREPARE add_adviser_id;

-- 2. Add foreign key constraint to sms2_db.users(id) if it doesn't exist
SET @fk_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = @tablename
    AND CONSTRAINT_NAME = 'fk_club_adviser_user'
);
SET @fk_sql = IF(@fk_exists = 0,
  'ALTER TABLE `clubs` ADD CONSTRAINT `fk_club_adviser_user` FOREIGN KEY (`adviser_id`) REFERENCES `sms2_db`.`users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE add_fk FROM @fk_sql;
EXECUTE add_fk;
DEALLOCATE PREPARE add_fk;
