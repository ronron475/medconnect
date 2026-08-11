-- Expand case_reports for video consultation violation reports (additive).

ALTER TABLE case_reports
    MODIFY COLUMN triage_id INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS source_type VARCHAR(30) NOT NULL DEFAULT 'case' AFTER id,
    ADD COLUMN IF NOT EXISTS consultation_id INT UNSIGNED NULL AFTER triage_id,
    ADD COLUMN IF NOT EXISTS appointment_id INT UNSIGNED NULL AFTER consultation_id,
    ADD COLUMN IF NOT EXISTS consultation_status_at_report VARCHAR(30) NULL AFTER notes;

-- MySQL 8.0 may not support IF NOT EXISTS on ADD COLUMN; runtime schema handles upgrades.
