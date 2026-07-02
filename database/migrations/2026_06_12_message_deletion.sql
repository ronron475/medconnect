-- MedConnect: consultation message deletion (delete for me / delete for everyone)
-- Safe to run multiple times; skip columns that already exist.

ALTER TABLE consultation_messages
    ADD COLUMN IF NOT EXISTS is_deleted_for_everyone TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read,
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL DEFAULT NULL AFTER is_deleted_for_everyone,
    ADD COLUMN IF NOT EXISTS deleted_by_user_id INT UNSIGNED NULL DEFAULT NULL AFTER deleted_at,
    ADD COLUMN IF NOT EXISTS deleted_for_me_users JSON NULL DEFAULT NULL AFTER deleted_by_user_id,
    ADD COLUMN IF NOT EXISTS message_original TEXT NULL DEFAULT NULL COMMENT 'Audit-only original body after delete-for-everyone' AFTER deleted_for_me_users;

CREATE TABLE IF NOT EXISTS message_chat_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    consultation_id INT UNSIGNED NOT NULL,
    message_id INT UNSIGNED NOT NULL,
    event_type ENUM('deleted_for_me', 'deleted_for_everyone') NOT NULL,
    actor_user_id INT UNSIGNED NOT NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_consultation_created (consultation_id, created_at),
    KEY idx_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
