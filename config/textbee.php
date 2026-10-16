<?php
/**
 * TextBee SMS configuration.
 * Live sending stays off unless TEXTBEE_SEND_ENABLED is explicitly true.
 * The API key belongs in the server environment, not in source control.
 */
if (!defined('TEXTBEE_API_KEY')) {
    define('TEXTBEE_API_KEY', trim((string) (getenv('TEXTBEE_API_KEY') ?: ($_ENV['TEXTBEE_API_KEY'] ?? ''))));
}
if (!defined('TEXTBEE_DEVICE_ID')) {
    define('TEXTBEE_DEVICE_ID', trim((string) (getenv('TEXTBEE_DEVICE_ID') ?: ($_ENV['TEXTBEE_DEVICE_ID'] ?? ''))));
}
if (!defined('TEXTBEE_API_BASE')) {
    $base = trim((string) (getenv('TEXTBEE_API_BASE') ?: ($_ENV['TEXTBEE_API_BASE'] ?? '')));
    define('TEXTBEE_API_BASE', $base !== '' ? rtrim($base, '/') : 'https://api.textbee.dev');
}
if (!defined('TEXTBEE_SEND_ENABLED')) {
    $flag = strtolower(trim((string) (getenv('TEXTBEE_SEND_ENABLED') ?: ($_ENV['TEXTBEE_SEND_ENABLED'] ?? ''))));
    define('TEXTBEE_SEND_ENABLED', in_array($flag, ['1', 'true', 'yes', 'on'], true));
}
