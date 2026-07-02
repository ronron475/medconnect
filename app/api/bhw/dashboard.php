<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';

$ctx = bhw_api_bootstrap($pdo);
Api::success([
    'metrics' => BhwWorkflows::getDashboardMetrics($pdo, $ctx),
    'queue' => BhwWorkflows::getTriageQueue($pdo, $ctx),
    'barangay' => $ctx['barangay_name'],
    'timestamp' => date('c'),
]);
