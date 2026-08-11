-- Case misuse reports (additive; does not modify existing triage/NLP tables beyond outcome usage).

CREATE TABLE IF NOT EXISTS case_reports (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    triage_id INT UNSIGNED NOT NULL,
    patient_id INT UNSIGNED NOT NULL,
    reported_by INT UNSIGNED NOT NULL,
    reason VARCHAR(80) NOT NULL,
    notes TEXT NULL,
    status ENUM('pending', 'under_review', 'dismissed', 'confirmed', 'escalated') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    admin_note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_case_reports_triage (triage_id),
    KEY idx_case_reports_patient (patient_id),
    KEY idx_case_reports_status (status, created_at),
    KEY idx_case_reports_reporter (reported_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
