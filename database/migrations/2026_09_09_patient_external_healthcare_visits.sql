-- External healthcare visits: patient medical HISTORY (not MedConnect consultations).
-- Runtime also ensures via patient_external_healthcare_visits_ensure_schema().

CREATE TABLE IF NOT EXISTS patient_external_healthcare_visits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    recorded_by INT UNSIGNED NOT NULL,
    recorder_role ENUM('bhw','patient','provider') NOT NULL DEFAULT 'bhw',
    visit_date DATE NOT NULL,
    facility_type ENUM(
        'hospital',
        'private_clinic',
        'health_center',
        'government_health_facility',
        'emergency_facility',
        'other'
    ) NOT NULL,
    facility_name VARCHAR(200) NOT NULL,
    reason TEXT NULL,
    reported_diagnosis TEXT NULL,
    reported_treatment TEXT NULL,
    notes TEXT NULL,
    information_source ENUM(
        'patient_reported',
        'bhw_recorded',
        'medical_document',
        'other'
    ) NOT NULL DEFAULT 'patient_reported',
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    updated_by INT UNSIGNED NULL,
    INDEX idx_pehv_patient (patient_id),
    INDEX idx_pehv_visit_date (visit_date),
    INDEX idx_pehv_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
