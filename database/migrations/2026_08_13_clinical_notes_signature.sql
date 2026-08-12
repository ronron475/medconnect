-- SOAP electronic signature fields on existing clinical_notes (reuse, do not duplicate).
-- Safe to run more than once.

ALTER TABLE clinical_notes
  MODIFY signature_data MEDIUMTEXT NULL;

ALTER TABLE clinical_notes
  ADD COLUMN IF NOT EXISTS signature_method VARCHAR(20) NULL DEFAULT NULL AFTER signature_data,
  ADD COLUMN IF NOT EXISTS signature_name VARCHAR(255) NULL DEFAULT NULL AFTER signature_method,
  ADD COLUMN IF NOT EXISTS signed_at DATETIME NULL DEFAULT NULL AFTER signature_name,
  ADD COLUMN IF NOT EXISTS finalized_at DATETIME NULL DEFAULT NULL AFTER signed_at;
