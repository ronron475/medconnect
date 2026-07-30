<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';

$ctx = bhw_api_bootstrap($pdo);
$filters = BhwWorkflows::parseDashboardFilters($_GET);

Api::success([
    'metrics'    => BhwWorkflows::getDashboardMetrics($pdo, $ctx, $_GET),
    'charts'     => BhwWorkflows::getDashboardCharts($pdo, $ctx, $_GET),
    'queue'      => BhwWorkflows::getTriageQueue($pdo, $ctx, 15, $_GET),
    'filters'    => $filters,
    'barangay'   => $ctx['barangay_name'],
    'assigned_barangay_id' => (int) ($ctx['barangay_id'] ?? 0),
    'timestamp'  => date('c'),
]);
