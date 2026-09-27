<?php
declare(strict_types=1);

require_once BASE_PATH . '/app/includes/audit_log.php';
require_once __DIR__ . '/../../app/core/NotificationManager.php';

function login_security_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_login_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            browser VARCHAR(80) NULL,
            os VARCHAR(80) NULL,
            device_type VARCHAR(30) NULL,
            device_fingerprint CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_fp (device_fingerprint),
            INDEX idx_ip (ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_devices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL,
            device_fingerprint CHAR(64) NOT NULL,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_ip VARCHAR(45) NULL,
            last_user_agent VARCHAR(255) NULL,
            UNIQUE KEY uniq_user_device (user_id, device_fingerprint),
            INDEX idx_user (user_id),
            INDEX idx_last_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $done = true;
}

function login_security_valid_ip(string $raw): string
{
    $ip = trim($raw);
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return '';
}

function login_security_env_flag(string $key, bool $default): bool
{
    if (function_exists('medconnect_env_bool')) {
        return medconnect_env_bool($key, $default);
    }
    $raw = getenv($key);
    if ($raw === false || $raw === '') {
        $raw = $_ENV[$key] ?? '';
    }
    if ($raw === false || $raw === '') {
        return $default;
    }
    return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
}

/** @return list<string> */
function login_security_trusted_proxies(): array
{
    $raw = getenv('MEDCONNECT_TRUSTED_PROXIES');
    if ($raw === false || $raw === '') {
        $raw = (string) ($_ENV['MEDCONNECT_TRUSTED_PROXIES'] ?? '');
    }
    $out = [];
    foreach (explode(',', (string) $raw) as $part) {
        $part = trim($part);
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return $out;
}

function login_security_ip_matches_rule(string $ip, string $rule): bool
{
    $rule = trim($rule);
    if ($rule === '' || $ip === '') {
        return false;
    }
    if (!str_contains($rule, '/')) {
        $left = @inet_pton($ip);
        $right = @inet_pton($rule);
        return $left !== false && $right !== false && $left === $right;
    }

    [$subnet, $bitsRaw] = explode('/', $rule, 2);
    if ($bitsRaw === '' || !ctype_digit($bitsRaw)) {
        return false;
    }
    $bits = (int) $bitsRaw;
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }
    $maxBits = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }
    $bytes = intdiv($bits, 8);
    $remainder = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
        return false;
    }
    if ($remainder === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $remainder)) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
}

function login_security_address_is_trusted_proxy(string $ip): bool
{
    foreach (login_security_trusted_proxies() as $rule) {
        if (login_security_ip_matches_rule($ip, $rule)) {
            return true;
        }
    }
    return false;
}

/**
 * Client IP for login throttles.
 * REMOTE_ADDR is the connection peer and cannot be chosen by the caller.
 * X-Forwarded-For, X-Real-IP, and CF-Connecting-IP are used only when
 * MEDCONNECT_TRUST_PROXY is on and that peer is listed in MEDCONNECT_TRUSTED_PROXIES.
 */
