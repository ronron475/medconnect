-- BHW community follow-up on doctor digital referrals (does not mutate clinical referral).
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS referral_community_followups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    referral_id INT UNSIGNED NOT NULL,
    patient_id INT UNSIGNED NOT NULL,
    bhw_id INT UNSIGNED NOT NULL,
    patient_contacted TINYINT(1) NOT NULL DEFAULT 0,
    acted_on_referral TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rcf_referral (referral_id),
    INDEX idx_rcf_patient (patient_id),
    INDEX idx_rcf_bhw (bhw_id),
    INDEX idx_rcf_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
