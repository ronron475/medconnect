<?php
/**
 * OCR.Space configuration
 * IMPORTANT: Never expose this file via a public URL.
 * Add /config/ to your .htaccess deny rules in production.
 */

define('OCR_SPACE_API_KEY',  'K81214247788957');
define('OCR_SPACE_ENDPOINT', 'https://api.ocr.space/parse/image');

// Upload constraints
define('OCR_MAX_FILE_SIZE',  5 * 1024 * 1024); // 5 MB
define('OCR_ALLOWED_TYPES',  ['image/jpeg', 'image/png', 'application/pdf']);
define('OCR_ALLOWED_EXTS',   ['jpg', 'jpeg', 'png', 'pdf']);

// Set to true only during local development to expose OCR debug output
define('OCR_DEBUG', true);

// FastAPI OCR — unified on main AI service (same URL as AI_SERVICE_BASE_URL when set)
$_ai_service_url = getenv('MEDCONNECT_OCR_SERVICE_URL')
    ?: (defined('AI_SERVICE_BASE_URL') ? AI_SERVICE_BASE_URL : '')
    ?: (getenv('MEDCONNECT_AI_SERVICE_URL') ?: '')
    ?: (function_exists('medconnect_is_production_host') && medconnect_is_production_host()
        ? (defined('MEDCONNECT_PRODUCTION_AI_SERVICE_URL') ? MEDCONNECT_PRODUCTION_AI_SERVICE_URL : 'https://medconnect-production-a654.up.railway.app')
        : 'http://127.0.0.1:8765');
define('OCR_FASTAPI_URL', rtrim((string) $_ai_service_url, '/'));
define('OCR_USE_FASTAPI', filter_var(getenv('MEDCONNECT_OCR_SERVICE_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN));
define('OCR_FASTAPI_TIMEOUT', (int) (getenv('MEDCONNECT_OCR_TIMEOUT') ?: 90));
