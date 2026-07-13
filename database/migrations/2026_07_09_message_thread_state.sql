-- 2026-07-09: Per-user conversation state for consultations (archive / soft delete)
-- Adds read_at to consultation_messages for future "Read" receipts.

START TRANSACTION;

-- Add read_at if missing
ALTER TABLE consultation_messages
  ADD COLUMN IF NOT EXISTS read_at DATETIME NULL DEFAULT NULL AFTER is_read;

-- Thread state (per user per consultation)
CREATE TABLE IF NOT EXISTS consultation_thread_state (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  consultation_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  is_deleted  TINYINT(1) NOT NULL DEFAULT 0,
  last_read_message_id INT UNSIGNED NULL DEFAULT NULL,
  last_read_at DATETIME NULL DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_consult_user (consultation_id, user_id),
  KEY idx_user_archived (user_id, is_archived),
  KEY idx_user_deleted (user_id, is_deleted),
  KEY idx_user_updated (user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

