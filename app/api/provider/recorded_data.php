<?php
/**
 * Provider: load patient/BHW recorded data for an assigned consultation only.
 * Enforces consultations.provider_id ownership (IDOR protection).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_recorded_data.php';

Api::startJson();

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    Api::error('Unauthorized.', 403);
}

$providerId = (int) $_SESSION['user_id'];
$consultationId = (int) ($_GET['consultation_id'] ?? $_POST['consultation_id'] ?? 0);
$patientId = (int) ($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);

if ($consultationId <= 0) {
    Api::error('Consultation ID is required.', 400);
}

$result = consultation_recorded_data_for_authorized_provider(
    $pdo,
    $providerId,
    $consultationId,
    $patientId,
    8
);

if (!$result['allowed']) {
    Api::error($result['message'] ?? 'Access denied.', 403);
}

Api::success([
    'consultation_id' => $consultationId,
    'patient_id'      => (int) (($result['recorded']['patient_id'] ?? $patientId) ?: 0),
    'recorded'        => $result['recorded'] ?? ['available' => false, 'fields' => []],
    'history'         => $result['history'] ?? [],
]);
