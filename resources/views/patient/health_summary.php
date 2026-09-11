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
require_once BASE_PATH . '/app/includes/provider_clinical_support.php';
require_once BASE_PATH . '/app/includes/patient_consultation_records.php';
require_once VIEWS_PATH . '/patient/partials/triage_helpers.php';

$latest_triage = null;
$triage_history = [];
try {
    triage_assessment_ensure_schema($pdo);
    if ($pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()) {
        $s = $pdo->prepare("
            SELECT tr.id, tr.chief_complaint, tr.assessed_at, tr.triage_classification, tr.level, tr.urgency_label,
                   tr.triage_level, tr.assessment_payload, tr.outcome, tr.recommendation_status,
                   tr.recommendation_approved_by, tr.assigned_provider_id,
                   TRIM(CONCAT(COALESCE(rev.first_name, ''), ' ', COALESCE(rev.last_name, ''))) AS reviewer_name,
                   TRIM(CONCAT(COALESCE(asn.first_name, ''), ' ', COALESCE(asn.last_name, ''))) AS assigned_name
            FROM triage_results tr
            LEFT JOIN users rev ON rev.id = tr.recommendation_approved_by
            LEFT JOIN users asn ON asn.id = tr.assigned_provider_id
            WHERE tr.patient_id = ?
            ORDER BY tr.assessed_at DESC, tr.id DESC
            LIMIT 12
        ");
        $s->execute([(int) $uid]);
        $triage_history = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($triage_history as &$trRow) {
            $name = trim((string) ($trRow['reviewer_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($trRow['assigned_name'] ?? ''));
            }
            $providerId = (int) ($trRow['recommendation_approved_by'] ?? 0);
            if ($providerId <= 0) {
                $providerId = (int) ($trRow['assigned_provider_id'] ?? 0);
            }
            // Prefer the doctor who saved a consultation override for this triage case.
            $consultId = 0;
            try {
                $cLookup = $pdo->prepare("
                    SELECT c.id, c.provider_id,
                           COALESCE(NULLIF(TRIM(c.provider_name), ''),
                                    TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')))) AS provider_display
                    FROM consultations c
                    LEFT JOIN users u ON u.id = c.provider_id
                    WHERE c.patient_id = ? AND c.triage_result_id = ?
                    ORDER BY c.id DESC
                    LIMIT 1
                ");
                $cLookup->execute([(int) $uid, (int) ($trRow['id'] ?? 0)]);
                $cRow = $cLookup->fetch(PDO::FETCH_ASSOC) ?: [];
                $consultId = (int) ($cRow['id'] ?? 0);
                if ($consultId > 0) {
                    $trRow['finalized_by_name'] = provider_clinical_support_finalized_by_label(
                        $pdo,
                        $consultId,
                        (int) ($cRow['provider_id'] ?? $providerId),
                        (string) ($cRow['provider_display'] ?? $name)
                    );
                }
            } catch (Throwable $e) {
                $consultId = 0;
            }
            if (empty($trRow['finalized_by_name'])) {
                $trRow['finalized_by_name'] = $name !== ''
                    ? patient_provider_display_name($name)
                    : ($providerId > 0
                        ? provider_clinical_support_finalized_by_label($pdo, 0, $providerId, '')
                        : 'Doctor');
            }
        }
        unset($trRow);
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
