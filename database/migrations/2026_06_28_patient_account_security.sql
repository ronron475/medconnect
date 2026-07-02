-- Patient account security columns for BHW-assisted registration and first-login setup.

-- users: authentication and account lifecycle
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS last_login DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS account_status VARCHAR(20) NOT NULL DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS first_login DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS password_reset_token VARCHAR(64) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS password_reset_expiry DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS password_setup_token VARCHAR(64) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS password_setup_expiry DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS terms_accepted_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS privacy_accepted_at DATETIME NULL DEFAULT NULL;

-- patient_registrations: display patient code and link to user account
ALTER TABLE patient_registrations
    ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS patient_code VARCHAR(20) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS middle_name VARCHAR(80) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS suffix VARCHAR(20) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS civil_status VARCHAR(30) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS purok VARCHAR(80) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS full_address VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS current_medications TEXT NULL,
    ADD COLUMN IF NOT EXISTS emergency_contact_name VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS emergency_contact_phone VARCHAR(20) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS emergency_contact_relation VARCHAR(60) NULL DEFAULT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_pr_patient_code ON patient_registrations (patient_code);
CREATE INDEX IF NOT EXISTS idx_users_password_setup_token ON users (password_setup_token);
CREATE INDEX IF NOT EXISTS idx_pr_user_id ON patient_registrations (user_id);
