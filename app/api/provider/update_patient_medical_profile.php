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

$requestId = !empty($_POST['request_id']) ? (int) $_POST['request_id'] : null;

$ok = patient_health_summary_provider_update($pdo, $patientId, $providerId, [
    'blood_type'           => $_POST['blood_type'] ?? '',
    'allergies'            => $_POST['allergies'] ?? '',
    'existing_conditions'  => $_POST['existing_conditions'] ?? '',
    'current_medications'  => $_POST['current_medications'] ?? '',
], $requestId);

if (!$ok) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Could not update medical profile. Check blood type and try again.']);
    exit;
}

try {
    require_once dirname(dirname(dirname(__DIR__))) . '/app/core/NotificationManager.php';
    NotificationManager::create($pdo, $patientId, [
        'type'       => 'clinical',
        'title'      => 'Medical Profile Updated',
        'message'    => 'Your permanent medical profile was verified and updated by your healthcare provider.',
        'priority'   => 'normal',
        'action_url' => '/views/patient/health_summary.php',
    ]);
} catch (Throwable $e) { /* non-fatal */ }

echo json_encode(['success' => true, 'message' => 'Patient medical profile updated and logged for audit.']);
