<?php
/**
 * Shared auth / rate-limit helpers for AI and NLP HTTP endpoints.
 * Does not change triage authority or demo interview gates.
 */
declare(strict_types=1);

require_once __DIR__ . '/rate_limiter.php';

/**
 * @return never
 */
function ai_endpoint_rate_limit_reject(int $retryAfter): void
{
    Api::error('Too many requests. Please wait a moment.', 429, [
        'code' => 'rate_limited',
        'retry_after' => max(1, $retryAfter),
    ]);
}

/**
 * Apply a per-IP (+ session) rate limit. Exits with 429 when exceeded.
 */
function ai_endpoint_rate_limit(string $bucket, int $max, int $windowSeconds): void
{
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $rl = mc_rate_limiter_allow($bucket, $max, $windowSeconds, $userId > 0 ? $userId : null);
    if (!$rl['allowed']) {
        ai_endpoint_rate_limit_reject((int) ($rl['retry_after'] ?? 1));
    }
}

function ai_endpoint_is_local_request(): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return in_array($ip, ['127.0.0.1', '::1'], true);
}

function ai_endpoint_is_staff(): bool
{
    $role = (string) ($_SESSION['user_role'] ?? '');
    return in_array($role, ['admin', 'superadmin'], true);
}

/** Debug / pipeline traces — local or staff only. */
function ai_endpoint_can_expose_debug(): bool
{
    return ai_endpoint_is_local_request() || ai_endpoint_is_staff();
}

/** Remote AI service process start — local or staff only (abuse protection). */
function ai_endpoint_can_start_ai_service(): bool
{
    return ai_endpoint_is_local_request() || ai_endpoint_is_staff();
}

/**
 * Require an authenticated user in one of the given roles.
 *
 * @param list<string> $roles
 */
function ai_endpoint_require_roles(array $roles): void
{
    Api::requireAuth();
    $role = (string) ($_SESSION['user_role'] ?? '');
    if ($roles !== [] && !in_array($role, $roles, true)) {
        Api::error('Forbidden.', 403, ['code' => 'forbidden']);
    }
}

/**
 * Public-safe AI service status fields (no paths, no exception text, no key material).
 *
 * @param array<string, mixed> $status
 * @return array<string, mixed>
 */
function ai_endpoint_public_connection_status(array $status): array
{
    return [
        'online' => (bool) ($status['online'] ?? false),
        'status' => (string) ($status['status'] ?? (($status['online'] ?? false) ? 'online' : 'offline')),
        'engine' => (string) ($status['engine'] ?? ''),
        'service' => (string) ($status['service'] ?? ''),
        'port_open' => (bool) ($status['port_open'] ?? false),
        'groq_configured' => (bool) ($status['groq_configured'] ?? false),
        'groq_connected' => (bool) ($status['groq_connected'] ?? false),
        'ai_service_enabled' => (bool) ($status['ai_service_enabled'] ?? (defined('AI_SERVICE_ENABLED') ? AI_SERVICE_ENABLED : false)),
    ];
}
