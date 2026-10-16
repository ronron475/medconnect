-- BHW Healthcare Reports and Activity Log schema.
-- Apply through the existing migration workflow. Report page loads, summary
-- refreshes, and CSV exports do not run this file and do not alter data.
-- Statements are idempotent. This file does not rewrite or remove rows.
-- Barangay id backfill, when still required, stays in
-- database/migrations/2026_08_13_patient_registration_barangay_id.sql.

ALTER TABLE consultations
    ADD COLUMN IF NOT EXISTS teleconsult_consent TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS teleconsult_consent_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS teleconsult_consent_by INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS booked_by_bhw_id INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS triage_result_id BIGINT UNSIGNED NULL;

ALTER TABLE triage_results
    ADD COLUMN IF NOT EXISTS outcome VARCHAR(40) NULL;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) NULL DEFAULT NULL;

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

CREATE TABLE IF NOT EXISTS bhw_report_exports (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bhw_id          INT UNSIGNED NOT NULL,
    barangay_id     INT UNSIGNED DEFAULT NULL,
    barangay_name   VARCHAR(120) DEFAULT NULL,
    report_type     VARCHAR(60) NOT NULL,
    export_format   VARCHAR(20) NOT NULL,
    filters_json    JSON DEFAULT NULL,
    ip_address      VARCHAR(45) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bhw_report_exports_bhw (bhw_id),
    KEY idx_bhw_report_exports_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS barangay_puroks (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    barangay_id     INT UNSIGNED NOT NULL,
    purok_name      VARCHAR(120) NOT NULL,
    source          VARCHAR(32) NOT NULL DEFAULT 'reference',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_brgy_purok (barangay_id, purok_name),
    KEY idx_brgy_purok_brgy (barangay_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
