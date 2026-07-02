<?php
/**
 * Remember-me tokens (selector + validator).
 *
 * Cookie stores: medconnect_remember = "<selector>:<validator>"
 * DB stores selector + hash(validator), so cookie theft alone isn't enough.
 */

declare(strict_types=1);

require_once __DIR__ . '/request_helpers.php';

if (!defined('REMEMBER_ME_COOKIE')) {
    define('REMEMBER_ME_COOKIE', 'medconnect_remember');
}
if (!defined('REMEMBER_ME_DAYS')) {
    define('REMEMBER_ME_DAYS', 30);
}

function remember_me_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS remember_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            selector VARCHAR(24) NOT NULL,
            validator_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            last_used_at DATETIME NULL DEFAULT NULL,
            ip VARCHAR(45) NULL DEFAULT NULL,
            user_agent VARCHAR(255) NULL DEFAULT NULL,
            UNIQUE KEY uniq_selector (selector),
            KEY idx_user_id (user_id),
            KEY idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function remember_me_cookie_params(): array
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    return [
        'expires'  => time() + (REMEMBER_ME_DAYS * 86400),
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ];
}

function remember_me_clear_cookie(): void
{
    $params = remember_me_cookie_params();
    setcookie(REMEMBER_ME_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
    unset($_COOKIE[REMEMBER_ME_COOKIE]);
}

function remember_me_issue_token(PDO $pdo, int $userId): void
{
    remember_me_ensure_schema($pdo);

    $selector = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    $validator = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $validatorHash = password_hash($validator, PASSWORD_BCRYPT, ['cost' => 12]);
    $expiresAt = date('Y-m-d H:i:s', time() + (REMEMBER_ME_DAYS * 86400));

    // Best-effort cleanup of expired tokens.
    try {
        $pdo->exec('DELETE FROM remember_tokens WHERE expires_at < NOW()');
    } catch (Throwable $e) { /* non-fatal */ }

    $stmt = $pdo->prepare("
        INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at, ip, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $selector,
        $validatorHash,
        $expiresAt,
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);

    setcookie(REMEMBER_ME_COOKIE, $selector . ':' . $validator, remember_me_cookie_params());
}

function remember_me_restore_session(PDO $pdo): void
{
    if (!empty($_SESSION['user_id'])) {
        return;
    }
    $raw = (string) ($_COOKIE[REMEMBER_ME_COOKIE] ?? '');
    if ($raw === '' || !str_contains($raw, ':')) {
        return;
    }
    [$selector, $validator] = explode(':', $raw, 2);
    if ($selector === '' || $validator === '') {
        remember_me_clear_cookie();
        return;
    }

    remember_me_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT rt.id, rt.user_id, rt.validator_hash, rt.expires_at,
               u.first_name, u.last_name, u.email, u.role, u.is_active
        FROM remember_tokens rt
        JOIN users u ON u.id = rt.user_id
        WHERE rt.selector = ?
          AND rt.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$selector]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        remember_me_clear_cookie();
        return;
    }
    if (!(bool) ($row['is_active'] ?? false)) {
        remember_me_clear_cookie();
        return;
    }
    if (!password_verify($validator, (string) $row['validator_hash'])) {
        // Possible theft. Revoke this selector.
        try {
            $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
        } catch (Throwable $e) { /* non-fatal */ }
        remember_me_clear_cookie();
        return;
    }

    // Successful remember-me login -> rotate validator (prevents replay).
    try {
        $newValidator = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $newHash = password_hash($newValidator, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('UPDATE remember_tokens SET validator_hash = ?, last_used_at = NOW() WHERE id = ?')
            ->execute([$newHash, (int) $row['id']]);
        setcookie(REMEMBER_ME_COOKIE, $selector . ':' . $newValidator, remember_me_cookie_params());
    } catch (Throwable $e) {
        // If rotation fails, don't block login.
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['user_id'];
    $_SESSION['user_name'] = trim(((string) $row['first_name']) . ' ' . ((string) $row['last_name']));
    $_SESSION['user_email'] = (string) ($row['email'] ?? '');
    $_SESSION['user_role'] = (string) ($row['role'] ?? '');
    $_SESSION['first_name'] = (string) ($row['first_name'] ?? '');
    $_SESSION['last_name'] = (string) ($row['last_name'] ?? '');
    $_SESSION['last_activity'] = time();
    $_SESSION['login_time'] = time();
}

