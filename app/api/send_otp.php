<?php
/**
 * API: Send OTP handler
 * Moved from root/send_otp.php → delegates to controllers/auth/send_otp.php
 * URL: /app/api/send_otp.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once CONTROLLERS_PATH . '/auth/send_otp.php';
