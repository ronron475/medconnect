<?php
/**
 * Ensure all official Bago City barangays exist for dropdowns and assignments.
 */

require_once dirname(__DIR__) . '/core/BagoBarangayCentroids.php';

function barangays_ensure_bago_city(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS barangays (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            city VARCHAR(120) NOT NULL DEFAULT 'Bago City',
            latitude DECIMAL(10, 8) NULL,
            longitude DECIMAL(11, 8) NULL,
            psgc_code VARCHAR(20) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            archived_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_barangay_city (name, city),
            KEY idx_barangay_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    barangays_bago_add_optional_columns($pdo);

    $city = 'Bago City';
    $insert = $pdo->prepare("
        INSERT INTO barangays (name, city, latitude, longitude, is_active)
        VALUES (?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            is_active = 1,
            latitude = COALESCE(VALUES(latitude), latitude),
            longitude = COALESCE(VALUES(longitude), longitude),
            archived_at = NULL
    ");

    foreach (BagoBarangayCentroids::barangayRecords() as $row) {
        $insert->execute([$row['name'], $city, $row['lat'], $row['lng']]);
    }

    $done = true;
}

function barangays_bago_add_optional_columns(PDO $pdo): void
{
    $columns = [
        'latitude'    => 'DECIMAL(10, 8) NULL',
        'longitude'   => 'DECIMAL(11, 8) NULL',
        'psgc_code'   => 'VARCHAR(20) NULL',
        'is_active'   => 'TINYINT(1) NOT NULL DEFAULT 1',
        'archived_at' => 'DATETIME NULL',
        'created_at'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ];

    foreach ($columns as $name => $definition) {
        try {
            $pdo->exec("ALTER TABLE barangays ADD COLUMN {$name} {$definition}");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
    }
}

/**
 * @return list<array{id: int, name: string, city: string}>
 */
function barangays_list_bago_city(PDO $pdo): array
{
    barangays_ensure_bago_city($pdo);

    $stmt = $pdo->query("
        SELECT id, name, city
        FROM barangays
        WHERE is_active = 1
          AND (city = 'Bago City' OR city LIKE 'Bago%')
        ORDER BY name ASC
    ");

    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}