function login_security_ip(): string
{
    $remote = login_security_valid_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $trustForwarded = $remote !== ''
        && login_security_env_flag('MEDCONNECT_TRUST_PROXY', true)
        && login_security_address_is_trusted_proxy($remote);
    if (!$trustForwarded) {
        return $remote;
    }

    $chain = [];
    foreach (explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $part) {
        $ip = login_security_valid_ip($part);
        if ($ip !== '') {
            $chain[] = $ip;
        }
    }
    for ($i = count($chain) - 1; $i >= 0; $i--) {
        if (!login_security_address_is_trusted_proxy($chain[$i])) {
            return $chain[$i];
        }
    }

    foreach (['HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $header) {
        $ip = login_security_valid_ip((string) ($_SERVER[$header] ?? ''));
        if ($ip !== '' && !login_security_address_is_trusted_proxy($ip)) {
            return $ip;
        }
    }

    return $remote;
}

function login_security_user_agent(): string
{
    return substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
}

/** @return array{browser: string, os: string, device_type: string} */
function login_security_parse_ua(string $ua): array
{
    $u = strtolower($ua);

    $device = 'desktop';
    if (str_contains($u, 'mobile') || str_contains($u, 'android') || str_contains($u, 'iphone')) $device = 'mobile';
    if (str_contains($u, 'ipad') || str_contains($u, 'tablet')) $device = 'tablet';

    $os = 'Unknown';
    if (str_contains($u, 'windows')) $os = 'Windows';
    elseif (str_contains($u, 'android')) $os = 'Android';
    elseif (str_contains($u, 'iphone') || str_contains($u, 'ios')) $os = 'iOS';
    elseif (str_contains($u, 'mac os') || str_contains($u, 'macintosh')) $os = 'macOS';
    elseif (str_contains($u, 'linux')) $os = 'Linux';

    $browser = 'Unknown';
    if (str_contains($u, 'edg/')) $browser = 'Edge';
    elseif (str_contains($u, 'chrome/') && !str_contains($u, 'chromium')) $browser = 'Chrome';
    elseif (str_contains($u, 'firefox/')) $browser = 'Firefox';
    elseif (str_contains($u, 'safari/') && !str_contains($u, 'chrome/')) $browser = 'Safari';

    return ['browser' => $browser, 'os' => $os, 'device_type' => $device];
}

function login_security_fingerprint(string $ua, string $ip): string
{
    // Privacy-conscious fingerprint: UA + coarse client hints.
    $al = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $tz = (string) ($_COOKIE['tz'] ?? ''); // optional if you ever set it
    return hash('sha256', $ua . '|' . $al . '|' . $tz);
}

function login_security_record_success(PDO $pdo, int $userId, string $role): void
{
    login_security_ensure_schema($pdo);

    $ip = login_security_ip();
    $ua = login_security_user_agent();
    $meta = login_security_parse_ua($ua);
    $fp = login_security_fingerprint($ua, $ip);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_login_events
                (user_id, role, ip_address, user_agent, browser, os, device_type, device_fingerprint, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $role, $ip, $ua, $meta['browser'], $meta['os'], $meta['device_type'], $fp]);
    } catch (Throwable $e) { /* non-fatal */ }

    $isNewDevice = false;
    try {
        $up = $pdo->prepare("
            INSERT INTO user_devices (user_id, role, device_fingerprint, first_seen_at, last_seen_at, last_ip, last_user_agent)
            VALUES (?, ?, ?, NOW(), NOW(), ?, ?)
            ON DUPLICATE KEY UPDATE last_seen_at = NOW(), last_ip = VALUES(last_ip), last_user_agent = VALUES(last_user_agent)
        ");
        $up->execute([$userId, $role, $fp, $ip, $ua]);
        $isNewDevice = ($up->rowCount() === 1); // insert vs update (MySQL: 1=insert, 2=update)
    } catch (Throwable $e) { /* non-fatal */ }

    if ($isNewDevice) {
        try {
            NotificationManager::create($pdo, $userId, [
                'receiver_role' => $role,
                'type'          => NotificationManager::TYPE_WARNING,
                'title'         => 'New Device Login',
                'message'       => 'Your account was accessed from a new or unrecognized device. If this was not you, secure your account immediately.',
                'priority'      => 'high',
                'icon'          => 'alert-triangle',
                'action_url'    => '/views/security/devices.php',
                'email'         => true,
            ]);
        } catch (Throwable $e) { /* non-fatal */ }
    } else {
        try {
            NotificationManager::create($pdo, $userId, [
                'receiver_role' => $role,
                'type'          => NotificationManager::TYPE_INFORMATION,
                'title'         => 'Login Successful',
                'message'       => 'You signed in from a recognized device.',
                'priority'      => 'normal',
                'icon'          => 'shield',
                'action_url'    => NotificationManager::dashboardPathForRole($role),
                'email'         => false,
            ]);
        } catch (Throwable $e) { /* non-fatal */ }
    }
}

