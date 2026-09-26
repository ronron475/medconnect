<?php
/**
 * OCR.Space configuration
 * IMPORTANT: Never expose this file via a public URL.
 * Add /config/ to your .htaccess deny rules in production.
 *
 * Secrets: OCR_SPACE_API_KEY must come from the server environment / .env only.
 * Never hardcode API keys here. Never return the key in client responses.
 */

require_once __DIR__ . '/env_loader.php';

if (!defined('OCR_SPACE_API_KEY')) {
    define(
        'OCR_SPACE_API_KEY',
        trim((string) (getenv('OCR_SPACE_API_KEY') ?: ($_ENV['OCR_SPACE_API_KEY'] ?? '')))
    );
}

if (!defined('OCR_SPACE_ENDPOINT')) {
    define(
        'OCR_SPACE_ENDPOINT',
        trim((string) (getenv('OCR_SPACE_ENDPOINT') ?: 'https://api.ocr.space/parse/image'))
    );
}

// Upload constraints
if (!defined('OCR_MAX_FILE_SIZE')) {
    define('OCR_MAX_FILE_SIZE', 5 * 1024 * 1024); // 5 MB
}
if (!defined('OCR_ALLOWED_TYPES')) {
    define('OCR_ALLOWED_TYPES', ['image/jpeg', 'image/png', 'application/pdf']);
}
if (!defined('OCR_ALLOWED_EXTS')) {
    define('OCR_ALLOWED_EXTS', ['jpg', 'jpeg', 'png', 'pdf']);
}

// Default false. Set OCR_DEBUG=1 (or true) in local .env only — never on production.
if (!defined('OCR_DEBUG')) {
    $ocrDebugRaw = getenv('OCR_DEBUG');
    if ($ocrDebugRaw === false) {
        $ocrDebugRaw = $_ENV['OCR_DEBUG'] ?? '0';
    }
    define('OCR_DEBUG', filter_var((string) $ocrDebugRaw, FILTER_VALIDATE_BOOLEAN));
}

// FastAPI OCR — unified on main AI service (same URL as AI_SERVICE_BASE_URL when set)
$_ai_service_url = getenv('MEDCONNECT_OCR_SERVICE_URL')
    ?: (defined('AI_SERVICE_BASE_URL') ? AI_SERVICE_BASE_URL : '')
    ?: (getenv('MEDCONNECT_AI_SERVICE_URL') ?: '')
    ?: (function_exists('medconnect_is_production_host') && medconnect_is_production_host()
        ? (defined('MEDCONNECT_PRODUCTION_AI_SERVICE_URL') ? MEDCONNECT_PRODUCTION_AI_SERVICE_URL : 'https://medconnect-production-f2b7.up.railway.app')
        : 'http://127.0.0.1:8765');
if (!defined('OCR_FASTAPI_URL')) {
    define('OCR_FASTAPI_URL', rtrim((string) $_ai_service_url, '/'));
}
if (!defined('OCR_USE_FASTAPI')) {
    define('OCR_USE_FASTAPI', filter_var(getenv('MEDCONNECT_OCR_SERVICE_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN));
}
if (!defined('OCR_FASTAPI_TIMEOUT')) {
    define('OCR_FASTAPI_TIMEOUT', (int) (getenv('MEDCONNECT_OCR_TIMEOUT') ?: 90));
}
