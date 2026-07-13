<?php
session_start();
header('Content-Type: application/json');
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';

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

require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
auth_csrf_require();

$id = (int)($_POST['followup_id'] ?? 0);
$date = trim($_POST['followup_date'] ?? '');
$status = trim($_POST['status'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$provider_id = (int)$_SESSION['user_id'];

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Follow-up ID required.']);
    exit;
}

$fields = [];
$params = [];
if ($date !== '') { $fields[] = 'followup_date = ?'; $params[] = $date; }
if ($status !== '') { $fields[] = 'status = ?'; $params[] = $status; }
if ($notes !== '') { $fields[] = 'notes = ?'; $params[] = $notes; }
if (empty($fields)) {
    echo json_encode(['success' => false, 'message' => 'Nothing to update.']);
    exit;
}

$params[] = $id;
$params[] = $provider_id;
$sql = 'UPDATE followups SET ' . implode(', ', $fields) . ' WHERE id = ? AND provider_id = ?';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'message' => 'Follow-up updated.']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
