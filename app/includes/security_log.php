<?php
/**
 * security_log.php — security event logger (no sensitive content).
 * Writes JSON lines to storage/logs/security/security_YYYY-MM-DD.log
 */

function security_log_event(string $event, array $meta = []): void
{
    $record = [
        'event' => $event,
        'user_id' => (int) ($_SESSION['user_id'] ?? 0),
        'role' => (string) ($_SESSION['user_role'] ?? ''),
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 220),
        'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        'ts' => date('Y-m-d H:i:s'),
        'meta' => $meta,
    ];

    // Never allow message text into logs.
    unset($record['meta']['message'], $record['meta']['body'], $record['meta']['content']);

    $dir = BASE_PATH . '/storage/logs/security';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/security_' . date('Y-m-d') . '.log';
    @file_put_contents($file, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

