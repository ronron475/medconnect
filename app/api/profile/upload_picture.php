<?php
/**
 * API: Upload or update profile picture (all authenticated roles).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/profile_picture.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (
    empty($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])
) {
    echo json_encode(['success' => false, 'message' => 'Invalid request token. Refresh the page and try again.']);
    exit;
}

$file = $_FILES['profile_picture'] ?? $_FILES['photo'] ?? null;
if (!is_array($file)) {
    echo json_encode(['success' => false, 'message' => 'No file received.']);
    exit;
}

$result = profile_picture_upload($pdo, (int) $_SESSION['user_id'], $file);
echo json_encode($result);
