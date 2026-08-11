-- Run once in Hostinger phpMyAdmin
-- Database: u520834156_meDBConnect26
-- (Select the database in the left sidebar — do NOT use USE medconnect;)

CREATE TABLE IF NOT EXISTS case_reports (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_type VARCHAR(30) NOT NULL DEFAULT 'case',
    triage_id INT UNSIGNED NULL,
    consultation_id INT UNSIGNED NULL,
    appointment_id INT UNSIGNED NULL,
    patient_id INT UNSIGNED NOT NULL,
    reported_by INT UNSIGNED NOT NULL,
    reason VARCHAR(80) NOT NULL,
    notes TEXT NULL,
    consultation_status_at_report VARCHAR(30) NULL,
    status ENUM('pending', 'under_review', 'dismissed', 'confirmed', 'escalated') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    admin_note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_case_reports_triage (triage_id),
    KEY idx_case_reports_consultation (consultation_id),
    KEY idx_case_reports_patient (patient_id),
    KEY idx_case_reports_status (status, created_at),
    KEY idx_case_reports_reporter (reported_by),
    KEY idx_case_reports_source (source_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- If table already existed from an older migration, add missing columns (ignore errors if column exists).

ALTER TABLE case_reports ADD COLUMN source_type VARCHAR(30) NOT NULL DEFAULT 'case' AFTER id;
ALTER TABLE case_reports ADD COLUMN consultation_id INT UNSIGNED NULL AFTER triage_id;
ALTER TABLE case_reports ADD COLUMN appointment_id INT UNSIGNED NULL AFTER consultation_id;
ALTER TABLE case_reports ADD COLUMN consultation_status_at_report VARCHAR(30) NULL AFTER notes;
ALTER TABLE case_reports MODIFY COLUMN triage_id INT UNSIGNED NULL;

-- Verify:
-- SHOW COLUMNS FROM case_reports;
