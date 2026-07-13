<?php
/**
 * Provider password change — validation, brute-force protection, audit logging.
 */

require_once __DIR__ . '/provider_settings.php';
require_once __DIR__ . '/password_history.php';
require_once __DIR__ . '/remember_me.php';

const PROVIDER_PASSWORD_MAX_ATTEMPTS = 5;
const PROVIDER_PASSWORD_LOCK_MINUTES = 15;

function provider_password_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS provider_activity_logs (
            log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_id INT UNSIGNED NOT NULL,
            action VARCHAR(120) NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (log_id),
            KEY idx_provider_created (provider_id, created_at),
            KEY idx_action (action),
            CONSTRAINT fk_pal_provider FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS provider_password_attempts (
            provider_id INT UNSIGNED NOT NULL,
            failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (provider_id),
            CONSTRAINT fk_ppa_provider FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function provider_password_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    foreach ($candidates as $raw) {
        $ip = trim(explode(',', $raw)[0]);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return 'unknown';
}

function provider_password_client_ua(): string
{
    return mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
}

function provider_password_log_activity(PDO $pdo, int $providerId, string $action): void
{
    provider_password_ensure_schema($pdo);
    $stmt = $pdo->prepare('
        INSERT INTO provider_activity_logs (provider_id, action, ip_address, user_agent)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([
        $providerId,
        mb_substr(trim($action), 0, 120),
        provider_password_client_ip(),
        provider_password_client_ua(),
    ]);
}

/**
 * @return array{locked:bool,message?:string,retry_after?:int}
 */
function provider_password_check_lock(PDO $pdo, int $providerId): array
{
    provider_password_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT failed_attempts, locked_until FROM provider_password_attempts WHERE provider_id = ? LIMIT 1');
    $stmt->execute([$providerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['locked_until'])) {
        return ['locked' => false];
    }

    $lockedUntil = strtotime((string) $row['locked_until']);
    if ($lockedUntil > time()) {
        $retryAfter = max(1, (int) ceil(($lockedUntil - time()) / 60));
        return [
            'locked' => true,
            'message' => 'Too many failed attempts. Password change is locked for ' . $retryAfter . ' more minute(s).',
            'retry_after' => $retryAfter,
        ];
    }

    $pdo->prepare('UPDATE provider_password_attempts SET failed_attempts = 0, locked_until = NULL WHERE provider_id = ?')
        ->execute([$providerId]);

    return ['locked' => false];
}

function provider_password_record_failed_attempt(PDO $pdo, int $providerId): void
{
    provider_password_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT failed_attempts FROM provider_password_attempts WHERE provider_id = ? LIMIT 1');
    $stmt->execute([$providerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $attempts = (int) $row['failed_attempts'] + 1;
        $lockedUntil = null;
        if ($attempts >= PROVIDER_PASSWORD_MAX_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + (PROVIDER_PASSWORD_LOCK_MINUTES * 60));
            $attempts = PROVIDER_PASSWORD_MAX_ATTEMPTS;
        }
        $pdo->prepare('
            UPDATE provider_password_attempts SET failed_attempts = ?, locked_until = ? WHERE provider_id = ?
        ')->execute([$attempts, $lockedUntil, $providerId]);
        return;
    }

    $pdo->prepare('INSERT INTO provider_password_attempts (provider_id, failed_attempts) VALUES (?, 1)')
        ->execute([$providerId]);
}

function provider_password_reset_attempts(PDO $pdo, int $providerId): void
{
    provider_password_ensure_schema($pdo);
    $pdo->prepare('
        INSERT INTO provider_password_attempts (provider_id, failed_attempts, locked_until)
        VALUES (?, 0, NULL)
        ON DUPLICATE KEY UPDATE failed_attempts = 0, locked_until = NULL
    ')->execute([$providerId]);
}

/**
 * @return array{valid:bool,message?:string,level?:string,score?:int}
 */
function provider_password_validate_rules(string $password): array
{
    if (strlen($password) < 8) {
        return ['valid' => false, 'message' => 'Password does not meet security requirements.', 'level' => 'weak', 'score' => 0];
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return ['valid' => false, 'message' => 'Password does not meet security requirements.', 'level' => 'weak', 'score' => 1];
    }
    if (!preg_match('/[a-z]/', $password)) {
        return ['valid' => false, 'message' => 'Password does not meet security requirements.', 'level' => 'weak', 'score' => 1];
    }
    if (!preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'message' => 'Password does not meet security requirements.', 'level' => 'weak', 'score' => 2];
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return ['valid' => false, 'message' => 'Password does not meet security requirements.', 'level' => 'medium', 'score' => 3];
    }

    $score = 0;
    if (strlen($password) >= 8) $score++;
    if (strlen($password) >= 12) $score++;
    if (preg_match('/[A-Z]/', $password)) $score++;
    if (preg_match('/[a-z]/', $password)) $score++;
    if (preg_match('/[0-9]/', $password)) $score++;
    if (preg_match('/[^A-Za-z0-9]/', $password)) $score++;

    if ($score <= 3) {
        return ['valid' => true, 'level' => 'weak', 'score' => $score];
    }
    if ($score <= 5) {
        return ['valid' => true, 'level' => 'medium', 'score' => $score];
    }
    return ['valid' => true, 'level' => 'strong', 'score' => $score];
}

/**
 * @return array{status:string,success:bool,message:string,errors?:array<string,string>}
 */
function provider_password_change(PDO $pdo, int $providerId, string $current, string $newPassword, string $confirm): array
{
    provider_password_ensure_schema($pdo);

    $current = (string) $current;
    $newPassword = (string) $newPassword;
    $confirm = (string) $confirm;

    $lock = provider_password_check_lock($pdo, $providerId);
    if ($lock['locked']) {
        return ['status' => 'error', 'success' => false, 'message' => $lock['message']];
    }

    if ($newPassword !== $confirm) {
        return ['status' => 'error', 'success' => false, 'message' => 'Passwords do not match.'];
    }

    $rules = provider_password_validate_rules($newPassword);
    if (!$rules['valid']) {
        return ['status' => 'error', 'success' => false, 'message' => $rules['message'] ?? 'Password does not meet security requirements.'];
    }

    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? AND role = ? LIMIT 1');
    $stmt->execute([$providerId, 'provider']);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($current, (string) $hash)) {
        provider_password_record_failed_attempt($pdo, $providerId);
        provider_password_log_activity($pdo, $providerId, 'Password Change Failed - Incorrect Current Password');
        return ['status' => 'error', 'success' => false, 'message' => 'Current password is incorrect.'];
    }

    if (password_verify($newPassword, (string) $hash)) {
        return ['status' => 'error', 'success' => false, 'message' => 'New password must be different from the current password.'];
    }

    // Prevent reuse of recent passwords.
    try {
        if (password_history_is_reused($pdo, $providerId, $newPassword, 5)) {
            return ['status' => 'error', 'success' => false, 'message' => 'You cannot reuse a recent password. Please choose a new one.'];
        }
    } catch (Throwable $e) { /* non-fatal */ }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ? AND role = ?')
        ->execute([$newHash, $providerId, 'provider']);

    remember_me_revoke_for_user($pdo, $providerId);
    remember_me_clear_cookie();

    // Store password hashes in history (best-effort).
    try { password_history_add($pdo, $providerId, (string) $hash); } catch (Throwable $e) { /* non-fatal */ }
    try { password_history_add($pdo, $providerId, (string) $newHash); } catch (Throwable $e) { /* non-fatal */ }

    provider_password_reset_attempts($pdo, $providerId);
    provider_password_log_activity($pdo, $providerId, 'Password Updated Successfully');

    return [
        'status' => 'success',
        'success' => true,
        'message' => 'Password updated successfully.',
    ];
}
