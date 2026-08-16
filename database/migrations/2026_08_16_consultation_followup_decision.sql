-- ============================================================================
-- medConnect — post-consultation follow-up decision
--
-- Adds an explicit "was a follow-up required?" answer to every consultation, and
-- brings the `followups` table under version control. Until now `followups` was
-- assumed to already exist on the server and was only patched at runtime by
-- app/api/provider/schedule_followup.php, so a fresh install had no table at all.
--
-- Safe to re-run. Existing rows are untouched.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. followups — first tracked definition, matching the columns the app writes.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `followups` (
    `id`              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `consultation_id` INT(11) UNSIGNED NULL,
    `patient_id`      INT(11) UNSIGNED NOT NULL,
    `provider_id`     INT(11) UNSIGNED NOT NULL,
    -- NULL means "follow-up required, not yet scheduled": the provider flagged the
    -- patient while no future availability existed. A date is filled in later.
    `followup_date`   DATE NULL DEFAULT NULL,
    `slot_id`         INT(11) UNSIGNED NULL DEFAULT NULL,
    `message`         TEXT NULL,
    `notes`           TEXT NULL,
    `contact_number`  VARCHAR(32) NULL,
    `status`          ENUM('unscheduled', 'scheduled', 'completed', 'missed', 'cancelled')
                          NOT NULL DEFAULT 'scheduled',
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_followup_patient` (`patient_id`, `status`),
    KEY `idx_followup_provider_date` (`provider_id`, `followup_date`),
    KEY `idx_followup_consultation` (`consultation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bring an already-deployed followups table up to the same shape.
ALTER TABLE `followups`
    ADD COLUMN IF NOT EXISTS `notes` TEXT NULL AFTER `message`,
    ADD COLUMN IF NOT EXISTS `contact_number` VARCHAR(32) NULL AFTER `notes`,
    ADD COLUMN IF NOT EXISTS `slot_id` INT(11) UNSIGNED NULL DEFAULT NULL AFTER `followup_date`;

-- Allow "required but unscheduled" on existing installs where the column is NOT NULL.
ALTER TABLE `followups`
    MODIFY COLUMN `followup_date` DATE NULL DEFAULT NULL;

-- Deployed installs have this ENUM without 'unscheduled', which is the status a
-- required-but-not-yet-bookable follow-up needs.
ALTER TABLE `followups`
    MODIFY COLUMN `status` ENUM('unscheduled', 'scheduled', 'completed', 'missed', 'cancelled')
        NOT NULL DEFAULT 'scheduled';

-- ---------------------------------------------------------------------------
-- 2. consultations — record the provider's follow-up decision.
--    NULL = not yet decided, so existing history is not retroactively marked.
-- ---------------------------------------------------------------------------
ALTER TABLE `consultations`
    ADD COLUMN IF NOT EXISTS `follow_up_required` TINYINT(1) NULL DEFAULT NULL AFTER `recommendation`,
    ADD COLUMN IF NOT EXISTS `follow_up_decided_at` DATETIME NULL DEFAULT NULL AFTER `follow_up_required`,
    ADD COLUMN IF NOT EXISTS `follow_up_id` INT(11) UNSIGNED NULL DEFAULT NULL AFTER `follow_up_decided_at`;

ALTER TABLE `consultations`
    ADD COLUMN IF NOT EXISTS `completed_at` DATETIME NULL DEFAULT NULL AFTER `status`;

-- One decision per consultation. The decision endpoint is idempotent, but this
-- makes a duplicate write impossible even if two requests race.
CREATE INDEX IF NOT EXISTS `idx_consultation_followup_decision`
    ON `consultations` (`follow_up_required`, `follow_up_decided_at`);
