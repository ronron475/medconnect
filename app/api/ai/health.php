<?php
/**
 * Combined health check: PHP launcher diagnostics + Python /health when available.
 * GET /app/api/ai/health.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();

$attemptStart = isset($_GET['start']) && $_GET['start'] === '1';
if ($attemptStart && !AiServiceClient::isHealthy()) {
    AiServiceLauncher::ensureRunning(true);
}

$diag = AiServiceLauncher::diagnostics();
$online = (bool) ($diag['online'] ?? false);

$payload = [
    'status'   => $online ? 'online' : 'offline',
    'service'  => $online ? 'python-medical-profile-nlp' : 'php-validation-workflow',
    'port'     => (int) ($diag['port'] ?? 8765),
    'groq'     => $online && ($diag['groq_configured'] ?? false) ? 'connected' : (($diag['groq_configured'] ?? false) ? 'configured' : 'missing'),
    'model'    => (string) ($diag['model'] ?? GROQ_MODEL),
    'engine'   => (string) ($diag['engine'] ?? 'php-validation-workflow'),
    'online'   => $online,
    'reason'   => (string) ($diag['reason'] ?? ''),
    'port_open'=> (bool) ($diag['port_open'] ?? false),
    'diagnostics' => $diag,
];

Api::success($payload, $online ? 'Python AI service online' : 'Python AI service offline');
