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

/**
 * Resolve a free-text barangay name to barangays.id (Bago City).
 * Uses canonical name aliases when available. Returns null when unmatched.
 */
function barangay_resolve_id_by_name(PDO $pdo, string $barangayName): ?int
{
    $raw = trim($barangayName);
    if ($raw === '') {
        return null;
    }

    barangays_ensure_bago_city($pdo);

    $canonical = BagoBarangayCentroids::canonicalName($raw) ?? $raw;
    $candidates = array_values(array_unique(array_filter([$canonical, $raw], static fn ($v) => trim((string) $v) !== '')));

    $stmt = $pdo->prepare("
        SELECT id
        FROM barangays
        WHERE is_active = 1
          AND (city = 'Bago City' OR city LIKE 'Bago%')
          AND LOWER(TRIM(CONVERT(name USING utf8mb4))) COLLATE utf8mb4_unicode_ci
            = LOWER(TRIM(CONVERT(? USING utf8mb4))) COLLATE utf8mb4_unicode_ci
        LIMIT 1
    ");

    foreach ($candidates as $name) {
        $stmt->execute([(string) $name]);
        $id = $stmt->fetchColumn();
        if ($id !== false && (int) $id > 0) {
            return (int) $id;
        }
    }

    return null;
}

/**
 * Ensure patient_registrations.barangay_id exists and backfill from Step-2 name.
 */
function patient_registrations_ensure_barangay_id(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    barangays_ensure_bago_city($pdo);

    $cols = [];
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM patient_registrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        return;
    }

    if (!in_array('barangay_id', $cols, true)) {
        try {
            $pdo->exec('ALTER TABLE patient_registrations ADD COLUMN barangay_id INT UNSIGNED NULL DEFAULT NULL AFTER barangay');
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                error_log('patient_registrations_ensure_barangay_id add column: ' . $e->getMessage());
                return;
            }
        }
        try {
            $pdo->exec('CREATE INDEX idx_pr_barangay_id ON patient_registrations (barangay_id)');
        } catch (PDOException $e) {
            // Index may already exist.
        }
        if (function_exists('bhw_pr_columns_reset')) {
            bhw_pr_columns_reset();
        }
    }

    try {
        $pdo->exec("
            UPDATE patient_registrations pr
            INNER JOIN barangays b
              ON LOWER(TRIM(CONVERT(b.name USING utf8mb4))) COLLATE utf8mb4_unicode_ci
               = LOWER(TRIM(CONVERT(pr.barangay USING utf8mb4))) COLLATE utf8mb4_unicode_ci
            SET pr.barangay_id = b.id
            WHERE pr.barangay_id IS NULL
              AND pr.barangay IS NOT NULL
              AND TRIM(pr.barangay) <> ''
        ");
    } catch (PDOException $e) {
        error_log('patient_registrations_ensure_barangay_id backfill: ' . $e->getMessage());
    }

    $done = true;
}
