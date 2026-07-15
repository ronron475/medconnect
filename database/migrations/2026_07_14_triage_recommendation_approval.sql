<?php
/**
 * Gate non-urgent NLP remedies behind provider approval before patient display.
 * Runtime ensure_schema also adds these columns if missing.
 */

ALTER TABLE triage_results
  ADD COLUMN IF NOT EXISTS recommendation_status VARCHAR(32) NOT NULL DEFAULT 'hidden'
    COMMENT 'hidden|pending_approval|approved|rejected' AFTER recommendations,
  ADD COLUMN IF NOT EXISTS recommendation_approved_by INT UNSIGNED NULL AFTER recommendation_status,
  ADD COLUMN IF NOT EXISTS recommendation_approved_at DATETIME NULL AFTER recommendation_approved_by,
  ADD COLUMN IF NOT EXISTS recommendation_patient_ack_at DATETIME NULL AFTER recommendation_approved_at;
