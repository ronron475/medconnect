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
if (!defined('REMEMBER_ME_IDLE_HOLD')) {
    define('REMEMBER_ME_IDLE_HOLD', 'medconnect_idle_hold');
}
if (!defined('REMEMBER_ME_DAYS')) {
    define('REMEMBER_ME_DAYS', 30);
}
if (!defined('REMEMBER_ME_ROTATION_GRACE')) {
    define('REMEMBER_ME_ROTATION_GRACE', 30);
}

function remember_me_ensure_schema(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (!empty($done[$key])) {
        return;
    }

    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS remember_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                selector TEXT NOT NULL UNIQUE,
                validator_hash TEXT NOT NULL,
                previous_validator_hash TEXT NULL,
                previous_valid_until TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT NOT NULL,
                last_used_at TEXT NULL,
                ip TEXT NULL,
                user_agent TEXT NULL
            )
        ");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS remember_tokens (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                selector VARCHAR(24) NOT NULL,
                validator_hash VARCHAR(255) NOT NULL,
                previous_validator_hash VARCHAR(255) NULL DEFAULT NULL,
                previous_valid_until DATETIME NULL DEFAULT NULL,
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
        $columns = remember_me_table_columns($pdo);
        if (!in_array('previous_validator_hash', $columns, true)) {
            $pdo->exec('ALTER TABLE remember_tokens ADD COLUMN previous_validator_hash VARCHAR(255) NULL DEFAULT NULL');
        }
        if (!in_array('previous_valid_until', $columns, true)) {
            $pdo->exec('ALTER TABLE remember_tokens ADD COLUMN previous_valid_until DATETIME NULL DEFAULT NULL');
        }
    }

    $done[$key] = true;
}

/** @return list<string> */
function remember_me_table_columns(PDO $pdo): array
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $rows = $pdo->query('PRAGMA table_info(remember_tokens)')->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $rows));
    }
    $rows = $pdo->query('SHOW COLUMNS FROM remember_tokens')->fetchAll(PDO::FETCH_ASSOC);
    return array_values(array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $rows));
}

