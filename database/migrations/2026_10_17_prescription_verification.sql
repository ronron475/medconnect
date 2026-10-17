-- Prescription verification tokens and revocation status.
-- MySQL 8 and MariaDB. These statements do not rewrite or delete prescription rows.
-- New columns are nullable, so existing prescriptions stay NULL and unverifiable.
--
-- MySQL 8 has no portable IF NOT EXISTS for ADD COLUMN or CREATE INDEX.
-- Run each statement only when its pre-check returns no row:
--
--   SHOW COLUMNS FROM prescriptions LIKE 'verification_token';
--   SHOW COLUMNS FROM prescriptions LIKE 'status';
--   SHOW INDEX FROM prescriptions WHERE Key_name = 'uq_rx_verification_token';
--
-- Re-running a statement that already succeeded will stop with a duplicate
-- column or index error and will not change prescription data.
-- The runtime helper in app/includes/prescription_verification.php applies the
-- same plain ALTER only after those checks.

ALTER TABLE prescriptions
  ADD COLUMN verification_token VARCHAR(64) NULL DEFAULT NULL AFTER notes;

ALTER TABLE prescriptions
  ADD COLUMN status ENUM('active','revoked') NULL DEFAULT NULL AFTER verification_token;

CREATE UNIQUE INDEX uq_rx_verification_token ON prescriptions (verification_token);
