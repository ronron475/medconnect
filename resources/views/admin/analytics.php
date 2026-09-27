<?php
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
require_once __DIR__ . '/_portal_access.php';
require_once BASE_PATH . '/app/includes/admin_operational_reports.php';

$page_title = 'Operational Reports & Analytics';

$reportPages = [];
foreach (array_keys(admin_operational_report_catalog()) as $reportType) {
    $reportPages[$reportType] = max(1, (int) ($_GET[$reportType . '_page'] ?? 1));
}
$reportBasePath = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
$reportExportBase = ASSET_BASE . '/app/api/admin/export_report.php';

require_once __DIR__ . '/partials/layout_open.php';

foreach ($reportPages as $reportType => $reportPageNumber) {
    $reportModule = admin_operational_report_page($pdo, $reportType, $reportPageNumber);
    $reportPages[$reportType] = (int) $reportModule['page'];
    require __DIR__ . '/partials/operational_report_module.php';
}

require_once __DIR__ . '/partials/layout_close.php';
