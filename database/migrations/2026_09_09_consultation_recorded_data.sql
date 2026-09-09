-- Patient/BHW recorded data for consultations (vitals + complaint snapshot).
-- Supports pending PRE-CONSULTATION rows (consultation_id NULL) that attach when a consult is booked.
-- Runtime also auto-creates/migrates this via consultation_recorded_data_ensure_schema().

CREATE TABLE IF NOT EXISTS consultation_recorded_data (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    consultation_id INT UNSIGNED NULL,
    triage_result_id BIGINT UNSIGNED NULL,
    recorded_by INT UNSIGNED NOT NULL,
    recorder_role ENUM('patient','bhw') NOT NULL,
    chief_complaint TEXT NULL,
    symptoms TEXT NULL,
    temperature_c DECIMAL(4,1) NULL,
    blood_pressure VARCHAR(32) NULL,
    pulse_bpm SMALLINT UNSIGNED NULL,
    respiratory_rate SMALLINT UNSIGNED NULL,
    spo2_percent DECIMAL(5,2) NULL,
    weight_kg DECIMAL(6,2) NULL,
    height_cm DECIMAL(6,2) NULL,
    notes TEXT NULL,
    status ENUM('pending','attached') NOT NULL DEFAULT 'pending',
    origin ENUM('consultation','pre_consultation') NOT NULL DEFAULT 'pre_consultation',
    attached_at DATETIME NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_crd_consultation (consultation_id),
    INDEX idx_crd_patient (patient_id),
    INDEX idx_crd_patient_status (patient_id, status),
    INDEX idx_crd_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent upgrades for existing installs
-- ALTER TABLE consultation_recorded_data MODIFY consultation_id INT UNSIGNED NULL;
-- ALTER TABLE consultation_recorded_data ADD COLUMN status ENUM('pending','attached') NOT NULL DEFAULT 'pending' AFTER notes;
-- ALTER TABLE consultation_recorded_data ADD COLUMN origin ENUM('consultation','pre_consultation') NOT NULL DEFAULT 'pre_consultation' AFTER status;
-- ALTER TABLE consultation_recorded_data ADD COLUMN attached_at DATETIME NULL AFTER origin;
