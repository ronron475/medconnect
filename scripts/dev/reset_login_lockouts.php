<?php
/**
 * Dev utility: reset failed login attempts and IP throttles.
 * Usage: php scripts/dev/reset_login_lockouts.php [email]
 */
require_once dirname(__DIR__, 2) . '/config/db.php';

$email = $argv[1] ?? null;

if ($email) {
    $stmt = $pdo->prepare('UPDATE users SET failed_attempts = 0, lockout_until = NULL WHERE email = ?');
    $stmt->execute([$email]);
    echo "Reset lockout for {$email}: {$stmt->rowCount()} row(s)\n";
} else {
    $n = $pdo->exec('UPDATE users SET failed_attempts = 0, lockout_until = NULL WHERE failed_attempts > 0 OR lockout_until IS NOT NULL');
    echo "Reset all user lockouts: {$n} row(s)\n";
}

try {
    $ip = $pdo->exec("DELETE FROM security_throttle WHERE throttle_key LIKE 'login_ip:%'");
    echo "Cleared IP login throttle: {$ip} row(s)\n";
} catch (Throwable $e) {
    echo "IP throttle clear skipped: {$e->getMessage()}\n";
}