function remember_me_cookie_params(): array
{
    $isHttps = function_exists('medconnect_request_is_https')
        ? medconnect_request_is_https()
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443));
    $path = function_exists('medconnect_session_cookie_path')
        ? medconnect_session_cookie_path()
        : '/';
    return [
        'expires'  => time() + (REMEMBER_ME_DAYS * 86400),
        'path'     => $path,
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

function remember_me_idle_hold_active(): bool
{
    return (string) ($_COOKIE[REMEMBER_ME_IDLE_HOLD] ?? '') === '1';
}

/**
 * Session-scoped marker. Idle expiry ends the PHP session but must not
 * immediately mint a new one from the persistent remember-me cookie.
 * The marker dies when the browser closes, so a later visit can still restore.
 */
function remember_me_mark_idle_hold(): void
{
    $params = remember_me_cookie_params();
    $params['expires'] = 0;
    setcookie(REMEMBER_ME_IDLE_HOLD, '1', $params);
    $_COOKIE[REMEMBER_ME_IDLE_HOLD] = '1';
}

function remember_me_clear_idle_hold(): void
{
    $params = remember_me_cookie_params();
    setcookie(REMEMBER_ME_IDLE_HOLD, '', [
        'expires'  => time() - 3600,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
    unset($_COOKIE[REMEMBER_ME_IDLE_HOLD]);
}

function remember_me_revoke_selector(PDO $pdo, string $selector): void
{
    if ($selector === '') {
        return;
    }
    remember_me_ensure_schema($pdo);
    try {
        $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
    } catch (Throwable $e) { /* non-fatal */ }
}

function remember_me_revoke_for_user(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    remember_me_ensure_schema($pdo);
    try {
        $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $e) { /* non-fatal */ }
}

function remember_me_revoke_current_cookie(PDO $pdo): void
{
    $raw = (string) ($_COOKIE[REMEMBER_ME_COOKIE] ?? '');
    if ($raw !== '' && str_contains($raw, ':')) {
        [$selector] = explode(':', $raw, 2);
        remember_me_revoke_selector($pdo, (string) $selector);
    }
    remember_me_clear_cookie();
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
        $pdo->prepare('DELETE FROM remember_tokens WHERE expires_at < ?')->execute([date('Y-m-d H:i:s')]);
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

    $cookie = $selector . ':' . $validator;
    setcookie(REMEMBER_ME_COOKIE, $cookie, remember_me_cookie_params());
    $_COOKIE[REMEMBER_ME_COOKIE] = $cookie;
}

/**
 * Verify a remember-me cookie and rotate it.
 * A reused validator after the rotation grace window revokes that token.
 *
 * @return array{ok:bool,reason?:string,user_id?:int,cookie?:string}
 */
function remember_me_authenticate_cookie(PDO $pdo, string $raw): array
{
    $parsed = remember_me_parse_cookie($raw);
    if ($parsed === null) {
        return ['ok' => false, 'reason' => 'malformed'];
    }
    [$selector, $validator] = $parsed;

    remember_me_ensure_schema($pdo);
    $stmt = $pdo->prepare('
        SELECT id, user_id, validator_hash, previous_validator_hash, previous_valid_until, expires_at
        FROM remember_tokens
        WHERE selector = ?
        LIMIT 1
    ');
    $stmt->execute([$selector]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'reason' => 'missing', 'selector' => $selector];
    }

    $expiresAt = strtotime((string) ($row['expires_at'] ?? '')) ?: 0;
    if ($expiresAt <= time()) {
        remember_me_revoke_selector($pdo, $selector);
        return ['ok' => false, 'reason' => 'expired', 'selector' => $selector];
    }

    $currentHash = (string) ($row['validator_hash'] ?? '');
    if ($currentHash !== '' && password_verify($validator, $currentHash)) {
        $newValidator = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $newHash = password_hash($newValidator, PASSWORD_BCRYPT, ['cost' => 12]);
        $now = date('Y-m-d H:i:s');
        $graceUntil = date('Y-m-d H:i:s', time() + REMEMBER_ME_ROTATION_GRACE);
        $updated = $pdo->prepare('
            UPDATE remember_tokens
            SET validator_hash = ?, previous_validator_hash = ?, previous_valid_until = ?, last_used_at = ?
            WHERE id = ? AND validator_hash = ?
        ');
        $updated->execute([$newHash, $currentHash, $graceUntil, $now, (int) $row['id'], $currentHash]);
        if ($updated->rowCount() !== 1) {
            return ['ok' => false, 'reason' => 'stale', 'selector' => $selector];
        }
        $cookie = $selector . ':' . $newValidator;
        setcookie(REMEMBER_ME_COOKIE, $cookie, remember_me_cookie_params());
        $_COOKIE[REMEMBER_ME_COOKIE] = $cookie;
        return [
            'ok' => true,
            'user_id' => (int) $row['user_id'],
            'cookie' => $cookie,
            'selector' => $selector,
        ];
    }

    $previousHash = (string) ($row['previous_validator_hash'] ?? '');
    $previousUntil = strtotime((string) ($row['previous_valid_until'] ?? '')) ?: 0;
    if ($previousHash !== '' && $previousUntil > time() && password_verify($validator, $previousHash)) {
        return [
            'ok' => true,
            'user_id' => (int) $row['user_id'],
            'cookie' => $raw,
            'selector' => $selector,
            'grace' => true,
        ];
    }

    remember_me_revoke_selector($pdo, $selector);
    return ['ok' => false, 'reason' => 'replay', 'selector' => $selector];
}

/** @return array{0:string,1:string}|null */
function remember_me_parse_cookie(string $raw): ?array
{
    if ($raw === '' || !str_contains($raw, ':')) {
        return null;
    }
    [$selector, $validator] = explode(':', $raw, 2);
    $selector = (string) $selector;
    $validator = (string) $validator;
    if ($selector === '' || $validator === '' || strlen($selector) > 32) {
        return null;
    }
    return [$selector, $validator];
}

function remember_me_restore_session(PDO $pdo): void
{
    if (!empty($_SESSION['user_id'])) {
        return;
    }
    if (remember_me_idle_hold_active()) {
        return;
    }
    $raw = (string) ($_COOKIE[REMEMBER_ME_COOKIE] ?? '');
    if ($raw === '' || !str_contains($raw, ':')) {
        return;
    }

    $auth = remember_me_authenticate_cookie($pdo, $raw);
    if (empty($auth['ok'])) {
        if (($auth['reason'] ?? '') !== 'stale') {
            remember_me_clear_cookie();
        }
        return;
    }
    $selector = (string) ($auth['selector'] ?? '');

    require_once __DIR__ . '/user_account_status.php';
    user_account_status_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT id AS user_id, first_name, last_name, email, role, is_active, account_status, session_epoch
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([(int) ($auth['user_id'] ?? 0)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        remember_me_revoke_selector($pdo, $selector);
        remember_me_clear_cookie();
        return;
    }

    if (!user_account_login_allowed_for_row($row)) {
        remember_me_revoke_selector($pdo, $selector);
        remember_me_clear_cookie();
        return;
    }

    $role = (string) ($row['role'] ?? '');
    if ($role === 'provider') {
        try {
            require_once __DIR__ . '/provider_verification.php';
            provider_verification_ensure_schema($pdo);
            $pStmt = $pdo->prepare('SELECT verification_status FROM provider_profiles WHERE user_id = ? LIMIT 1');
            $pStmt->execute([(int) $row['user_id']]);
            $verification = $pStmt->fetchColumn();
            if (!$verification || $verification !== 'verified') {
                remember_me_revoke_selector($pdo, $selector);
                remember_me_clear_cookie();
                return;
            }

            require_once dirname(__DIR__) . '/core/DoctorApplicationService.php';
            $doctorGate = (new DoctorApplicationService($pdo))->loginDenialReason(
                (int) $row['user_id'],
                (string) ($row['email'] ?? '')
            );
            if ($doctorGate !== null) {
                remember_me_revoke_selector($pdo, $selector);
                remember_me_clear_cookie();
                return;
            }
        } catch (Throwable $e) {
            remember_me_revoke_selector($pdo, $selector);
            remember_me_clear_cookie();
            return;
        }
    }

    session_regenerate_id(true);
    medconnect_session_set_identity([
        'id' => (int) $row['user_id'],
        'first_name' => (string) ($row['first_name'] ?? ''),
        'last_name' => (string) ($row['last_name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'role' => (string) ($row['role'] ?? ''),
        'session_epoch' => (int) ($row['session_epoch'] ?? 1),
    ]);
    unset($_SESSION['remember_me_extended']);
}

