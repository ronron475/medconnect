<?php
/**
 * Shared secret for calls to the Python AI service.
 * Value comes from MEDCONNECT_AI_SERVICE_TOKEN (server env / .env only).
 */
declare(strict_types=1);

function medconnect_ai_service_token(): string
{
    if (defined('MEDCONNECT_AI_SERVICE_TOKEN')) {
        $defined = trim((string) MEDCONNECT_AI_SERVICE_TOKEN);
        if ($defined !== '') {
            return $defined;
        }
    }

    $raw = getenv('MEDCONNECT_AI_SERVICE_TOKEN');
    if ($raw === false || $raw === '') {
        $raw = $_ENV['MEDCONNECT_AI_SERVICE_TOKEN'] ?? '';
    }

    return trim((string) $raw);
}

/**
 * Header lines for curl. Empty when the server has no token configured.
 *
 * @return list<string>
 */
function medconnect_ai_service_auth_headers(): array
{
    $token = medconnect_ai_service_token();
    if ($token === '') {
        return [];
    }

    return ['Authorization: Bearer ' . $token];
}
