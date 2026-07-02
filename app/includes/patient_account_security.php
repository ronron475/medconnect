<?php
/**
 * Patient account security — schema helpers, ID generation, and credential utilities.
 */
declare(strict_types=1);

/**
 * Ensure users and patient_registrations have security-related columns.
 */
function patient_security_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $userCols = patient_security_user_columns($pdo);
    $userAdds = [
        'last_login'              => 'DATETIME NULL DEFAULT NULL',
        'failed_attempts'         => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'lockout_until'           => 'DATETIME NULL DEFAULT NULL',
        'account_status'          => "VARCHAR(20) NOT NULL DEFAULT 'active'",
        'must_change_password'    => 'TINYINT(1) NOT NULL DEFAULT 0',
        'first_login'             => 'DATETIME NULL DEFAULT NULL',
        'password_changed_at'     => 'DATETIME NULL DEFAULT NULL',
        'password_reset_token'    => 'VARCHAR(64) NULL DEFAULT NULL',
        'password_reset_expiry'   => 'DATETIME NULL DEFAULT NULL',
        'password_setup_token'    => 'VARCHAR(64) NULL DEFAULT NULL',
        'password_setup_expiry'   => 'DATETIME NULL DEFAULT NULL',
        'terms_accepted_at'       => 'DATETIME NULL DEFAULT NULL',
        'privacy_accepted_at'     => 'DATETIME NULL DEFAULT NULL',
    ];
    foreach ($userAdds as $col => $def) {
        if (!in_array($col, $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$col} {$def}");
            $userCols[] = $col;
        }
    }

    $prCols = patient_security_pr_columns($pdo);
    $prAdds = [
        'user_id'                    => 'INT UNSIGNED NULL DEFAULT NULL',
        'patient_code'               => 'VARCHAR(20) NULL DEFAULT NULL',
        'middle_name'                => 'VARCHAR(80) NULL DEFAULT NULL',
        'suffix'                     => 'VARCHAR(20) NULL DEFAULT NULL',
        'civil_status'               => 'VARCHAR(30) NULL DEFAULT NULL',
        'purok'                      => 'VARCHAR(80) NULL DEFAULT NULL',
        'full_address'               => 'VARCHAR(255) NULL DEFAULT NULL',
        'current_medications'        => 'TEXT NULL',
        'emergency_contact_name'     => 'VARCHAR(100) NULL DEFAULT NULL',
        'emergency_contact_phone'    => 'VARCHAR(20) NULL DEFAULT NULL',
        'emergency_contact_relation' => 'VARCHAR(60) NULL DEFAULT NULL',
    ];
    foreach ($prAdds as $col => $def) {
        if (!in_array($col, $prCols, true)) {
            $pdo->exec("ALTER TABLE patient_registrations ADD COLUMN {$col} {$def}");
            $prCols[] = $col;
        }
    }

    try {
        $pdo->exec('CREATE UNIQUE INDEX idx_pr_patient_code ON patient_registrations (patient_code)');
    } catch (PDOException $e) {
        // Index may already exist.
    }
    try {
        $pdo->exec('CREATE INDEX idx_users_password_setup_token ON users (password_setup_token)');
    } catch (PDOException $e) {
        // Index may already exist.
    }
    try {
        $pdo->exec('CREATE INDEX idx_pr_user_id ON patient_registrations (user_id)');
    } catch (PDOException $e) {
        // Index may already exist.
    }

    $done = true;
}

/** @return string[] */
function patient_security_user_columns(PDO $pdo): array
{
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $cols = [];
    }
    return $cols;
}

/** @return string[] */
function patient_security_pr_columns(PDO $pdo): array
{
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM patient_registrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $cols = [];
    }
    return $cols;
}

/**
 * Generate a unique patient display ID, e.g. P2026-000145.
 */
function patient_generate_code(PDO $pdo): string
{
    $year = date('Y');
    $prefix = 'P' . $year . '-';

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM patient_registrations WHERE patient_code LIKE ?');
        $stmt->execute([$prefix . '%']);
        $seq = (int) $stmt->fetchColumn() + 1 + $attempt;
        $code = $prefix . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);

        $dup = $pdo->prepare('SELECT id FROM patient_registrations WHERE patient_code = ? LIMIT 1');
        $dup->execute([$code]);
        if (!$dup->fetch()) {
            return $code;
        }
    }

    return $prefix . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Cryptographically secure temporary password for fallback delivery.
 */
