-- BHW patient workflow status + consultation priority
ALTER TABLE patient_registrations
    ADD COLUMN IF NOT EXISTS workflow_status VARCHAR(40) NOT NULL DEFAULT 'registered'
        COMMENT 'BHW facilitator workflow state' AFTER status;

ALTER TABLE consultations
    ADD COLUMN IF NOT EXISTS consult_priority ENUM('standard','urgent','emergency') NOT NULL DEFAULT 'standard'
        AFTER status;

CREATE INDEX IF NOT EXISTS idx_pr_workflow_status ON patient_registrations (workflow_status);
