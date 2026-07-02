<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_patient_access.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (!auth_csrf_validate($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$patient_id      = (int)($_POST['patient_id']      ?? 0);
$consultation_id = (int)($_POST['consultation_id'] ?? 0);
$followup_date   = trim($_POST['followup_date']    ?? '');
$message         = trim($_POST['message']          ?? '');
$provider_id     = (int)$_SESSION['user_id'];

if (!$patient_id || !$followup_date) {
    echo json_encode(['success' => false, 'message' => 'Patient ID and date are required.']);
    exit;
}

// IDOR protection: provider must be assigned to consultation/patient.
$access = provider_patient_assert_access($pdo, $provider_id, $patient_id, $consultation_id);
if (!$access['allowed']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $access['message']]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO followups 
            (consultation_id, patient_id, provider_id, followup_date, message, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'scheduled', NOW())
    ");
    $stmt->execute([
        $consultation_id ?: null,
        $patient_id,
        $provider_id,
        $followup_date,
        $message ?: null
    ]);
    $followupId = (int) $pdo->lastInsertId();

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
    NotificationEvents::followUpScheduled($pdo, $patient_id, $followup_date, $provider_id, $provider_id);
    require_once BASE_PATH . '/app/includes/audit_log.php';
    audit_log($pdo, [
        'patient_id' => $patient_id,
        'action_type' => 'provider_followup_scheduled',
        'description' => 'Provider scheduled follow-up from consultation.',
        'meta' => ['followup_id' => $followupId, 'provider_id' => $provider_id, 'date' => $followup_date],
    ]);

    echo json_encode(['success' => true, 'message' => 'Follow-up appointment scheduled.', 'followup_id' => $followupId]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
