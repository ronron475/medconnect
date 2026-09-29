<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_patient_access.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_followup.php';

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

$patient_id      = (int) ($_POST['patient_id'] ?? 0);
$consultation_id = (int) ($_POST['consultation_id'] ?? 0);
$followup_date   = trim((string) ($_POST['followup_date'] ?? ''));
$provider_id     = (int) $_SESSION['user_id'];

if (!$patient_id || !$followup_date) {
    echo json_encode(['success' => false, 'message' => 'Patient ID and date are required.']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $followup_date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid follow-up date.']);
    exit;
}

$access = provider_patient_assert_access($pdo, $provider_id, $patient_id, $consultation_id);
if (!$access['allowed']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $access['message']]);
    exit;
}

// Date-only booking cannot claim a real slot. A consultation that already has a
// follow-up returns that row. Anything else must use the follow-up decision form.
try {
    consultation_followup_ensure_schema($pdo);
    $existing = $consultation_id > 0
        ? consultation_followup_already_saved($pdo, $consultation_id)
        : null;
} catch (Throwable $e) {
    error_log('schedule_followup: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

if ($existing !== null) {
    echo json_encode([
        'success'         => true,
        'already_decided' => true,
        'message'         => $existing['message'],
        'followup_id'     => (int) $existing['followup_id'],
        'scheduled'       => !empty($existing['scheduled']),
    ]);
    exit;
}

http_response_code(409);
echo json_encode([
    'success' => false,
    'message' => 'Choose an available time in the follow-up form. A date alone does not book an appointment.',
]);
