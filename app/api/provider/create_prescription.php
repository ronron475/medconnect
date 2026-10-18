<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_patient_access.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_tables.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/prescription_verification.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/prescription_lines.php';

clinical_tables_ensure($pdo);

if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'provider') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!auth_csrf_validate($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit;
}

$patient_id = (int)($_POST['patient_id'] ?? 0);
$consultation_id = (int)($_POST['consultation_id'] ?? 0);
$medication = trim($_POST['medication_name'] ?? '');
$dosage = trim($_POST['dosage'] ?? '');
$frequency = trim($_POST['frequency'] ?? '');
$duration = trim($_POST['duration'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$provider_id = (int)$_SESSION['user_id'];

if ($consultation_id <= 0 || $patient_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Select the consultation this prescription belongs to.']);
    exit;
}

// IDOR protection: the consultation must belong to this provider and patient.
$access = provider_patient_assert_access($pdo, $provider_id, $patient_id, $consultation_id);
if (!$access['allowed']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $access['message']]);
    exit;
}
$consultation_id = (int) ($access['consultation_id'] ?? 0);
$gate = prescription_consultation_can_issue($pdo, $provider_id, $patient_id, $consultation_id);
if (!$gate['ok']) {
    http_response_code($gate['message'] === 'Access denied.' ? 403 : 422);
    echo json_encode(['success' => false, 'message' => $gate['message']]);
    exit;
}

if (!$medication || !$dosage || !$frequency || !$duration) {
    echo json_encode(['success' => false, 'message' => 'Required fields missing.']);
    exit;
}
if (mb_strlen($medication) > 150 || mb_strlen($dosage) > 100 || mb_strlen($frequency) > 100 || mb_strlen($duration) > 100) {
    echo json_encode(['success' => false, 'message' => 'One of the prescription fields is too long.']);
    exit;
}
if (preg_match('/[\r\n\t]/', $medication . $dosage . $frequency . $duration)) {
    echo json_encode(['success' => false, 'message' => 'Prescription fields cannot contain line breaks.']);
    exit;
}
if (prescription_active_duplicate_exists($pdo, $consultation_id, $medication, $dosage, $frequency, $duration)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This active prescription was already issued for this consultation.']);
    exit;
}

try {
    $pdo->beginTransaction();

    prescription_issue(
        $pdo,
        $consultation_id,
        $patient_id,
        $provider_id,
        $medication,
        $dosage,
        $frequency,
        $duration,
        $notes !== '' ? $notes : null
    );
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('create_prescription: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
prescription_notify_quietly(static function () use ($pdo, $patient_id, $provider_id): void {
    NotificationEvents::prescriptionAvailable($pdo, $patient_id, $provider_id, $provider_id);
});
echo json_encode(['success' => true, 'message' => 'Prescription saved to patient record.']);
