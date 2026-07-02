-- MedConnect Notification System — schema upgrade
-- Run via phpMyAdmin or scripts/dev/apply_notification_migration.php

CREATE TABLE IF NOT EXISTS notifications (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL COMMENT 'receiver user id (legacy alias)',
    sender_id       INT UNSIGNED NULL,
    receiver_role   VARCHAR(20)  NULL,
    type            VARCHAR(50)  NOT NULL DEFAULT 'information',
    title           VARCHAR(255) NOT NULL,
    message         TEXT         NOT NULL,
    priority        ENUM('low','normal','high','critical','emergency') NOT NULL DEFAULT 'normal',
    related_table   VARCHAR(80)  NULL,
    related_id      BIGINT UNSIGNED NULL,
    status          ENUM('active','archived','deleted') NOT NULL DEFAULT 'active',
    is_read         TINYINT(1)   NOT NULL DEFAULT 0,
    link            VARCHAR(512) NULL COMMENT 'action URL (legacy alias)',
    icon            VARCHAR(50)  NULL,
    expires_at      DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_unread (user_id, is_read, status),
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_receiver_role (receiver_role, created_at),
    INDEX idx_related (related_table, related_id),
    INDEX idx_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
