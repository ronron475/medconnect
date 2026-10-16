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

    if (!provider_verification_ensure_columns($pdo)) {
        return;
    }

    $done = true;
}

function provider_verification_ensure_columns(PDO $pdo): bool
{
    static $columnsDone = false;
    if ($columnsDone) {
        return true;
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
        $pdo->exec("
            ALTER TABLE provider_profiles
            MODIFY COLUMN verification_status
                ENUM('pending', 'verified', 'rejected', 'needs_review') NOT NULL DEFAULT 'pending'
        ");
    } catch (Throwable $e) {
        // Enum may already include needs_review.
    }

    $indexReady = provider_verification_ensure_prc_unique_index($pdo);
    prc_verification_ensure_history($pdo);
    if (!$indexReady) {
        return false;
    }

    $columnsDone = true;
    return true;
}

/**
 * True when some unique index covers the full prc_license_number column.
 * The index name is not evidence; a same-named non-unique or composite index does not qualify.
 *
 * @param list<array<string, mixed>> $rows
 */
function provider_verification_index_rows_enforce_prc_unique(array $rows): bool
{
    $grouped = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = (string) ($row['Key_name'] ?? $row['key_name'] ?? '');
        if ($name === '') {
            continue;
        }
        $grouped[$name][] = $row;
    }

    foreach ($grouped as $indexRows) {
        if (count($indexRows) !== 1) {
            continue;
        }
        $index = $indexRows[0];
        $column = strtolower((string) ($index['Column_name'] ?? $index['column_name'] ?? ''));
        $sequence = (int) ($index['Seq_in_index'] ?? $index['seq_in_index'] ?? 0);
        $prefix = $index['Sub_part'] ?? $index['sub_part'] ?? null;
        $unique = (int) ($index['Non_unique'] ?? $index['non_unique'] ?? 1) === 0;
        if ($unique && $column === 'prc_license_number' && $sequence === 1 && ($prefix === null || $prefix === '')) {
            return true;
        }
    }

    return false;
}

function provider_verification_prc_unique_index_exists(PDO $pdo): bool
{
    $stmt = $pdo->query('SHOW INDEX FROM provider_profiles');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    return provider_verification_index_rows_enforce_prc_unique(is_array($rows) ? $rows : []);
}

function provider_verification_ensure_prc_unique_index(PDO $pdo): bool
{
    if (provider_verification_prc_unique_index_exists($pdo)) {
        return true;
    }

    try {
        $pdo->exec('CREATE UNIQUE INDEX uq_provider_prc ON provider_profiles (prc_license_number)');
    } catch (Throwable $e) {
        $sqlState = '';
        $code = 0;
        if ($e instanceof PDOException) {
            $info = $e->errorInfo ?? [];
            $sqlState = (string) ($info[0] ?? '');
            $code = (int) ($info[1] ?? 0);
        }
        if ($code === 1061 && provider_verification_prc_unique_index_exists($pdo)) {
            return true;
        }
        provider_verification_log_prc_index_failure($sqlState, $code);

        return false;
    }

    if (provider_verification_prc_unique_index_exists($pdo)) {
        return true;
    }

    provider_verification_log_prc_index_failure('', 0);

    return false;
}

function provider_verification_log_prc_index_failure(string $sqlState, int $code): void
{
    $state = $sqlState !== '' ? $sqlState : 'none';
    error_log('provider_verification_ensure_columns unique index: SQLSTATE ' . $state . ' code ' . $code);
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
    if (!preg_match('/\d/', $normalized)) {
        return 'PRC license number must include at least one digit.';
    }
    return null;
}

