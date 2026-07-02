<?php
/**
 * API: Verify OTP handler
 * Moved from root/verify_otp.php → delegates to controllers/auth/verify_otp.php
 * URL: /app/api/verify_otp.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once CONTROLLERS_PATH . '/auth/verify_otp.php';
