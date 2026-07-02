<?php
/**
 * Provider (doctor) PRC verification helpers
 */
function provider_verification_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS provider_profiles (
            id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT(11) UNSIGNED NOT NULL,
            prc_license_number VARCHAR(32) NOT NULL,
            verification_status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
            verified_by INT(11) UNSIGNED NULL,
            verified_at DATETIME NULL,
            rejection_note VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_provider_user (user_id),
            KEY idx_verification_status (verification_status),
            CONSTRAINT fk_provider_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    provider_verification_ensure_columns($pdo);

    $done = true;
}

function provider_verification_ensure_columns(PDO $pdo): void
{
    static $columnsDone = false;
    if ($columnsDone) {
        return;
    }

    $columns = [
        'middle_name' => 'VARCHAR(100) NULL DEFAULT NULL AFTER prc_license_number',
        'birthdate'   => 'DATE NULL DEFAULT NULL AFTER middle_name',
        'specialty'   => 'VARCHAR(120) NULL DEFAULT NULL AFTER birthdate',
        'facility'    => 'VARCHAR(200) NULL DEFAULT NULL AFTER specialty',
        'created_by'  => 'INT(11) UNSIGNED NULL DEFAULT NULL AFTER facility',
    ];

    $existing = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM provider_profiles');
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[(string) $row['Field']] = true;
        }
    }

    foreach ($columns as $name => $definition) {
        if (isset($existing[$name])) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE provider_profiles ADD COLUMN `{$name}` {$definition}");
        } catch (Throwable $e) {
            // Non-fatal for partial migrations.
        }
    }

    try {
        $pdo->exec('CREATE UNIQUE INDEX uq_provider_prc ON provider_profiles (prc_license_number)');
    } catch (Throwable $e) {
        // Index may already exist.
    }

    $columnsDone = true;
}

function provider_verification_normalize_prc(string $prc): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($prc)));
}

function provider_verification_validate_prc(string $prc): ?string
{
    $normalized = provider_verification_normalize_prc($prc);
    if ($normalized === '') {
        return 'PRC license number is required for doctors.';
    }
    if (!preg_match('/^[A-Z0-9\-]{5,20}$/', $normalized)) {
        return 'PRC license number must be 5–20 letters, numbers, or hyphens.';
    }
    return null;
}
