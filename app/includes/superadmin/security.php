<?php
declare(strict_types=1);

require_once __DIR__ . '/schema.php';
require_once dirname(__DIR__) . '/login_security.php';

function superadmin_security_log(
    PDO $pdo,
    string $action,
    string $module,
    string $status = 'info',
    ?string $description = null,
    ?int $userId = null,
    ?string $role = null,
    ?array $meta = null
): void {
    superadmin_ensure_schema($pdo);

    $ip = login_security_ip();
    $ua = login_security_user_agent();
    $parsed = login_security_parse_ua($ua);
    $uid = $userId ?? (int) ($_SESSION['user_id'] ?? 0) ?: null;
    $urole = $role ?? (string) ($_SESSION['user_role'] ?? '');

    try {
        $stmt = $pdo->prepare('
            INSERT INTO security_logs
                (user_id, role, action, module, status, description, ip_address, user_agent, browser, device, meta, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $uid,
            $urole !== '' ? $urole : null,
            $action,
            $module,
            $status,
            $description,
            $ip !== '' ? $ip : null,
            $ua !== '' ? $ua : null,
            $parsed['browser'],
            $parsed['device_type'],
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) { /* non-fatal */ }
}

function superadmin_record_failed_login(PDO $pdo, ?string $email, ?int $userId, string $reason = 'invalid_credentials'): void
{
    superadmin_ensure_schema($pdo);
    $ip = login_security_ip();
    $ua = login_security_user_agent();

    try {
        $stmt = $pdo->prepare('
            INSERT INTO failed_logins (email, user_id, ip_address, user_agent, reason, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $email,
            $userId,
            $ip !== '' ? $ip : null,
            $ua !== '' ? $ua : null,
            $reason,
        ]);
    } catch (Throwable $e) { /* non-fatal */ }

    superadmin_security_log($pdo, 'login_failed', 'auth', 'failure', $reason, $userId, null, [
        'email' => $email,
        'ip' => $ip,
    ]);
}

function superadmin_record_active_session(PDO $pdo, int $userId, string $role): void
{
    superadmin_ensure_schema($pdo);
    $ip = login_security_ip();
    $ua = login_security_user_agent();
    $parsed = login_security_parse_ua($ua);
    $sid = session_id();

    try {
        $pdo->prepare('
            INSERT INTO active_sessions (user_id, role, session_id, ip_address, user_agent, browser, device, last_activity, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE last_activity = NOW(), ip_address = VALUES(ip_address), user_agent = VALUES(user_agent)
        ')->execute([$userId, $role, $sid, $ip, $ua, $parsed['browser'], $parsed['device_type']]);
    } catch (Throwable $e) {
        // Table may not have unique on session_id — use upsert by user+session
        try {
            $pdo->prepare('DELETE FROM active_sessions WHERE user_id = ? AND session_id = ?')->execute([$userId, $sid]);
            $pdo->prepare('
                INSERT INTO active_sessions (user_id, role, session_id, ip_address, user_agent, browser, device, last_activity, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ')->execute([$userId, $role, $sid, $ip, $ua, $parsed['browser'], $parsed['device_type']]);
        } catch (Throwable $e2) { /* non-fatal */ }
    }
}

function superadmin_touch_session(PDO $pdo, int $userId): void
{
    superadmin_ensure_schema($pdo);
    $sid = session_id();
    if ($sid === '') {
        return;
    }
    try {
        $pdo->prepare('UPDATE active_sessions SET last_activity = NOW() WHERE user_id = ? AND session_id = ?')
            ->execute([$userId, $sid]);
    } catch (Throwable $e) { /* non-fatal */ }
}

function superadmin_clear_session(PDO $pdo, int $userId): void
{
    superadmin_ensure_schema($pdo);
    $sid = session_id();
    try {
        if ($sid !== '') {
            $pdo->prepare('DELETE FROM active_sessions WHERE user_id = ? AND session_id = ?')->execute([$userId, $sid]);
        }
    } catch (Throwable $e) { /* non-fatal */ }
}

function superadmin_terminate_session(PDO $pdo, int $sessionRowId, ?int $terminatedBy = null): bool
{
    superadmin_ensure_schema($pdo);

    $stmt = $pdo->prepare('SELECT id, user_id, session_id, role FROM active_sessions WHERE id = ? LIMIT 1');
    $stmt->execute([$sessionRowId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    $pdo->prepare('DELETE FROM active_sessions WHERE id = ?')->execute([$sessionRowId]);

    superadmin_security_log($pdo, 'session_terminated', 'security', 'warning', 'Active session terminated by administrator', $terminatedBy, 'superadmin', [
        'target_user_id' => (int) $row['user_id'],
        'target_role' => $row['role'] ?? null,
        'session_id' => $row['session_id'] ?? null,
    ]);

    return true;
}

function superadmin_is_ip_blocked(PDO $pdo, string $ip): bool
{
    if ($ip === '') {
        return false;
    }
    superadmin_ensure_schema($pdo);
    $stmt = $pdo->prepare('
        SELECT id FROM blocked_ips
        WHERE ip_address = ?
          AND (is_permanent = 1 OR blocked_until IS NULL OR blocked_until > NOW())
        LIMIT 1
    ');
    $stmt->execute([$ip]);
    return (bool) $stmt->fetchColumn();
}

function superadmin_block_ip(PDO $pdo, string $ip, string $reason, bool $permanent = false, ?int $hours = 24): void
{
    superadmin_ensure_schema($pdo);
    $blockedBy = (int) ($_SESSION['user_id'] ?? 0) ?: null;
    $until = $permanent ? null : date('Y-m-d H:i:s', time() + ($hours ?? 24) * 3600);

    $stmt = $pdo->prepare('
        INSERT INTO blocked_ips (ip_address, reason, blocked_by, blocked_until, is_permanent, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE reason = VALUES(reason), blocked_by = VALUES(blocked_by),
            blocked_until = VALUES(blocked_until), is_permanent = VALUES(is_permanent), updated_at = NOW()
    ');
    $stmt->execute([$ip, $reason, $blockedBy, $until, $permanent ? 1 : 0]);

    superadmin_security_log($pdo, 'ip_blocked', 'security', 'warning', $reason, null, null, [
        'ip' => $ip,
        'permanent' => $permanent,
    ]);
}

function superadmin_unblock_ip(PDO $pdo, string $ip): void
{
    superadmin_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM blocked_ips WHERE ip_address = ?')->execute([$ip]);
    superadmin_security_log($pdo, 'ip_unblocked', 'security', 'success', "Unblocked IP {$ip}");
}

function superadmin_get_security_summary(PDO $pdo): array
{
    superadmin_ensure_schema($pdo);

    $failed24h = 0;
    $blockedIps = 0;
    $activeSessions = 0;
    $securityEvents = 0;

    try {
        $failed24h = (int) $pdo->query("SELECT COUNT(*) FROM failed_logins WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $blockedIps = (int) $pdo->query("SELECT COUNT(*) FROM blocked_ips WHERE is_permanent = 1 OR blocked_until IS NULL OR blocked_until > NOW()")->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $activeSessions = (int) $pdo->query("SELECT COUNT(*) FROM active_sessions WHERE last_activity >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)")->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $securityEvents = (int) $pdo->query("SELECT COUNT(*) FROM security_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    } catch (Throwable $e) {}

    return compact('failed24h', 'blockedIps', 'activeSessions', 'securityEvents');
}
