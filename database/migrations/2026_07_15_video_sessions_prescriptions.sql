-- 2026-07-15: Ensure video consultation rooms + e-prescription storage exist

CREATE TABLE IF NOT EXISTS `video_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `consultation_id` INT(11) UNSIGNED NOT NULL,
    `room_token` VARCHAR(64) NOT NULL,
    `status` ENUM('active','ended') NOT NULL DEFAULT 'active',
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ended_at` DATETIME NULL,
    `recording_path` VARCHAR(500) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_video_room_token` (`room_token`),
    KEY `idx_video_consultation` (`consultation_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- For existing installs created before recording_path existed:
-- ALTER TABLE video_sessions ADD COLUMN recording_path VARCHAR(500) NULL AFTER ended_at;

CREATE TABLE IF NOT EXISTS `prescriptions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `consultation_id` INT(11) UNSIGNED NULL,
    `patient_id` INT(11) UNSIGNED NOT NULL,
    `provider_id` INT(11) UNSIGNED NOT NULL,
    `medication_name` VARCHAR(255) NOT NULL,
    `dosage` VARCHAR(120) NOT NULL,
    `frequency` VARCHAR(120) NOT NULL,
    `duration` VARCHAR(120) NOT NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rx_patient` (`patient_id`),
    KEY `idx_rx_provider` (`provider_id`),
    KEY `idx_rx_consultation` (`consultation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
