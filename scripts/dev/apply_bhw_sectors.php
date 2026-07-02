<?php
require_once dirname(__DIR__, 2) . '/config/db.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS barangays (
        id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        city VARCHAR(120) NOT NULL DEFAULT 'Bago City',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uq_barangay_city (name, city)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    INSERT INTO barangays (id, name, city)
    VALUES (1, 'Poblacion', 'Bago City')
    ON DUPLICATE KEY UPDATE name = VALUES(name)
");

$pdo->exec("UPDATE users SET barangay_id = 1 WHERE role = 'bhw' AND (barangay_id IS NULL OR barangay_id = 0)");

echo "OK barangays table ready, BHW users assigned to Poblacion\n";
