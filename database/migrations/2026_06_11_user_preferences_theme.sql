-- Unified theme preferences for all MedConnect user roles

CREATE TABLE IF NOT EXISTS user_preferences (
    preference_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    user_type ENUM('patient', 'provider', 'admin', 'bhw') NOT NULL,
    theme_preference ENUM('system', 'light', 'dark') NOT NULL DEFAULT 'system',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (preference_id),
    UNIQUE KEY uq_user_prefs (user_id, user_type),
    KEY idx_user_type (user_type),
    CONSTRAINT fk_uprefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing provider themes from provider_system_preferences
INSERT INTO user_preferences (user_id, user_type, theme_preference, created_at, updated_at)
SELECT p.provider_id, 'provider', p.theme_preference, COALESCE(p.created_at, NOW()), COALESCE(p.updated_at, NOW())
FROM provider_system_preferences p
ON DUPLICATE KEY UPDATE
    theme_preference = VALUES(theme_preference),
    updated_at = VALUES(updated_at);
