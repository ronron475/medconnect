-- Per-consultation chief complaint history (registration complaint stays on patient_registrations).
CREATE TABLE IF NOT EXISTS patient_chief_complaints (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id INT UNSIGNED NOT NULL,
    complaint_text TEXT NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'consultation_booking',
    triage_result_id INT UNSIGNED NULL,
    consultation_id INT UNSIGNED NULL,
    appointment_slot_id INT UNSIGNED NULL,
    registration_reference TEXT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pcc_patient (patient_id),
    KEY idx_pcc_consultation (consultation_id),
    KEY idx_pcc_triage (triage_result_id),
    KEY idx_pcc_submitted (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE complaint_evidence
    ADD COLUMN IF NOT EXISTS consultation_id INT UNSIGNED NULL AFTER triage_result_id;
