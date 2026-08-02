-- Health Summary update requests: patient-proposed values + assigned reviewing provider.

ALTER TABLE patient_medical_update_requests
  ADD COLUMN IF NOT EXISTS proposed_blood_type VARCHAR(20) NULL AFTER patient_note,
  ADD COLUMN IF NOT EXISTS proposed_allergies TEXT NULL AFTER proposed_blood_type,
  ADD COLUMN IF NOT EXISTS proposed_conditions TEXT NULL AFTER proposed_allergies,
  ADD COLUMN IF NOT EXISTS proposed_medications TEXT NULL AFTER proposed_conditions;
