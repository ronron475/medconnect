-- SOAP electronic signature: provider-uploaded signed PDF linked to clinical_notes.
-- Files live in private storage (storage/uploads/soap_signed); only the relative path is stored.
-- Safe to run more than once.

ALTER TABLE clinical_notes
  ADD COLUMN IF NOT EXISTS signed_pdf_path VARCHAR(255) NULL DEFAULT NULL AFTER finalized_at,
  ADD COLUMN IF NOT EXISTS signed_pdf_name VARCHAR(255) NULL DEFAULT NULL AFTER signed_pdf_path,
  ADD COLUMN IF NOT EXISTS signed_pdf_size INT UNSIGNED NULL DEFAULT NULL AFTER signed_pdf_name,
  ADD COLUMN IF NOT EXISTS signed_pdf_sha256 CHAR(64) NULL DEFAULT NULL AFTER signed_pdf_size;
