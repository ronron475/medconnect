<?php
/**
 * Diagnose why NLP uses PHP fallback instead of Python AI service.
 * Run: php scripts/dev/check_ai_service.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

$base = AI_SERVICE_BASE_URL;
$healthUrl = $base . '/health';
$analyzeUrl = $base . '/analyze-medical-profile';

echo "MedConnect AI service diagnostic\n";
echo "================================\n";
echo "Configured URL: {$base}\n";
echo "Analyze timeout: " . AI_SERVICE_TIMEOUT_ANALYZE . "s\n\n";

echo "1) Health check (GET {$healthUrl})\n";
$healthBody = @file_get_contents($healthUrl, false, stream_context_create([
    'http' => ['method' => 'GET', 'timeout' => 3, 'ignore_errors' => true],
]));
if ($healthBody === false) {
    echo "   FAIL — cannot connect (service likely not running).\n";
    echo "   Start: ai_service\\start_ai_service.bat\n";
    echo "   Or repair venv: ai_service\\install_ai_dependencies.bat\n\n";
} else {
    echo "   OK — response: " . substr($healthBody, 0, 200) . "\n\n";
}

echo "2) Medical profile analyze (POST {$analyzeUrl})\n";
$payload = json_encode([
    'allergies' => 'Penicillin',
    'current_medications' => 'Hypertension',
], JSON_UNESCAPED_UNICODE);
$analyzeBody = @file_get_contents($analyzeUrl, false, stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $payload,
        'timeout' => AI_SERVICE_TIMEOUT_ANALYZE,
        'ignore_errors' => true,
    ],
]));
if ($analyzeBody === false) {
    echo "   FAIL — no response within " . AI_SERVICE_TIMEOUT_ANALYZE . "s (timeout or connection refused).\n";
    echo "   PHP will use MedicalValidationWorkflow (php-validation-workflow).\n\n";
} else {
    $decoded = json_decode($analyzeBody, true);
    $ok = is_array($decoded) && !empty($decoded['success']);
    echo '   ' . ($ok ? 'OK' : 'FAIL') . " — success=" . ($ok ? 'true' : 'false') . "\n";
    if (!$ok && is_array($decoded)) {
        echo '   message: ' . ($decoded['message'] ?? 'n/a') . "\n";
    }
    echo "\n";
}

echo "3) AiServiceClient::analyzeMedicalProfile()\n";
$data = AiServiceClient::analyzeMedicalProfile('Penicillin', 'Hypertension');
echo '   Returns data: ' . ($data !== null ? 'yes (Python path)' : 'no (PHP fallback)') . "\n";
echo '   isHealthy(): ' . (AiServiceClient::isHealthy() ? 'true' : 'false') . "\n";
