-- MedConnect Announcement Management System
-- Extends announcements table and adds media library + barangay targeting.

CREATE TABLE IF NOT EXISTS `announcements` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `target_roles` JSON NOT NULL,
    `status` ENUM('draft','scheduled','published','archived','expired') NOT NULL DEFAULT 'draft',
    `publish_at` DATETIME NULL,
    `expire_at` DATETIME NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ann_status` (`status`),
    KEY `idx_ann_publish` (`publish_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extended columns (safe to re-run via announcement_service schema ensure)
ALTER TABLE `announcements`
    ADD COLUMN IF NOT EXISTS `subtitle` VARCHAR(255) NULL AFTER `title`,
    ADD COLUMN IF NOT EXISTS `category` VARCHAR(80) NOT NULL DEFAULT 'general' AFTER `subtitle`,
    ADD COLUMN IF NOT EXISTS `short_description` VARCHAR(500) NULL AFTER `category`,
    ADD COLUMN IF NOT EXISTS `content` LONGTEXT NULL AFTER `short_description`,
    ADD COLUMN IF NOT EXISTS `banner_image` VARCHAR(512) NULL AFTER `content`,
    ADD COLUMN IF NOT EXISTS `attachment` VARCHAR(512) NULL AFTER `banner_image`,
    ADD COLUMN IF NOT EXISTS `author_id` INT UNSIGNED NULL AFTER `attachment`,
    ADD COLUMN IF NOT EXISTS `target_audience` JSON NULL AFTER `target_roles`,
    ADD COLUMN IF NOT EXISTS `priority` ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal' AFTER `target_audience`,
    ADD COLUMN IF NOT EXISTS `is_pinned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `priority`,
    ADD COLUMN IF NOT EXISTS `is_featured` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_pinned`,
    ADD COLUMN IF NOT EXISTS `view_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_featured`,
    ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL AFTER `updated_at`;

CREATE TABLE IF NOT EXISTS `announcement_barangays` (
    `announcement_id` BIGINT UNSIGNED NOT NULL,
    `barangay_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`announcement_id`, `barangay_id`),
    KEY `idx_ab_barangay` (`barangay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `media_library` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(512) NOT NULL,
    `file_type` ENUM('image','document') NOT NULL DEFAULT 'image',
    `mime_type` VARCHAR(100) NOT NULL,
    `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
    `alt_text` VARCHAR(255) NULL,
    `uploaded_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_media_type` (`file_type`),
    KEY `idx_media_uploaded` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
