<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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
require_once BASE_PATH . '/app/includes/patient_portal_bootstrap.php';
require_once BASE_PATH . '/app/includes/patient_health_summary.php';
require_once BASE_PATH . '/app/includes/triage_assessment_schema.php';
require_once VIEWS_PATH . '/patient/partials/triage_helpers.php';

$latest_triage = null;
$triage_history = [];
try {
    triage_assessment_ensure_schema($pdo);
    if ($pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()) {
        $s = $pdo->prepare("
            SELECT id, chief_complaint, assessed_at, triage_classification, level, urgency_label,
                   triage_level, assessment_payload, outcome, recommendation_status
            FROM triage_results
            WHERE patient_id = ?
            ORDER BY assessed_at DESC, id DESC
            LIMIT 12
        ");
        $s->execute([(int) $uid]);
        $triage_history = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $latest_triage = $triage_history[0] ?? null;
    }
} catch (Throwable $e) {
    $triage_history = [];
    $latest_triage = null;
}

$page_title = 'Health Summary';
$health_css_ver = (int) @filemtime(ASSETS_PATH . '/css/patient-health-summary.css');
$health_js_ver = (int) @filemtime(ASSETS_PATH . '/js/patient-health-summary.js');
$dash_css_ver = (int) @filemtime(ASSETS_PATH . '/css/patient-dashboard.css');
$patient_page_stylesheets = [
    ASSET_BASE . '/assets/css/patient-dashboard.css?v=' . $dash_css_ver,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once VIEWS_PATH . '/patient/partials/layout_head.php'; ?>
</head>
<body class="patient-portal">

<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/patient-health-summary.css?v=<?= $health_css_ver ?>"/>

<div class="patient-page pdash-page patient-health-summary-page"
     id="patientHealthSummaryRoot"
     data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"
     data-api="<?= htmlspecialchars(ASSET_BASE) ?>/app/api/patient">

  <?php require VIEWS_PATH . '/patient/partials/view_health_summary.php'; ?>

</div>

<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>

<script>window.APP_BASE = <?= json_encode(ASSET_BASE) ?>;</script>
<script src="<?= ASSET_BASE ?>/assets/js/patient-health-summary.js?v=<?= $health_js_ver ?>"></script>
</body>
</html>
