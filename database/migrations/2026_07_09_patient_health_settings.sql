-- Patient Health Summary metadata + Settings preferences
-- Safe to run multiple times on MySQL 8+

USE `medconnect`;

ALTER TABLE patient_registrations
    ADD COLUMN IF NOT EXISTS medical_profile_updated_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS medical_profile_updated_by INT UNSIGNED NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS patient_medical_update_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'in_review', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    patient_note TEXT NULL,
    provider_id INT UNSIGNED NULL DEFAULT NULL,
    provider_note TEXT NULL,
    reviewed_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_patient_status (patient_id, status),
    KEY idx_provider (provider_id),
    CONSTRAINT fk_pmur_patient FOREIGN KEY (patient_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_pmur_provider FOREIGN KEY (provider_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_notification_preferences (
    user_id INT UNSIGNED NOT NULL,
    appointment_reminders TINYINT(1) NOT NULL DEFAULT 1,
    consultation_updates TINYINT(1) NOT NULL DEFAULT 1,
    followup_reminders TINYINT(1) NOT NULL DEFAULT 1,
    prescription_notifications TINYINT(1) NOT NULL DEFAULT 1,
    system_announcements TINYINT(1) NOT NULL DEFAULT 1,
    in_app_notifications TINYINT(1) NOT NULL DEFAULT 1,
    email_notifications TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_pnp_patient FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_privacy_preferences (
    user_id INT UNSIGNED NOT NULL,
    share_medical_records TINYINT(1) NOT NULL DEFAULT 1,
    emergency_access_consent TINYINT(1) NOT NULL DEFAULT 1,
    data_privacy_acknowledged TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_ppp_patient FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
