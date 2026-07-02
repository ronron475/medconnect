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

function login_security_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    foreach ($candidates as $raw) {
        $ip = trim(explode(',', (string) $raw)[0]);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return '';
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
                'type'       => NotificationManager::TYPE_SECURITY,
                'title'      => 'New Device Login',
                'message'    => 'Your account was accessed from a new device.',
                'priority'   => 'critical',
                'action_url' => '/views/security/devices.php',
                'email'      => true,
            ]);
        } catch (Throwable $e) { /* non-fatal */ }
    }
}

