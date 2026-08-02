<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_health_summary.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_settings.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_patient_access.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$providerId = provider_settings_require_provider();
provider_settings_verify_csrf();

$patientId = (int) ($_POST['patient_id'] ?? 0);
$requestId = !empty($_POST['request_id']) ? (int) $_POST['request_id'] : null;
$providerNote = trim((string) ($_POST['provider_note'] ?? ''));
if (strlen($providerNote) > 500) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Note must be 500 characters or fewer.']);
    exit;
}

if ($patientId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Patient is required.']);
    exit;
}

$access = provider_patient_assert_access($pdo, $providerId, $patientId, 0);
if (!$access['allowed']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$ok = patient_health_summary_provider_reject($pdo, $patientId, $providerId, $requestId, $providerNote);
if (!$ok) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Could not reject this request. It may already be reviewed.']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Health Summary update request rejected. The patient has been notified.',
], JSON_UNESCAPED_UNICODE);
