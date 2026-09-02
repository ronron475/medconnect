-- BHW invite → self-onboarding → Superadmin review workflow
-- Admin invites only; BHW sets password, completes profile, uploads personal docs.

ALTER TABLE bhw_applications
    MODIFY COLUMN status ENUM(
        'draft',
        'invited',
        'onboarding',
        'pending_approval',
        'approved',
        'active',
        'rejected',
        'requires_documents'
    ) NOT NULL DEFAULT 'draft';

ALTER TABLE bhw_applications
    ADD COLUMN invite_token VARCHAR(64) NULL DEFAULT NULL AFTER password_hash,
    ADD COLUMN invite_expires_at DATETIME NULL DEFAULT NULL AFTER invite_token,
    ADD COLUMN invited_at DATETIME NULL DEFAULT NULL AFTER invite_expires_at,
    ADD COLUMN activated_at DATETIME NULL DEFAULT NULL AFTER invited_at,
    ADD COLUMN bhw_submitted_at DATETIME NULL DEFAULT NULL AFTER activated_at;

ALTER TABLE bhw_applications
    ADD UNIQUE KEY uq_bhw_app_invite_token (invite_token);
