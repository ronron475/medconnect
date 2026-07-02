<?php
/**
 * Password history / reuse prevention (shared across roles).
 */

declare(strict_types=1);

function password_history_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_history (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user_created (user_id, created_at),
            CONSTRAINT fk_ph_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function password_history_add(PDO $pdo, int $userId, string $hash): void
{
    password_history_ensure_schema($pdo);
    if ($hash === '') {
        return;
    }
    $pdo->prepare('INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)')
        ->execute([$userId, $hash]);
}

function password_history_is_reused(PDO $pdo, int $userId, string $password, int $limit = 5): bool
{
    password_history_ensure_schema($pdo);
    if ($password === '') {
        return false;
    }
    $limit = max(1, min(20, (int) $limit));

    $stmt = $pdo->prepare("
        SELECT password_hash
        FROM password_history
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$userId]);
    $hashes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($hashes as $h) {
        if ($h && password_verify($password, (string) $h)) {
            return true;
        }
    }
    return false;
}

