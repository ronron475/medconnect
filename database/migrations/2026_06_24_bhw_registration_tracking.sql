-- BHW patient registration tracking
-- Run: php scripts/dev/apply_bhw_registration_migration.php

ALTER TABLE patient_registrations
  ADD COLUMN registered_by_bhw_id INT UNSIGNED NULL DEFAULT NULL;

CREATE INDEX idx_pr_registered_by_bhw ON patient_registrations (registered_by_bhw_id);
