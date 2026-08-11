-- Smart patient location metadata for GIS (Hostinger / phpMyAdmin)
-- Run after 2026_06_23_gis_patient_locations.sql

ALTER TABLE `patient_locations`
  ADD COLUMN IF NOT EXISTS `location_accuracy` VARCHAR(20) NULL AFTER `location_source`,
  ADD COLUMN IF NOT EXISTS `address_confidence` VARCHAR(20) NULL AFTER `location_accuracy`,
  ADD COLUMN IF NOT EXISTS `canonical_barangay` VARCHAR(120) NULL AFTER `address_confidence`;

ALTER TABLE `patient_locations`
  MODIFY COLUMN `location_source` VARCHAR(32) NOT NULL DEFAULT 'barangay_centroid';

CREATE TABLE IF NOT EXISTS `address_geocode_cache` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `address_hash` CHAR(64) NOT NULL,
  `query_text` VARCHAR(500) NOT NULL,
  `latitude` DECIMAL(10, 8) NULL,
  `longitude` DECIMAL(11, 8) NULL,
  `confidence` VARCHAR(20) NULL,
  `provider` VARCHAR(40) NOT NULL DEFAULT 'nominatim',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_address_geocode_hash` (`address_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
