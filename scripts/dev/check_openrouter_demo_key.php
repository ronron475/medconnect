<?php
/**
 * Reports whether the demo OpenRouter key is configured.
 * Prints present or missing only. Never prints the key.
 *
 * Usage: php scripts/dev/check_openrouter_demo_key.php
 */
require_once dirname(__DIR__, 2) . '/app/includes/openrouter_demo_fallback.php';

echo medconnect_demo_openrouter_key_is_configured()
    ? "openrouter_key=present\n"
    : "openrouter_key=missing\n";
