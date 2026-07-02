<?php
/**
 * API: GIS Dashboard data (admin + healthcare provider only).
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/BagoBarangayCentroids.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/GisDashboardService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/TriageLevelService.php';

$role = (string) ($_SESSION['user_role'] ?? '');
if (!in_array($role, ['admin', 'provider', 'superadmin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$gis = new GisDashboardService($pdo);
$action = (string) ($_GET['action'] ?? 'bundle');

$filters = [
    'search'       => trim((string) ($_GET['search'] ?? '')),
    'province'     => trim((string) ($_GET['province'] ?? '')),
    'municipality' => trim((string) ($_GET['municipality'] ?? '')),
    'barangay'     => trim((string) ($_GET['barangay'] ?? '')),
    'status'       => trim((string) ($_GET['status'] ?? '')),
    'date_from'    => trim((string) ($_GET['date_from'] ?? '')),
    'date_to'      => trim((string) ($_GET['date_to'] ?? '')),
];

try {
    switch ($action) {
        case 'summary':
            $payload = ['summary' => $gis->getSummary()];
            break;

        case 'patients':
            $payload = ['patients' => $gis->getPatientRecords($filters)];
            break;

        case 'analytics':
            $payload = ['analytics' => $gis->getAnalytics()];
            break;

        case 'updates':
            $since = (string) ($_GET['since'] ?? date('c', time() - 60));
            $sync = $gis->getSyncChanges($since, $filters);
            $payload = [
                'summary'      => $sync['summary'],
                'patients'     => $sync['changed'],
                'changed'      => $sync['changed'],
                'triage_stats' => $sync['triage_stats'],
                'server_ts'    => $sync['server_ts'],
            ];
            break;

        case 'sync':
            $since = (string) ($_GET['since'] ?? date('c', time() - 60));
            $payload = $gis->getSyncChanges($since, $filters);
            break;

        case 'triage_stats':
            $payload = ['triage_stats' => $gis->getTriageStats()];
            break;

        case 'bundle':
        default:
            $payload = [
                'summary'      => $gis->getSummary(),
                'patients'     => $gis->getPatientRecords($filters),
                'analytics'    => $gis->getAnalytics(),
                'triage_stats' => $gis->getTriageStats(),
                'server_ts'    => date('c'),
            ];
            break;
    }

    echo json_encode(['success' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'GIS data unavailable.',
        'error'   => $e->getMessage(),
    ]);
}
