<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_patient_access.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_tables.php';

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

if (!$patient_id || !$medication || !$dosage || !$frequency || !$duration) {
    echo json_encode(['success' => false, 'message' => 'Required fields missing.']);
    exit;
}

// IDOR protection: provider must be assigned to consultation/patient (or have an existing relationship).
$access = provider_patient_assert_access($pdo, $provider_id, $patient_id, $consultation_id);
if (!$access['allowed']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $access['message']]);
    exit;
}
$consultation_id = (int) ($access['consultation_id'] ?? $consultation_id);

// Backward compatibility: if no consultation exists but a booked appointment relationship exists,
// create a consultation record tied to this provider/patient so the prescription remains linked.
if ($consultation_id <= 0) {
    $pdo->prepare("
        INSERT INTO consultations (patient_id, provider_id, consult_date, consult_time, status, provider_name)
        VALUES (?, ?, CURDATE(), CURTIME(), 'completed', ?)
    ")->execute([
        $patient_id,
        $provider_id,
        trim((string)(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''))),
    ]);
    $consultation_id = (int) $pdo->lastInsertId();
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO prescriptions (consultation_id, patient_id, provider_id, medication_name, dosage, frequency, duration, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$consultation_id, $patient_id, $provider_id, $medication, $dosage, $frequency, $duration, $notes ?: null]);
    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
    NotificationEvents::prescriptionAvailable($pdo, $patient_id, $provider_id, $provider_id);
    echo json_encode(['success' => true, 'message' => 'Prescription saved to patient record.']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
