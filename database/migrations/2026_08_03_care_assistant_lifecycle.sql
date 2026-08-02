-- Care Assistant lifecycle: one-time auto-open, dismiss persistence, 24h window tracking.
-- Runtime triage_assessment_ensure_schema() also adds these if missing.

ALTER TABLE triage_results
  ADD COLUMN IF NOT EXISTS recommendation_assistant_first_opened_at DATETIME NULL
    COMMENT 'When Care Assistant first auto-opened after approval' AFTER recommendation_patient_ack_at,
  ADD COLUMN IF NOT EXISTS recommendation_assistant_dismissed_at DATETIME NULL
    COMMENT 'When patient closed Care Assistant (manual open still allowed within 24h)' AFTER recommendation_assistant_first_opened_at,
  ADD COLUMN IF NOT EXISTS recommendation_last_viewed_at DATETIME NULL
    COMMENT 'Last time patient viewed Care Tips in Care Assistant' AFTER recommendation_assistant_dismissed_at;
