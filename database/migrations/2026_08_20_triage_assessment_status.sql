-- Runtime-added by triage_assessment_ensure_schema().
-- Separate from triage_classification: interview may be IN_PROGRESS while
-- the final class is still unset. Completed rows must store one of
-- NON-URGENT / URGENT / EMERGENCY (or NON_URGENT).

ALTER TABLE triage_results
  ADD COLUMN assessment_status VARCHAR(20) NULL
  COMMENT 'IN_PROGRESS|COMPLETED'
  AFTER outcome;
