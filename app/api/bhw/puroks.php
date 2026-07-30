<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_reports.php';

$ctx = bhw_api_bootstrap($pdo);

Api::success([
    'puroks' => BhwReports::listPuroks($pdo, $ctx, []),
    'barangay_id' => (int) ($ctx['barangay_id'] ?? 0),
    'barangay' => $ctx['barangay_name'] ?? '',
]);
