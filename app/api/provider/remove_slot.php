<?php
/**
 * API: Remove a single available appointment slot (provider).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_slots.php';
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
$slotId = (int) ($_POST['slot_id'] ?? 0);

try {
    $pdo->beginTransaction();
    $result = appointment_slot_remove_available($pdo, $providerId, $slotId);
    if (!$result['ok']) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $result['message']]);
        exit;
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $result['message']]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not remove slot.']);
}
