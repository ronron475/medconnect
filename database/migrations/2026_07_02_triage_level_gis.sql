-- Canonical GIS triage severity on triage_results (AI/NLP + manual reassessment).
-- Values: non_urgent | urgent | emergency

ALTER TABLE triage_results
    ADD COLUMN IF NOT EXISTS triage_level VARCHAR(20) NULL
        COMMENT 'GIS severity: non_urgent|urgent|emergency' AFTER severity;
