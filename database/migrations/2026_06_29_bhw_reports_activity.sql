-- BHW Reports & Activity Log support
-- Safe to run multiple times (IF NOT EXISTS / conditional indexes).

CREATE TABLE IF NOT EXISTS `bhw_report_exports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bhw_id` INT UNSIGNED NOT NULL,
  `barangay_id` INT UNSIGNED DEFAULT NULL,
  `barangay_name` VARCHAR(120) DEFAULT NULL,
  `report_type` VARCHAR(60) NOT NULL,
  `export_format` VARCHAR(20) NOT NULL,
  `filters_json` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bhw_report_exports_bhw` (`bhw_id`),
  KEY `idx_bhw_report_exports_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Optional indexes on patient_audit_logs for BHW activity queries
-- (skip if table missing — application handles gracefully)
