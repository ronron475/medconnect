-- Provider Settings: profile extensions, notifications, system preferences

ALTER TABLE provider_profiles
    ADD COLUMN IF NOT EXISTS specialty VARCHAR(120) NULL DEFAULT NULL AFTER prc_license_number,
    ADD COLUMN IF NOT EXISTS facility VARCHAR(200) NULL DEFAULT 'City Health Office' AFTER specialty;

CREATE TABLE IF NOT EXISTS provider_notification_preferences (
    user_id INT UNSIGNED NOT NULL,
    new_messages TINYINT(1) NOT NULL DEFAULT 1,
    consultation_requests TINYINT(1) NOT NULL DEFAULT 1,
    triage_alerts TINYINT(1) NOT NULL DEFAULT 1,
    system_notifications TINYINT(1) NOT NULL DEFAULT 1,
    email_notifications TINYINT(1) NOT NULL DEFAULT 1,
    sms_notifications TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_pnp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_system_preferences (
    user_id INT UNSIGNED NOT NULL,
    theme ENUM('light','dark','system') NOT NULL DEFAULT 'light',
    language VARCHAR(10) NOT NULL DEFAULT 'en',
    time_format ENUM('12h','24h') NOT NULL DEFAULT '12h',
    date_format VARCHAR(20) NOT NULL DEFAULT 'M j, Y',
    auto_logout_minutes INT UNSIGNED NOT NULL DEFAULT 30,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_psp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
