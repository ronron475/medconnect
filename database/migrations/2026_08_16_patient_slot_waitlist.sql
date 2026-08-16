-- NON-URGENT patients waiting for a bookable doctor slot (same-day teleconsult).
-- Runtime schema is also created by app/includes/patient_slot_waitlist.php.

CREATE TABLE IF NOT EXISTS patient_slot_waitlist (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id INT UNSIGNED NOT NULL,
    triage_result_id INT UNSIGNED NOT NULL,
    assigned_provider_id INT UNSIGNED NULL,
    eligible_provider_id INT UNSIGNED NULL,
    complaint TEXT NULL,
    triage_level VARCHAR(32) NOT NULL DEFAULT 'non_urgent',
    status ENUM('waiting', 'slot_available', 'booked', 'cancelled', 'expired') NOT NULL DEFAULT 'waiting',
    waiting_since DATETIME NOT NULL,
    slot_available_at DATETIME NULL,
    notified_at DATETIME NULL,
    notification_id INT UNSIGNED NULL,
    availability_key VARCHAR(80) NULL,
    eligible_provider_name VARCHAR(191) NULL,
    booked_consultation_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_waitlist_patient_triage (patient_id, triage_result_id),
    KEY idx_waitlist_status_since (status, waiting_since),
    KEY idx_waitlist_patient_status (patient_id, status),
    KEY idx_waitlist_assigned (assigned_provider_id, status),
    KEY idx_waitlist_eligible (eligible_provider_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
