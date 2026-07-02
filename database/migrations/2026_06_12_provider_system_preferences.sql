-- Provider system preferences (one record per provider)

CREATE TABLE IF NOT EXISTS provider_system_preferences (
    preference_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id INT UNSIGNED NOT NULL,
    theme_preference ENUM('system','light','dark') NOT NULL DEFAULT 'system',
    language VARCHAR(10) NOT NULL DEFAULT 'en',
    time_format ENUM('12h','24h') NOT NULL DEFAULT '12h',
    date_format VARCHAR(20) NOT NULL DEFAULT 'M j, Y',
    auto_logout_duration INT UNSIGNED NOT NULL DEFAULT 30,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (preference_id),
    UNIQUE KEY uq_provider_system_prefs (provider_id),
    CONSTRAINT fk_psp_provider FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
