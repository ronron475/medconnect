-- Provider password security: activity logs + brute-force tracking

CREATE TABLE IF NOT EXISTS provider_activity_logs (
    log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id INT UNSIGNED NOT NULL,
    action VARCHAR(120) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_provider_created (provider_id, created_at),
    KEY idx_action (action),
    CONSTRAINT fk_pal_provider FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_password_attempts (
    provider_id INT UNSIGNED NOT NULL,
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (provider_id),
    CONSTRAINT fk_ppa_provider FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
