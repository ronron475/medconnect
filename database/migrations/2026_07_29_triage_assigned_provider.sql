-- Non-urgent AI self-care review: assign reviewing provider on triage_results
ALTER TABLE triage_results
  ADD COLUMN IF NOT EXISTS assigned_provider_id INT UNSIGNED NULL AFTER recommendation_patient_ack_at,
  ADD COLUMN IF NOT EXISTS assigned_at DATETIME NULL AFTER assigned_provider_id;

CREATE INDEX IF NOT EXISTS idx_triage_assigned_provider
  ON triage_results (assigned_provider_id, recommendation_status, assessed_at);
