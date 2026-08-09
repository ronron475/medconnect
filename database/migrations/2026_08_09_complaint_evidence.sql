-- Optional patient-submitted photo/video linked to a triage case (doctor review only).
CREATE TABLE IF NOT EXISTS complaint_evidence (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id INT UNSIGNED NOT NULL,
    triage_result_id INT UNSIGNED NOT NULL,
    media_type ENUM('image', 'video') NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NULL,
    mime_type VARCHAR(128) NOT NULL,
    file_size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_complaint_evidence_triage (triage_result_id),
    KEY idx_complaint_evidence_patient (patient_id),
    KEY idx_complaint_evidence_uploaded (uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
