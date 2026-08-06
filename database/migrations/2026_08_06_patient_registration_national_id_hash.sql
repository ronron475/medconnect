-- Widen national_id to store full SHA-256 hex digest (64 chars) used at registration.
ALTER TABLE patient_registrations
    MODIFY COLUMN national_id VARCHAR(64) NOT NULL;
