-- System settings, facilities, announcements, follow-up notes extension
-- Run: php scripts/dev/apply_admin_modules_migration.php

CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `updated_by` INT UNSIGNED NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
    ('AI_CONFIDENCE_THRESHOLD', '0.85'),
    ('MAX_APPOINTMENTS_PER_PROVIDER', '15'),
    ('SESSION_TIMEOUT_MINUTES', '60')
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

CREATE TABLE IF NOT EXISTS `facilities` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `facility_name` VARCHAR(150) NOT NULL,
    `facility_type` ENUM('Hospital','Clinic','Laboratory','Specialist','ABTC','TB-DOTS','Other') NOT NULL DEFAULT 'Hospital',
    `address` VARCHAR(255) NULL,
    `contact_number` VARCHAR(30) NULL,
    `latitude` DECIMAL(10, 8) NULL,
    `longitude` DECIMAL(11, 8) NULL,
    `status` ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_facility_status` (`status`),
    KEY `idx_facility_type` (`facility_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `facilities` (`facility_name`, `facility_type`, `address`, `status`) VALUES
    ('Bago City Hospital', 'Hospital', 'Bago City, Negros Occidental', 'active'),
    ('City Health Office', 'Clinic', 'Bago City Hall Complex', 'active'),
    ('ABTC - Bago City', 'ABTC', 'Bago City Health District', 'active'),
    ('TB-DOTS Center', 'TB-DOTS', 'Bago City Health Office', 'active')
ON DUPLICATE KEY UPDATE `facility_name` = VALUES(`facility_name`);

CREATE TABLE IF NOT EXISTS `announcements` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `target_roles` JSON NOT NULL,
    `status` ENUM('draft','scheduled','published','expired') NOT NULL DEFAULT 'draft',
    `publish_at` DATETIME NULL,
    `expire_at` DATETIME NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ann_status` (`status`),
    KEY `idx_ann_publish` (`publish_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `barangays`
    ADD COLUMN IF NOT EXISTS `latitude` DECIMAL(10, 8) NULL,
    ADD COLUMN IF NOT EXISTS `longitude` DECIMAL(11, 8) NULL,
    ADD COLUMN IF NOT EXISTS `psgc_code` VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS `archived_at` DATETIME NULL;

ALTER TABLE `followups`
    ADD COLUMN IF NOT EXISTS `notes` TEXT NULL AFTER `message`;

ALTER TABLE `digital_referrals`
    ADD COLUMN IF NOT EXISTS `facility_id` INT UNSIGNED NULL AFTER `facility_name`;
