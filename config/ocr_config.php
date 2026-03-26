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