function patient_generate_temp_password(int $length = 14): string
{
    $lower   = 'abcdefghijkmnopqrstuvwxyz';
    $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits  = '23456789';
    $special = '!@#$%&*';
    $all     = $lower . $upper . $digits . $special;

    $chars = [
        $lower[random_int(0, strlen($lower) - 1)],
        $upper[random_int(0, strlen($upper) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $special[random_int(0, strlen($special) - 1)],
    ];
    for ($i = count($chars); $i < $length; $i++) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    shuffle($chars);
    return implode('', $chars);
}

/**
 * @return array{token: string, expiry: string}
 */
function patient_generate_setup_token(): array
{
    return [
        'token'  => bin2hex(random_bytes(32)),
        'expiry' => date('Y-m-d H:i:s', strtotime('+72 hours')),
    ];
}

function patient_hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Validate password meets minimum healthcare policy for patient accounts.
 */
function patient_validate_password_policy(string $password): ?string
{
    if (strlen($password) < 12) {
        return 'Password must be at least 12 characters.';
    }
    $score = 0;
    if (preg_match('/[a-z]/', $password)) {
        $score++;
    }
    if (preg_match('/[A-Z]/', $password)) {
        $score++;
    }
    if (preg_match('/\d/', $password)) {
        $score++;
    }
    if (preg_match('/[^A-Za-z0-9]/', $password)) {
        $score++;
    }
    if ($score < 4) {
        return 'Password must include uppercase, lowercase, a number, and a special character.';
    }
    return null;
}

function patient_normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function patient_is_valid_ph_mobile(string $phone): bool
{
    return (bool) preg_match('/^09\d{9}$/', patient_normalize_phone($phone));
}

/**
 * Whether the patient must complete first-login account setup.
 */
function patient_requires_account_setup(PDO $pdo, int $userId): bool
{
    patient_security_ensure_schema($pdo);
    $cols = patient_security_user_columns($pdo);
    if (!in_array('must_change_password', $cols, true)) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT must_change_password, account_status FROM users WHERE id = ? AND role = ? LIMIT 1');
    $stmt->execute([$userId, 'patient']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    if (($row['account_status'] ?? 'active') !== 'active') {
        return false;
    }
    return (int) ($row['must_change_password'] ?? 0) === 1;
}

/**
 * Record first login timestamp if not yet set.
 */
function patient_record_first_login(PDO $pdo, int $userId): void
{
    $cols = patient_security_user_columns($pdo);
    if (!in_array('first_login', $cols, true)) {
        return;
    }
    $pdo->prepare('UPDATE users SET first_login = COALESCE(first_login, NOW()) WHERE id = ?')
        ->execute([$userId]);
}

/**
 * Update last_login on successful authentication.
 */
function patient_record_login(PDO $pdo, int $userId): void
{
    $cols = patient_security_user_columns($pdo);
    if (in_array('last_login', $cols, true)) {
        $pdo->prepare('UPDATE users SET last_login = NOW(), failed_attempts = 0 WHERE id = ?')
            ->execute([$userId]);
    }
}

/**
 * Complete account setup after password change and policy acceptance.
 */
function patient_complete_account_setup(PDO $pdo, int $userId, string $newPassword): void
{
    patient_security_ensure_schema($pdo);
    $hash = patient_hash_password($newPassword);
    $pdo->prepare("
        UPDATE users SET
            password = ?,
            must_change_password = 0,
            password_changed_at = NOW(),
            password_setup_token = NULL,
            password_setup_expiry = NULL,
            terms_accepted_at = NOW(),
            privacy_accepted_at = NOW(),
            account_status = 'active',
            updated_at = NOW()
        WHERE id = ? AND role = 'patient'
    ")->execute([$hash, $userId]);
}

/**
 * Find user by valid password setup token.
 */
function patient_find_by_setup_token(PDO $pdo, string $token): ?array
{
    patient_security_ensure_schema($pdo);
    if ($token === '') {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, role
        FROM users
        WHERE password_setup_token = ?
          AND password_setup_expiry > NOW()
          AND role = 'patient'
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Whether SMTP/mailer is configured enough to attempt delivery.
 */
function patient_email_delivery_available(): bool
{
    return defined('MAIL_USERNAME') && MAIL_USERNAME !== '' && defined('MAIL_PASSWORD') && MAIL_PASSWORD !== '';
}
