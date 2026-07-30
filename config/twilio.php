<?php
/**
 * Twilio SMS configuration (loaded from .env).
 */
if (!defined('TWILIO_ACCOUNT_SID')) {
    define('TWILIO_ACCOUNT_SID', (string) (getenv('TWILIO_ACCOUNT_SID') ?: ''));
}
if (!defined('TWILIO_AUTH_TOKEN')) {
    define('TWILIO_AUTH_TOKEN', (string) (getenv('TWILIO_AUTH_TOKEN') ?: ''));
}
if (!defined('TWILIO_FROM_NUMBER')) {
    define('TWILIO_FROM_NUMBER', (string) (getenv('TWILIO_FROM_NUMBER') ?: ''));
}
if (!defined('TWILIO_CONFIGURED')) {
    define(
        'TWILIO_CONFIGURED',
        TWILIO_ACCOUNT_SID !== '' && TWILIO_AUTH_TOKEN !== '' && TWILIO_FROM_NUMBER !== ''
    );
}
