-- Prescription verification tokens and revocation status.
-- Target checked read-only: Hostinger MariaDB 11.8 (medconnect.bccbsis.com).
-- These statements do not rewrite or delete prescription rows.
-- New columns are nullable, so existing prescriptions stay NULL and unverifiable.
--
-- MariaDB 11.8 accepts IF NOT EXISTS for ADD COLUMN and CREATE INDEX.
-- Re-running this file is a no-op when the column or index is already present.
-- It does not fill tokens or change status on existing rows.
--
-- Pre-check, optional:
--   SHOW COLUMNS FROM prescriptions LIKE 'verification_token';
--   SHOW COLUMNS FROM prescriptions LIKE 'status';
--   SHOW INDEX FROM prescriptions WHERE Key_name = 'uq_rx_verification_token';
--
-- The runtime helper in app/includes/prescription_verification.php uses the
-- same plain ALTER only after those checks, for servers without IF NOT EXISTS.

ALTER TABLE prescriptions
  ADD COLUMN IF NOT EXISTS verification_token VARCHAR(64) NULL DEFAULT NULL AFTER notes;

ALTER TABLE prescriptions
  ADD COLUMN IF NOT EXISTS status ENUM('active','revoked') NULL DEFAULT NULL AFTER verification_token;

CREATE UNIQUE INDEX IF NOT EXISTS uq_rx_verification_token ON prescriptions (verification_token);
