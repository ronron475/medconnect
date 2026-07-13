-- Optional metadata for BHW-uploaded patient documents
ALTER TABLE residency_documents
    ADD COLUMN IF NOT EXISTS document_type VARCHAR(64) NULL AFTER original_name,
    ADD COLUMN IF NOT EXISTS document_title VARCHAR(255) NULL AFTER document_type,
    ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER document_title;
