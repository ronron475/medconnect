<?php
/**
 * Canonical Gemini API key + model resolution (server-side only).
 *
 * Priority for keys (matches .env.example + Railway / Python gemini_client.py):
 *   AI_API_KEY → GEMINI_API_KEY → GOOGLE_API_KEY
 *
 * Model: AI_MODEL when it starts with "gemini", else gemini-3.5-flash.
 * Never expose these values to browser JavaScript.
 */
declare(strict_types=1);

if (defined('MEDCONNECT_GEMINI_CONFIG_LOADED')) {
    return;
}
define('MEDCONNECT_GEMINI_CONFIG_LOADED', true);

/** Default production Gemini chat model (free-tier Standard). */
const MEDCONNECT_GEMINI_DEFAULT_MODEL = 'gemini-3.5-flash';

/**
 * Resolve the Gemini API key from server environment only.
 * Does not log or return partial keys.
 */
function medconnect_gemini_api_key(): string
{
    foreach (['AI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'] as $name) {
        $val = getenv($name);
        if ($val === false || $val === '') {
            $val = $_ENV[$name] ?? '';
        }
        $val = trim((string) $val);
        if ($val !== '') {
            return $val;
        }
    }

    return '';
}

function medconnect_gemini_key_configured(): bool
{
    return medconnect_gemini_api_key() !== '';
}

/**
 * Resolve the Gemini model name for production clients.
 * Ignores non-Gemini AI_MODEL values (e.g. Groq llama ids) so callers never
 * hit generativelanguage.googleapis.com with the wrong model id.
 */
function medconnect_gemini_model(): string
{
    $model = getenv('AI_MODEL');
    if ($model === false || $model === '') {
        $model = $_ENV['AI_MODEL'] ?? '';
    }
    $model = trim((string) $model);
    if ($model !== '' && str_starts_with(strtolower($model), 'gemini')) {
        return $model;
    }

    return MEDCONNECT_GEMINI_DEFAULT_MODEL;
}
