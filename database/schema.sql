-- medConnect Database Repair Script
-- Purpose: Completely remove and recreate tables to fix "Table doesn't exist in engine" errors
-- Generated on: 2026-05-27

-- Ensure the database is selected
CREATE DATABASE IF NOT EXISTS `medconnect`;
USE `medconnect`;

-- Disable foreign key checks to allow dropping tables with constraints
SET FOREIGN_KEY_CHECKS = 0;

-- 1. DROP Corrupt Tables
DROP TABLE IF EXISTS `consultations`;
DROP TABLE IF EXISTS `patient_audit_logs`;
DROP TABLE IF EXISTS `triage_rules`;
DROP TABLE IF EXISTS `triage_results`;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- 2. Recreate Consultations Table
-- Fixes analytics.php line 14
CREATE TABLE `consultations` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT(11) UNSIGNED NOT NULL,
    `provider_id` INT(11) UNSIGNED DEFAULT NULL,
    `provider_name` VARCHAR(100) DEFAULT NULL,
    `consult_date` DATE NOT NULL,
    `consult_time` TIME NOT NULL,
    `consult_type` VARCHAR(50) DEFAULT 'General Consultation',
    `status` ENUM('pending', 'scheduled', 'in_consultation', 'completed', 'cancelled') DEFAULT 'pending',
    `diagnosis` TEXT DEFAULT NULL,
    `recommendation` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `patient_id` (`patient_id`),
    KEY `provider_id` (`provider_id`),
    CONSTRAINT `fk_consult_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_consult_provider` FOREIGN KEY (`provider_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Recreate Patient Audit Logs Table
-- Fixes audit_logs.php line 13
CREATE TABLE `patient_audit_logs` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT(11) UNSIGNED NOT NULL,
    `action_type` VARCHAR(80) NOT NULL,
    `description` TEXT NOT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `meta` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `patient_id` (`patient_id`),
    KEY `idx_action` (`action_type`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_audit_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Recreate Triage Rules Table
-- Fixes ai_config.php line 13
CREATE TABLE `triage_rules` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `symptom_name` VARCHAR(100) NOT NULL,
    `base_level` INT(11) NOT NULL COMMENT '1=Emergency, 5=Routine',
    `weight` DECIMAL(5,2) DEFAULT 1.00,
    `is_emergency` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Recreate Triage Results Table
-- Used for platform-wide AI Triage status
CREATE TABLE `triage_results` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT(11) UNSIGNED NOT NULL,
    `level` VARCHAR(20) NOT NULL,
    `symptoms` TEXT NOT NULL,
    `chief_complaint` VARCHAR(255) DEFAULT NULL,
    `urgency_label` VARCHAR(50) DEFAULT NULL,
    `status` ENUM('pending', 'reviewed', 'completed') DEFAULT 'pending',
    `assessed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `barangay_id` INT(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `patient_id` (`patient_id`),
    CONSTRAINT `fk_triage_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Provider Availability and Schedule
CREATE TABLE IF NOT EXISTS `provider_schedules` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider_id` INT(11) UNSIGNED NOT NULL,
    `day_of_week` ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `slot_duration` INT(11) DEFAULT 30 COMMENT 'Duration in minutes',
    `is_active` TINYINT(1) DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_provider_day_sort` (`provider_id`, `day_of_week`, `sort_order`),
    CONSTRAINT `fk_schedule_provider` FOREIGN KEY (`provider_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Bookable Time Slots (Generated based on schedule)
CREATE TABLE IF NOT EXISTS `appointment_slots` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider_id` INT(11) UNSIGNED NOT NULL,
    `slot_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `status` ENUM('available', 'booked', 'blocked') DEFAULT 'available',
    `patient_id` INT(11) UNSIGNED NULL,
    `consultation_id` INT(11) UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_provider_slot` (`provider_id`, `slot_date`, `start_time`),
    KEY `idx_slot_patient` (`patient_id`),
    KEY `idx_slot_consultation` (`consultation_id`),
    CONSTRAINT `fk_slot_provider` FOREIGN KEY (`provider_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Digital Clinical Records (SOAP Notes)
CREATE TABLE IF NOT EXISTS `clinical_notes` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `consultation_id` INT(11) UNSIGNED NOT NULL,
    `patient_id` INT(11) UNSIGNED NOT NULL,
    `provider_id` INT(11) UNSIGNED NOT NULL,
    `subjective` TEXT,
    `objective` TEXT,
    `assessment` TEXT,
    `plan` TEXT,
    `diagnosis` TEXT,
    `treatment_plan` TEXT,
    `prescription` TEXT,
    `signature_data` TEXT COMMENT 'Digital signature reference',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `consultation_id` (`consultation_id`),
    CONSTRAINT `fk_note_consult` FOREIGN KEY (`consultation_id`) REFERENCES `consultations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_note_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_note_provider` FOREIGN KEY (`provider_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Referrals
CREATE TABLE IF NOT EXISTS `digital_referrals` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT(11) UNSIGNED NOT NULL,
    `provider_id` INT(11) UNSIGNED NOT NULL,
    `referral_type` VARCHAR(50) NOT NULL COMMENT 'ABTC, TB-DOTS, LAB, etc',
    `reason` TEXT NOT NULL,
    `destination_facility` VARCHAR(255),
    `status` ENUM('pending', 'completed', 'expired') DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_ref_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ref_provider` FOREIGN KEY (`provider_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Initial Seed Data for Triage Rules
INSERT INTO `triage_rules` (`symptom_name`, `base_level`, `weight`, `is_emergency`) VALUES
('Chest Pain', 1, 5.00, 1),
('Difficulty Breathing', 1, 5.00, 1),
('High Fever', 2, 3.50, 0),
('Severe Headache', 2, 3.00, 0),
('Persistent Cough', 3, 2.00, 0),
('Mild Fatigue', 4, 1.00, 0),
('Routine Checkup', 5, 0.50, 0);