function prc_verification_ensure_history(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prc_verification_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_user_id INT UNSIGNED NOT NULL,
            prc_license_number VARCHAR(32) NOT NULL,
            status ENUM('pending', 'verified', 'rejected', 'needs_review') NOT NULL,
            verifier_id INT UNSIGNED NULL,
            remarks VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_prc_events_provider (provider_user_id, id),
            KEY idx_prc_events_license (prc_license_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prc_license_locks (
            prc_license_number VARCHAR(32) NOT NULL,
            holder_type ENUM('application', 'provider') NOT NULL,
            holder_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (prc_license_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $done = true;
}

/**
 * Reserve a normalized PRC number. The same holder may claim it again.
 * A different holder is rejected.
 */
function prc_license_lock_claim(PDO $pdo, string $prc, string $holderType, int $holderId): ?string
{
    provider_verification_ensure_schema($pdo);
    $prc = provider_verification_normalize_prc($prc);
    if (!in_array($holderType, ['application', 'provider'], true) || $holderId <= 0 || $prc === '') {
        return 'PRC license number could not be reserved.';
    }

    $stmt = $pdo->prepare('SELECT holder_type, holder_id FROM prc_license_locks WHERE prc_license_number = ? LIMIT 1');
    $stmt->execute([$prc]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if ((string) $row['holder_type'] === $holderType && (int) $row['holder_id'] === $holderId) {
            return null;
        }
        return 'This PRC license number is already registered to another doctor.';
    }

    try {
        $pdo->prepare('
            INSERT INTO prc_license_locks (prc_license_number, holder_type, holder_id)
            VALUES (?, ?, ?)
        ')->execute([$prc, $holderType, $holderId]);
    } catch (PDOException $e) {
        $stmt->execute([$prc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (string) $row['holder_type'] === $holderType && (int) $row['holder_id'] === $holderId) {
            return null;
        }
        return 'This PRC license number is already registered to another doctor.';
    }

    return null;
}

function prc_license_lock_release(PDO $pdo, string $prc, string $holderType, int $holderId): void
{
    provider_verification_ensure_schema($pdo);
    $prc = provider_verification_normalize_prc($prc);
    $pdo->prepare('
        DELETE FROM prc_license_locks
        WHERE prc_license_number = ? AND holder_type = ? AND holder_id = ?
    ')->execute([$prc, $holderType, $holderId]);
}

function prc_license_lock_transfer(PDO $pdo, string $prc, string $fromType, int $fromId, string $toType, int $toId): ?string
{
    provider_verification_ensure_schema($pdo);
    $prc = provider_verification_normalize_prc($prc);
    $stmt = $pdo->prepare('SELECT holder_type, holder_id FROM prc_license_locks WHERE prc_license_number = ? LIMIT 1');
    $stmt->execute([$prc]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return prc_license_lock_claim($pdo, $prc, $toType, $toId);
    }
    if ((string) $row['holder_type'] !== $fromType || (int) $row['holder_id'] !== $fromId) {
        if ((string) $row['holder_type'] === $toType && (int) $row['holder_id'] === $toId) {
            return null;
        }
        return 'This PRC license number is already registered to another doctor.';
    }
    $pdo->prepare('
        UPDATE prc_license_locks
        SET holder_type = ?, holder_id = ?
        WHERE prc_license_number = ? AND holder_type = ? AND holder_id = ?
    ')->execute([$toType, $toId, $prc, $fromType, $fromId]);

    return null;
}

/**
 * Append a PRC decision without changing account status.
 * Verified is stored only when an authorized reviewer submits remarks.
 * That remark is a manual decision, not a PRC API result.
 *
 * @return array{success:bool,message:string,status?:string}
 */
function prc_record_decision(PDO $pdo, int $providerUserId, string $status, int $verifierId, string $remarks): array
{
    provider_verification_ensure_schema($pdo);
    $status = strtolower(trim($status));
    $allowed = ['pending', 'verified', 'rejected', 'needs_review'];
    if (!in_array($status, $allowed, true)) {
        return ['success' => false, 'message' => 'Invalid PRC verification status.'];
    }

    $remarks = trim($remarks);
    if ($remarks === '' || mb_strlen($remarks) < 5) {
        return ['success' => false, 'message' => 'Remarks are required and must be at least 5 characters.'];
    }
    if (mb_strlen($remarks) > 500) {
        $remarks = mb_substr($remarks, 0, 500);
    }

    $verifier = $pdo->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
    $verifier->execute([$verifierId]);
    $verifierRow = $verifier->fetch(PDO::FETCH_ASSOC);
    if (!$verifierRow || (string) $verifierRow['role'] !== 'superadmin') {
        return ['success' => false, 'message' => 'Only a Super Administrator can record a PRC verification decision.'];
    }

    $profile = $pdo->prepare('SELECT id, prc_license_number, verification_status FROM provider_profiles WHERE user_id = ? LIMIT 1');
    $profile->execute([$providerUserId]);
    $row = $profile->fetch(PDO::FETCH_ASSOC);
    if (!$row || trim((string) $row['prc_license_number']) === '') {
        return ['success' => false, 'message' => 'No PRC license on file for this doctor.'];
    }

    $doctor = $pdo->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
    $doctor->execute([$providerUserId]);
    $doctorRow = $doctor->fetch(PDO::FETCH_ASSOC);
    if (!$doctorRow || (string) $doctorRow['role'] !== 'provider') {
        return ['success' => false, 'message' => 'Doctor account not found.'];
    }

    $verifiedBy = $status === 'verified' ? $verifierId : null;
    $verifiedAt = $status === 'verified' ? date('Y-m-d H:i:s') : null;
    $rejectionNote = $status === 'rejected' ? $remarks : null;
    $labels = [
        'pending' => 'Pending',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        'needs_review' => 'Needs Review',
    ];

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $fault = prc_record_decision_test_fault();
        if ($fault === 'profile') {
            throw new RuntimeException('simulated profile update failure');
        }
        $pdo->prepare('
            UPDATE provider_profiles
            SET verification_status = ?, verified_by = ?, verified_at = ?, rejection_note = ?, updated_at = NOW()
            WHERE user_id = ?
        ')->execute([$status, $verifiedBy, $verifiedAt, $rejectionNote, $providerUserId]);
        if ($fault === 'event') {
            throw new RuntimeException('simulated history insert failure');
        }
        prc_append_event($pdo, $providerUserId, (string) $row['prc_license_number'], $status, $verifierId, $remarks);
        if ($ownTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('prc_record_decision: ' . $e->getMessage());
        if (!$ownTx) {
            throw $e;
        }
        return ['success' => false, 'message' => 'PRC verification decision could not be saved.'];
    }

    return [
        'success' => true,
        'status' => $status,
        'message' => 'PRC status recorded as ' . $labels[$status] . '. This is a manual review decision, not an automated PRC result.',
    ];
}

/**
 * Test-only fault for the decision transaction. Production callers leave this unset.
 */
function prc_record_decision_test_fault(?string $stage = null): ?string
{
    static $fault = null;
    if (func_num_args() > 0) {
        $fault = $stage;
    }
    return $fault;
}

/**
 * Null means the provider is verified and login may continue.
 * Any missing row, non-verified status, or database failure denies login.
 *
 * @param null|callable(PDO,int):mixed $statusReader
 */
function provider_login_verification_denial(PDO $pdo, int $userId, ?callable $statusReader = null): ?string
{
    try {
        if ($statusReader !== null) {
            $verification = $statusReader($pdo, $userId);
        } else {
            provider_verification_ensure_schema($pdo);
            $stmt = $pdo->prepare('SELECT verification_status FROM provider_profiles WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $verification = $stmt->fetchColumn();
        }
        if ($verification === 'verified') {
            return null;
        }
        return match ((string) $verification) {
            'rejected' => 'Your PRC verification was rejected. Contact the administrator for assistance.',
            'needs_review' => 'Your PRC license is awaiting a Super Administrator verification decision. You can sign in after that decision is recorded as verified.',
            default => 'Your doctor account is not approved yet. A Super Administrator must verify PRC and approve the application before you can sign in.',
        };
    } catch (Throwable $e) {
        error_log('provider login verification: ' . $e->getMessage());
        return 'Your doctor account cannot be verified right now. Please try again later.';
    }
}

function prc_append_event(
    PDO $pdo,
    int $providerUserId,
    string $prc,
    string $status,
    ?int $verifierId,
    string $remarks
): void {
    $pdo->prepare('
        INSERT INTO prc_verification_events
            (provider_user_id, prc_license_number, status, verifier_id, remarks)
        VALUES (?, ?, ?, ?, ?)
    ')->execute([
        $providerUserId,
        provider_verification_normalize_prc($prc),
        $status,
        $verifierId,
        mb_substr(trim($remarks), 0, 500),
    ]);
}
