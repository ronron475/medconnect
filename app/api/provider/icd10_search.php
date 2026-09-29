<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/icd10_lookup.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
echo json_encode([
    'success' => true,
    'results' => icd10_search($q, 12),
]);
