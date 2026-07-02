-- BHW clinical workflow extensions
-- Run: php scripts/dev/apply_bhw_clinical_migration.php

ALTER TABLE consultations
    ADD COLUMN IF NOT EXISTS teleconsult_consent TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS teleconsult_consent_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS teleconsult_consent_by INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS booked_by_bhw_id INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS triage_result_id BIGINT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS bhw_home_visits (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    followup_id     BIGINT UNSIGNED NULL,
    patient_id      INT UNSIGNED NOT NULL,
    bhw_id          INT UNSIGNED NOT NULL,
    visit_date      DATE NOT NULL,
    visit_type      ENUM('follow_up','monitoring','emergency_check','other') NOT NULL DEFAULT 'follow_up',
    notes           TEXT NULL,
    patient_status  ENUM('improving','stable','worsening','referred','unknown') NOT NULL DEFAULT 'stable',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_patient (patient_id),
    INDEX idx_bhw (bhw_id),
    INDEX idx_followup (followup_id),
    INDEX idx_visit_date (visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
