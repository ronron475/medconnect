<?php
/**
 * BHW application tables — Maker-Checker workflow schema.
 */

function bhw_application_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bhw_applications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NULL,
            status ENUM(
                'draft',
                'pending_approval',
                'approved',
                'active',
                'rejected',
                'requires_documents'
            ) NOT NULL DEFAULT 'draft',
            first_name VARCHAR(100) NOT NULL,
            middle_name VARCHAR(100) NULL,
            last_name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(20) NULL,
            password_hash VARCHAR(255) NULL,
            barangay_id INT UNSIGNED NOT NULL,
            appointment_date DATE NULL,
            submitted_by INT UNSIGNED NULL,
            submitted_at DATETIME NULL,
            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            approved_by INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            rejected_by INT UNSIGNED NULL,
            rejected_at DATETIME NULL,
            rejection_reason TEXT NULL,
            additional_docs_note TEXT NULL,
            checklist_json JSON NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_bhw_app_status (status),
            KEY idx_bhw_app_created_by (created_by),
            KEY idx_bhw_app_submitted_by (submitted_by),
            KEY idx_bhw_app_user (user_id),
            KEY idx_bhw_app_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bhw_application_documents (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id INT UNSIGNED NOT NULL,
            document_type ENUM('appointment_letter', 'government_id', 'cho_endorsement', 'other') NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NULL,
            file_size INT UNSIGNED NULL,
            uploaded_by INT UNSIGNED NOT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_bhw_doc_app (application_id),
            CONSTRAINT fk_bhw_doc_app FOREIGN KEY (application_id) REFERENCES bhw_applications(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    require_once dirname(__DIR__) . '/includes/barangays_bago.php';
    barangays_ensure_bago_city($pdo);

    $done = true;
}
