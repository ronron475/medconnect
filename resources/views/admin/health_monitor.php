<?php
session_start();
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

if (!defined('MC_PORTAL_SHELL') || MC_PORTAL_SHELL !== 'superadmin') {
    require_once __DIR__ . '/_portal_access.php';
} else {
    require_once BASE_PATH . '/app/includes/auth_guard.php';
    auth_require_role(['admin', 'superadmin']);
}

require_once BASE_PATH . '/app/includes/system_health_monitor.php';

$is_superadmin_portal = (defined('MC_PORTAL_SHELL') && MC_PORTAL_SHELL === 'superadmin');
$page_title = $is_superadmin_portal ? 'System Health' : 'System Health Monitor';
$portal_eyebrow = $is_superadmin_portal ? 'Super Administration · Platform Monitoring' : 'Administration · Platform Monitoring';
$portal_heading = 'System Health & Performance';

$health = system_health_snapshot($pdo);
$overall = (string) ($health['overall_status'] ?? 'unknown');

require_once __DIR__ . '/partials/layout_open.php';
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-applications.css?v=1.1">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-system-health.css?v=1.0">

<article
  class="sys-health-page staff-apps-page"
  id="sysHealthRoot"
  data-api="<?= htmlspecialchars(ASSET_BASE . '/app/api/admin/system_health.php') ?>"
>

<header class="staff-apps-hero">
    <div class="staff-apps-hero__content">
        <span class="staff-apps-hero__eyebrow"><?= htmlspecialchars($portal_eyebrow) ?></span>
        <h1 class="staff-apps-hero__title"><?= htmlspecialchars($portal_heading) ?></h1>
        <p class="staff-apps-hero__desc">Live operational metrics from the database, application runtime, storage, and integrated services.</p>
    </div>
</header>

<div class="sys-health-overall sys-health-overall--<?= htmlspecialchars($overall) ?>" id="sysHealthOverall">
    <div class="sys-health-overall__left">
        <span class="sys-health-overall__dot" aria-hidden="true"></span>
        <div>
            <p class="sys-health-overall__title" id="sysHealthOverallTitle">
                <?php
                echo match ($overall) {
                    'healthy'  => 'All Systems Operational',
                    'warning'  => 'Some Systems Need Attention',
                    'critical' => 'Critical Issues Detected',
                    default    => 'System Status Unknown',
                };
                ?>
            </p>
            <p class="sys-health-overall__sub" id="sysHealthOverallSub">Last checked: <?= htmlspecialchars($health['generated_label'] ?? '—') ?></p>
        </div>
    </div>
    <div class="sys-health-toolbar">
        <span class="sys-health-toolbar__updated" id="sysHealthUpdated">Updated <?= htmlspecialchars($health['generated_label'] ?? '—') ?></span>
        <button type="button" class="mc-btn mc-btn--outline" id="sysHealthRefreshBtn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            Refresh Live Data
        </button>
    </div>
</div>

<section class="sys-health-section" aria-labelledby="sysHealthServicesTitle">
    <h2 class="sys-health-section__title" id="sysHealthServicesTitle">Core Services</h2>
    <div class="sys-health-services" id="sysHealthServices">
        <?php foreach ($health['services'] ?? [] as $svc):
            $status = (string) ($svc['status'] ?? 'unknown');
        ?>
        <article class="sys-health-service">
            <div class="sys-health-service__head">
                <h3 class="sys-health-service__label"><?= htmlspecialchars($svc['label'] ?? '') ?></h3>
                <span class="sys-health-pill sys-health-pill--<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(system_health_status_label($status)) ?></span>
            </div>
            <p class="sys-health-service__detail"><?= htmlspecialchars($svc['detail'] ?? '') ?></p>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="sys-health-section" aria-labelledby="sysHealthMetricsTitle">
    <h2 class="sys-health-section__title" id="sysHealthMetricsTitle">Live Operations</h2>
    <div class="sys-health-metrics" id="sysHealthMetrics">
        <?php foreach ($health['metrics'] ?? [] as $m):
            $toneClass = ($m['tone'] ?? '') !== 'neutral' && !empty($m['tone']) ? ' sys-health-metric__value--' . $m['tone'] : '';
        ?>
        <div class="sys-health-metric">
            <div class="sys-health-metric__value<?= $toneClass ?>">
                <?= htmlspecialchars((string) ($m['value'] ?? '0')) ?>
                <?php if (!empty($m['unit'])): ?><span class="sys-health-metric__unit"><?= htmlspecialchars($m['unit']) ?></span><?php endif; ?>
            </div>
            <div class="sys-health-metric__label"><?= htmlspecialchars($m['label'] ?? '') ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="sys-health-section" aria-labelledby="sysHealthDetailsTitle">
    <h2 class="sys-health-section__title" id="sysHealthDetailsTitle">Storage &amp; Backups</h2>
    <div class="sys-health-details">
        <div class="sys-health-detail-card">
            <h3 class="sys-health-detail-card__title">Storage Usage (<?= htmlspecialchars($health['storage']['path'] ?? 'storage/') ?>)</h3>
            <?php
            $pct = (float) ($health['storage']['used_pct'] ?? 0);
            $barClass = $pct >= 90 ? ' sys-health-progress__bar--critical' : ($pct >= 80 ? ' sys-health-progress__bar--warning' : '');
            ?>
            <div class="sys-health-progress" role="progressbar" aria-valuenow="<?= (int) $pct ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="sys-health-progress__bar<?= $barClass ?>" id="sysHealthStorageBar" style="width: <?= min(100, max(0, $pct)) ?>%;"></div>
            </div>
            <div class="sys-health-detail-meta" id="sysHealthStorageMeta">
                <span><?= htmlspecialchars((string) ($health['storage']['used_mb'] ?? 0)) ?> MB used</span>
                <span><?= htmlspecialchars((string) ($health['storage']['free_mb'] ?? 0)) ?> MB free</span>
            </div>
        </div>

        <div class="sys-health-detail-card">
            <h3 class="sys-health-detail-card__title">Database Performance</h3>
            <div class="sys-health-detail-meta" style="margin-bottom:12px;">
                <span>Latency</span>
                <span id="sysHealthDbLatency"><?= $health['database']['latency_ms'] !== null ? htmlspecialchars((string) $health['database']['latency_ms']) . ' ms' : '—' ?></span>
            </div>
            <div class="sys-health-detail-meta">
                <span>Database size</span>
                <span id="sysHealthDbSize"><?= htmlspecialchars((string) ($health['database']['size_mb'] ?? 0)) ?> MB</span>
            </div>
        </div>

        <div class="sys-health-detail-card" style="grid-column: 1 / -1;">
            <h3 class="sys-health-detail-card__title">Last Backup</h3>
            <p class="sys-health-backup-text" id="sysHealthBackupText"><?= htmlspecialchars($health['backup']['label'] ?? 'No backups logged') ?></p>
            <p class="sys-health-backup-sub" id="sysHealthBackupSub">
                <?php if (!empty($health['backup']['filename'])): ?>
                File: <?= htmlspecialchars($health['backup']['filename']) ?>
                <?php else: ?>
                Run a backup from Super Admin → Backup Management
                <?php endif; ?>
            </p>
        </div>
    </div>
</section>

<div class="staff-apps-note" role="note">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    <span>Metrics auto-refresh every 60 seconds. Use <strong>Refresh Live Data</strong> for an immediate update. AI service status is probed in real time.</span>
</div>

</article>

<script src="<?= ASSET_BASE ?>/assets/js/admin-system-health.js?v=1.0"></script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
