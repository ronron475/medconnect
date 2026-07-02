<?php
declare(strict_types=1);

/**
 * Simple DB-backed throttle / lockout for public endpoints (IP/email keys).
 * Complements per-user lockout fields without changing existing auth logic.
 */

function security_throttle_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS security_throttle (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            key_hash CHAR(64) NOT NULL,
            key_label VARCHAR(40) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            locked_until DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_key (key_hash),
            INDEX idx_locked (locked_until),
            INDEX idx_last (last_attempt_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $done = true;
}

function security_throttle_key(string $label, string $value): string
{
    $v = trim($value);
    return hash('sha256', strtolower($label) . '|' . $v);
}

/**
 * @return array{locked: bool, locked_until?: string|null, attempts?: int}
 */
function security_throttle_check(PDO $pdo, string $keyHash): array
{
    security_throttle_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT attempts, locked_until FROM security_throttle WHERE key_hash = ? LIMIT 1');
    $stmt->execute([$keyHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['locked' => false];
    $lu = (string) ($row['locked_until'] ?? '');
    if ($lu !== '' && (strtotime($lu) ?: 0) > time()) {
        return ['locked' => true, 'locked_until' => $lu, 'attempts' => (int) ($row['attempts'] ?? 0)];
    }
    return ['locked' => false, 'attempts' => (int) ($row['attempts'] ?? 0)];
}

/**
 * Increment attempts for a key within a window; lock when threshold reached.
 */
function security_throttle_fail(PDO $pdo, string $keyHash, string $keyLabel, int $windowSeconds, int $maxAttempts, int $lockMinutes): void
{
    security_throttle_ensure_schema($pdo);
    $now = time();
    $stmt = $pdo->prepare('SELECT attempts, window_started_at, locked_until FROM security_throttle WHERE key_hash = ? LIMIT 1');
    $stmt->execute([$keyHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $attempts = 0;
    $windowStarted = $now;
    if ($row) {
        $attempts = (int) ($row['attempts'] ?? 0);
        $ws = strtotime((string) ($row['window_started_at'] ?? '')) ?: $now;
        if (($now - $ws) <= $windowSeconds) {
            $windowStarted = $ws;
        } else {
            $attempts = 0;
            $windowStarted = $now;
        }
        $lu = strtotime((string) ($row['locked_until'] ?? '')) ?: 0;
        if ($lu > $now) {
            return; // already locked
        }
    }

    $attempts++;
    $lockUntil = null;
    if ($attempts >= $maxAttempts) {
        $lockUntil = date('Y-m-d H:i:s', $now + ($lockMinutes * 60));
    }

    $up = $pdo->prepare("
        INSERT INTO security_throttle (key_hash, key_label, attempts, window_started_at, last_attempt_at, locked_until)
        VALUES (?, ?, ?, FROM_UNIXTIME(?), NOW(), ?)
        ON DUPLICATE KEY UPDATE
            key_label = VALUES(key_label),
            attempts = VALUES(attempts),
            window_started_at = VALUES(window_started_at),
            last_attempt_at = NOW(),
            locked_until = VALUES(locked_until)
    ");
    $up->execute([$keyHash, $keyLabel, $attempts, $windowStarted, $lockUntil]);
}

function security_throttle_reset(PDO $pdo, string $keyHash): void
{
    security_throttle_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM security_throttle WHERE key_hash = ?')->execute([$keyHash]);
}

