-- Link patient Step-2 barangay to barangays.id for BHW sector authorization.
-- Safe to re-run: ADD COLUMN fails harmlessly if already present when applied via PHP ensure.

ALTER TABLE patient_registrations
  ADD COLUMN barangay_id INT UNSIGNED NULL DEFAULT NULL AFTER barangay;

CREATE INDEX idx_pr_barangay_id ON patient_registrations (barangay_id);

-- Backfill from name match (collation-safe). Unmatched names remain NULL (= no BHW access).
UPDATE patient_registrations pr
INNER JOIN barangays b
  ON LOWER(TRIM(CONVERT(b.name USING utf8mb4))) COLLATE utf8mb4_unicode_ci
   = LOWER(TRIM(CONVERT(pr.barangay USING utf8mb4))) COLLATE utf8mb4_unicode_ci
SET pr.barangay_id = b.id
WHERE pr.barangay_id IS NULL
  AND pr.barangay IS NOT NULL
  AND TRIM(pr.barangay) <> '';
