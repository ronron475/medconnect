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

$page_title = 'Operational Reports';

$reportBasePath = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
$reportSections = admin_operational_reports_render($pdo, $_GET, $reportBasePath);
$opLiveJsVer = (int) @filemtime(ASSETS_PATH . '/js/admin-operational-reports-live.js');

if (!headers_sent()) {
    header('Cache-Control: no-store, max-age=0');
}

require_once __DIR__ . '/partials/layout_open.php';
?>
<div data-op-reports-live data-op-reports-api="<?= htmlspecialchars(ASSET_BASE . '/app/api/admin/operational_reports_live.php') ?>">
    <div style="display: flex; justify-content: flex-end; margin-bottom: 6px;">
        <span class="text-xs text-muted" data-op-live-sync aria-live="polite">Live</span>
    </div>
    <?php foreach ($reportSections as $sectionHtml): ?>
        <?= $sectionHtml ?>
    <?php endforeach; ?>
</div>
<script src="<?= ASSET_BASE ?>/assets/js/admin-operational-reports-live.js?v=<?= $opLiveJsVer ?>"></script>
<?php
require_once __DIR__ . '/partials/layout_close.php';
