<?php
/**
 * Lightweight NLP bootstrap without MySQL (demo trial probes / CLI).
 */
define('BASE_PATH', dirname(__DIR__, 2));
define('APP_ROOT', BASE_PATH);
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Manila');
}
date_default_timezone_set(APP_TIMEZONE);

require_once BASE_PATH . '/config/env_loader.php';
require_once BASE_PATH . '/config/app.php';
require_once BASE_PATH . '/config/ai_interpreter.php';

spl_autoload_register(static function (string $class): void {
    $file = BASE_PATH . '/app/core/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

putenv('MEDCONNECT_PHP_NLP_ONLY=1');
$_ENV['MEDCONNECT_PHP_NLP_ONLY'] = '1';
putenv('MEDCONNECT_AI_INTERPRETER=0');
$_ENV['MEDCONNECT_AI_INTERPRETER'] = '0';
putenv('MEDCONNECT_SKIP_ML_LAYER=1');
$_ENV['MEDCONNECT_SKIP_ML_LAYER'] = '1';
