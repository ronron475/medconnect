<?php
/**
 * Small request helpers shared across view + API endpoints.
 */

function request_is_api(): bool
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    return str_contains($uri, '/app/api/');
}

function request_wants_json(): bool
{
    if (request_is_api()) {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) {
        return true;
    }
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    return stripos($accept, 'application/json') !== false;
}

