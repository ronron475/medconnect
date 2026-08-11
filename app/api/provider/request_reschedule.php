<?php
/**
 * API: Provider requests appointment reschedule (patient must confirm).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_reschedule.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

auth_csrf_require();

$providerId = (int) $_SESSION['user_id'];
$consultationId = (int) ($_POST['consultation_id'] ?? 0);
$newSlotId = (int) ($_POST['new_slot_id'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));

$result = appointment_reschedule_provider_request(
    $pdo,
    $providerId,
    $consultationId,
    $newSlotId,
    $reason,
    $providerId
);

echo json_encode([
    'success' => $result['ok'],
    'message' => $result['message'],
    'reschedule_id' => $result['reschedule_id'] ?? null,
]);
