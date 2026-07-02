-- GIS Dashboard: patient geolocation storage
-- Run in phpMyAdmin or via scripts/dev/apply migration helper

CREATE TABLE IF NOT EXISTS `patient_locations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `province` VARCHAR(120) NULL,
    `city_municipality` VARCHAR(120) NULL,
    `barangay` VARCHAR(120) NULL,
    `address` VARCHAR(255) NULL,
    `latitude` DECIMAL(10, 8) NULL,
    `longitude` DECIMAL(11, 8) NULL,
    `location_source` ENUM('gps', 'manual', 'barangay_centroid', 'imported') NOT NULL DEFAULT 'barangay_centroid',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_patient_locations_patient` (`patient_id`),
    KEY `idx_pl_barangay` (`barangay`),
    KEY `idx_pl_city` (`city_municipality`),
    KEY `idx_pl_province` (`province`),
    KEY `idx_pl_coords` (`latitude`, `longitude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `barangays`
    ADD COLUMN `latitude` DECIMAL(10, 8) NULL,
    ADD COLUMN `longitude` DECIMAL(11, 8) NULL,
    ADD COLUMN `psgc_code` VARCHAR(20) NULL;

-- Approximate centroids for Bago City barangays (Negros Occidental)
UPDATE `barangays` SET `latitude` = 10.5378, `longitude` = 122.8383 WHERE `name` = 'Poblacion' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5521, `longitude` = 122.8512 WHERE `name` = 'Abuanan' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5289, `longitude` = 122.8124 WHERE `name` = 'Alianza' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5612, `longitude` = 122.8245 WHERE `name` = 'Atipuluan' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5198, `longitude` = 122.8456 WHERE `name` = 'Bacong-Montilla' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5445, `longitude` = 122.8156 WHERE `name` = 'Bagroy' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5312, `longitude` = 122.8289 WHERE `name` = 'Balingasag' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5567, `longitude` = 122.8367 WHERE `name` = 'Binubuhan' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5234, `longitude` = 122.8567 WHERE `name` = 'Busay' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5489, `longitude` = 122.8023 WHERE `name` = 'Calumangan' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5156, `longitude` = 122.8312 WHERE `name` = 'Caridad' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5678, `longitude` = 122.8489 WHERE `name` = 'Dulao' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5389, `longitude` = 122.8678 WHERE `name` = 'Ilijan' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5123, `longitude` = 122.8198 WHERE `name` = 'Lag-Asan' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5545, `longitude` = 122.7934 WHERE `name` = 'Ma-ao' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5267, `longitude` = 122.8745 WHERE `name` = 'Mailum' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5412, `longitude` = 122.8056 WHERE `name` = 'Malingin' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5589, `longitude` = 122.8612 WHERE `name` = 'Napoles' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5178, `longitude` = 122.8423 WHERE `name` = 'Pacol' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5498, `longitude` = 122.8289 WHERE `name` = 'Sagasa' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5334, `longitude` = 122.7989 WHERE `name` = 'Sampinit' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5623, `longitude` = 122.8178 WHERE `name` = 'Tabunan' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5212, `longitude` = 122.8634 WHERE `name` = 'Taloc' AND (`latitude` IS NULL OR `longitude` IS NULL);
UPDATE `barangays` SET `latitude` = 10.5456, `longitude` = 122.8523 WHERE `name` = 'Taba-ao' AND (`latitude` IS NULL OR `longitude` IS NULL);
