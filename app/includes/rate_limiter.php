<?php
/**
 * Very small rate limiter (session+IP keyed).
 * Uses APCu when available, otherwise file-based in storage/ratelimit.
 *
 * NOTE: Does not store message content; only counters/timestamps.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

function mc_rate_limiter_key(string $name, ?int $userId = null): string
{
    if (function_exists('login_security_ip')) {
        $ip = login_security_ip();
    } else {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
    // Keying strictly by IP (and authenticated user ID when present)
    // prevents attackers from bypassing limits by discarding or rotating session IDs.
    if ($userId !== null && $userId > 0) {
        return 'mc_rl:' . $name . ':u:' . $userId . ':ip:' . $ip;
    }
    return 'mc_rl:' . $name . ':ip:' . $ip;
}

/**
 * @return array{allowed:bool,retry_after:int}
 */
function mc_rate_limiter_allow(string $name, int $max, int $windowSeconds, ?int $userId = null): array
{
    $key = mc_rate_limiter_key($name, $userId);
    $now = time();
    $windowStart = $now - $windowSeconds;

    // APCu path (fast)
    if (function_exists('apcu_fetch') && function_exists('apcu_store')) {
        $data = apcu_fetch($key);
        if (!is_array($data)) $data = ['hits' => [], 'reset' => $now + $windowSeconds];
        $hits = array_values(array_filter($data['hits'] ?? [], fn($t) => is_int($t) && $t >= $windowStart));
        if (count($hits) >= $max) {
            $oldest = min($hits);
            return ['allowed' => false, 'retry_after' => max(1, ($oldest + $windowSeconds) - $now)];
        }
        $hits[] = $now;
        apcu_store($key, ['hits' => $hits], $windowSeconds + 2);
        return ['allowed' => true, 'retry_after' => 0];
    }

    // File-based path
    $dir = BASE_PATH . '/storage/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $key) . '.json';

    $hits = [];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $hits = array_values(array_filter($decoded['hits'] ?? [], fn($t) => is_int($t) && $t >= $windowStart));
        }
    }

    if (count($hits) >= $max) {
        $oldest = min($hits);
        return ['allowed' => false, 'retry_after' => max(1, ($oldest + $windowSeconds) - $now)];
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode(['hits' => $hits], JSON_UNESCAPED_SLASHES));
    return ['allowed' => true, 'retry_after' => 0];
}

