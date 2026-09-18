<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/config/env_loader.php';
require_once BASE_PATH . '/config/app.php';

spl_autoload_register(static function (string $class): void {
    $file = BASE_PATH . '/app/core/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

echo 'url=' . AI_SERVICE_BASE_URL . PHP_EOL;
echo 'enrich_enabled=' . (ClinicalInterviewPythonEnrichment::enabled() ? 'yes' : 'no') . PHP_EOL;
echo 'healthy=' . (AiServiceClient::isHealthy(5) ? 'yes' : 'no') . PHP_EOL;

$e = ClinicalInterviewPythonEnrichment::enrich('Masakit akon ulo');
echo json_encode([
    'used' => $e['used'],
    'reason' => $e['reason'],
    'symptoms' => $e['english_symptoms'],
    'gloss' => mb_substr((string) $e['english_gloss'], 0, 100),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
