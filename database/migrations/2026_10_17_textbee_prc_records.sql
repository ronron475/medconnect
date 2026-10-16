-- TextBee follow-up SMS logs and PRC verification history.
-- Existing patient, follow-up, and doctor rows are not deleted or merged.
-- The application also creates these tables on first use.
--
-- This file is not one transaction. MySQL and MariaDB commit each CREATE and ALTER
-- on its own. If the index step fails, the SMS log table, the verification_status
-- change, prc_verification_events, and prc_license_locks remain in place.
-- Recovery: restore the staging backup taken before this file was run, or leave
-- those earlier changes, resolve duplicate provider_profiles.prc_license_number
-- values outside this script, and run the file again. Do not delete provider rows
-- to force the index through. A later run skips index creation only when a unique
-- index already covers the full prc_license_number column.

CREATE TABLE IF NOT EXISTS followup_sms_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    followup_id INT UNSIGNED NOT NULL,
    patient_id INT UNSIGNED NOT NULL,
    sent_by INT UNSIGNED NOT NULL,
    recipient VARCHAR(20) NOT NULL,
    message_body VARCHAR(500) NOT NULL,
    status ENUM('mocked', 'accepted', 'failed', 'duplicate', 'pending') NOT NULL,
    provider_ref VARCHAR(80) NULL,
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_followup_sms_followup (followup_id, status),
    KEY idx_followup_sms_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adds the non-retryable pending claim status. Existing rows are left unchanged.
ALTER TABLE followup_sms_logs
    MODIFY COLUMN status ENUM('mocked', 'accepted', 'failed', 'duplicate', 'pending') NOT NULL;

ALTER TABLE provider_profiles
    MODIFY COLUMN verification_status
        ENUM('pending', 'verified', 'rejected', 'needs_review') NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS prc_verification_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_user_id INT UNSIGNED NOT NULL,
    prc_license_number VARCHAR(32) NOT NULL,
    status ENUM('pending', 'verified', 'rejected', 'needs_review') NOT NULL,
    verifier_id INT UNSIGNED NULL,
    remarks VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_prc_events_provider (provider_user_id, id),
    KEY idx_prc_events_license (prc_license_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prc_license_locks (
    prc_license_number VARCHAR(32) NOT NULL,
    holder_type ENUM('application', 'provider') NOT NULL,
    holder_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (prc_license_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unique full-column index on provider_profiles.prc_license_number.
-- A same-named non-unique, composite, or prefix index does not qualify.
-- Duplicate license values make CREATE UNIQUE INDEX fail visibly. This script
-- does not catch that error and does not change provider rows to remove it.
-- CREATE INDEX IF NOT EXISTS is not used: MySQL 8 rejects that syntax.
DROP PROCEDURE IF EXISTS medconnect_ensure_uq_provider_prc;

DELIMITER $$

CREATE PROCEDURE medconnect_ensure_uq_provider_prc()
BEGIN
    DECLARE qualifying_indexes INT DEFAULT 0;

    SELECT COUNT(*) INTO qualifying_indexes
    FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'provider_profiles'
          AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING COUNT(*) = 1
           AND SUM(
                CASE
                    WHEN SEQ_IN_INDEX = 1
                     AND LOWER(COLUMN_NAME) = 'prc_license_number'
                     AND SUB_PART IS NULL
                    THEN 1
                    ELSE 0
                END
           ) = 1
    ) AS qualifying;

    IF qualifying_indexes = 0 THEN
        CREATE UNIQUE INDEX uq_provider_prc ON provider_profiles (prc_license_number);
    END IF;
END$$

DELIMITER ;

CALL medconnect_ensure_uq_provider_prc();
DROP PROCEDURE IF EXISTS medconnect_ensure_uq_provider_prc;
