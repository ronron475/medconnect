<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_triage_cases.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$providerId = (int) $_SESSION['user_id'];
$tab        = ($_GET['tab'] ?? 'active') === 'history' ? 'history' : 'active';

try {
    $cases = provider_triage_cases_load($pdo, $providerId);
    if ($tab === 'active') {
        $cases = array_values(array_filter($cases, 'provider_triage_case_is_active'));
    }

    echo json_encode([
        'success' => true,
        'tab'     => $tab,
        'cases'   => $cases,
        'stats'   => provider_triage_cases_stats($cases),
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load triage cases.']);
}
